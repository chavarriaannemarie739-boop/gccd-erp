<?php
/**
 * [AI-2026-08] 配件库存孤儿第二阶段：增强匹配 + 缺失档案补建后重挂
 *
 * 对仍孤儿的配件库存/入库：
 * 1) 增强名称/型号匹配（去空格、去「新」前缀、括号单位等）
 * 2) 仍无法唯一命中且有名称时：在配件档案中补建一条（规格固定「-」），再挂回
 * 3) 无任何名称来源的测试脏数据：标记为 unresolved，不瞎挂
 *
 * 用法：
 *   php gccd_server/scripts/rematch-accessory-stock-phase2.php
 *   php gccd_server/scripts/rematch-accessory-stock-phase2.php --execute
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$execute = in_array('--execute', $argv ?? [], true);
$dryRun = !$execute;

$mysql = [
    'hostname' => '42.192.67.226',
    'hostport' => '13306',
    'database' => 'gxgccd',
    'username' => 'gxgccd',
    'password' => 'gxgccd@123',
];

$mapTable = 'ya_accessory_stock_remap_phase2_20260806';
$fixedSpec = '-';
$propertyAccessory = '配件';

/**
 * @param mixed $s
 */
function normText($s): string
{
    $s = trim((string) $s);
    $s = str_replace(["\xc2\xa0", '　'], ' ', $s);
    $s = preg_replace('/\s+/u', '', $s) ?? $s;
    $s = str_replace(['ø', 'Ø', 'Φ', 'φ', '∅'], 'Φ', $s);
    $s = str_replace(['（', '）', '(', ')'], '', $s);

    return mb_strtolower($s);
}

/**
 * @param mixed $s
 */
function normModel($s): string
{
    $s = normText($s);
    $s = preg_replace('/^新/u', '', $s) ?? $s;
    // 规格占位
    if (in_array($s, ['无', '没有', '-', '—', '--', 'null', 'none'], true)) {
        return '';
    }

    return $s;
}

/**
 * @return list<string>
 */
function nameVariants(string $name): array
{
    $variants = [$name];
    $stripped = preg_replace('/[（(][^）)]*[）)]/u', '', $name) ?? $name;
    $stripped = trim($stripped);
    if ($stripped !== '' && $stripped !== $name) {
        $variants[] = $stripped;
    }
    $noUnit = preg_replace('/(个|只|根|条|米|套|副|块|片|kg|KG|L|l)$/u', '', $stripped) ?? $stripped;
    $noUnit = trim($noUnit);
    if ($noUnit !== '' && !in_array($noUnit, $variants, true)) {
        $variants[] = $noUnit;
    }

    return $variants;
}

/**
 * @param array<string, array<int, array<string, mixed>>> $byNameExact
 * @param array<string, array<int, array<string, mixed>>> $byNameNorm
 * @return array{0:?int,1:string}
 */
function resolveTarget(string $name, string $spec, array $byNameExact, array $byNameNorm): array
{
    $cands = [];
    foreach (nameVariants($name) as $variant) {
        if (isset($byNameExact[$variant])) {
            foreach ($byNameExact[$variant] as $c) {
                $cands[(int) $c['id']] = $c;
            }
        }
        $nk = normText($variant);
        if (isset($byNameNorm[$nk])) {
            foreach ($byNameNorm[$nk] as $c) {
                $cands[(int) $c['id']] = $c;
            }
        }
    }
    $cands = array_values($cands);
    if ($cands === []) {
        return [null, 'no_match'];
    }

    $specN = normModel($spec);
    if ($specN !== '') {
        $hit = [];
        foreach ($cands as $c) {
            $m = normModel((string) $c['material_model']);
            if ($m === $specN) {
                $hit[(int) $c['id']] = $c;
            }
        }
        if (count($hit) === 1) {
            return [(int) array_key_first($hit), 'name_model'];
        }

        $hit = [];
        foreach ($cands as $c) {
            $m = normModel((string) $c['material_model']);
            if ($m !== '' && (str_contains($m, $specN) || str_contains($specN, $m))) {
                $hit[(int) $c['id']] = $c;
            }
        }
        if (count($hit) === 1) {
            return [(int) array_key_first($hit), 'name_model_fuzzy'];
        }
        if (count($hit) > 1) {
            return [null, 'ambiguous_model'];
        }
    }

    if (count($cands) === 1) {
        return [(int) $cands[0]['id'], 'unique_name'];
    }

    if ($specN === '') {
        $empty = [];
        foreach ($cands as $c) {
            if (normModel((string) $c['material_model']) === '') {
                $empty[] = $c;
            }
        }
        if (count($empty) === 1) {
            return [(int) $empty[0]['id'], 'prefer_empty_model'];
        }
    }

    return [null, 'ambiguous'];
}

/**
 * @param PDO $pdo
 * @param array<string, array<int, array<string, mixed>>> $byNameExact
 * @param array<string, array<int, array<string, mixed>>> $byNameNorm
 * @return array{id:int, created:bool}
 */
function ensureMaterial(
    PDO $pdo,
    string $name,
    string $model,
    string $fixedSpec,
    string $property,
    array &$byNameExact,
    array &$byNameNorm,
    bool $dryRun
): array {
    [$existingId] = resolveTarget($name, $model, $byNameExact, $byNameNorm);
    if ($existingId !== null) {
        return ['id' => $existingId, 'created' => false];
    }

    // 精确查：同名同型号（规格固定 -）
    $stmt = $pdo->prepare(
        "SELECT id FROM ya_material
         WHERE is_delete = 0 AND material_property LIKE '%配件%'
           AND material_name = ? AND IFNULL(material_model,'') = ? AND IFNULL(material_spec,'') = ?
         LIMIT 1"
    );
    $stmt->execute([$name, $model, $fixedSpec]);
    $found = $stmt->fetchColumn();
    if ($found !== false) {
        return ['id' => (int) $found, 'created' => false];
    }

    if ($dryRun) {
        return ['id' => -1, 'created' => true];
    }

    $ins = $pdo->prepare(
        'INSERT INTO ya_material (
            material_name, material_spec, material_model, material_property,
            is_product, is_delete, create_time, create_uid, unit_price, material_num
        ) VALUES (?, ?, ?, ?, 0, 0, NOW(), 0, 0.00, 0)'
    );
    $ins->execute([$name, $fixedSpec, $model, $property]);
    $newId = (int) $pdo->lastInsertId();

    $row = [
        'id' => $newId,
        'material_name' => $name,
        'material_model' => $model,
    ];
    $byNameExact[$name][] = $row;
    $byNameNorm[normText($name)][] = $row;

    return ['id' => $newId, 'created' => true];
}

try {
    $pdo = new PDO(
        'mysql:host=' . $mysql['hostname'] . ';port=' . $mysql['hostport'] . ';dbname=' . $mysql['database'] . ';charset=utf8mb4',
        $mysql['username'],
        $mysql['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo $dryRun ? "=== DRY RUN ===\n" : "=== 正式执行 ===\n";

    $nameMap = [];
    foreach ($pdo->query(
        "SELECT material_id, material_name1, material_spec1
         FROM ya_wms_in
         WHERE is_delete = 0 AND warehouse LIKE '%配件%'"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['material_id'];
        $n = trim((string) $r['material_name1']);
        $s = trim((string) $r['material_spec1']);
        if ($n === '') {
            continue;
        }
        if (!isset($nameMap[$id])) {
            $nameMap[$id] = ['name' => $n, 'spec' => $s];
        } elseif ($nameMap[$id]['spec'] === '' && $s !== '') {
            $nameMap[$id]['spec'] = $s;
        }
    }

    if ($pdo->query("SHOW TABLES LIKE 'ya_material_copy1'")->fetchColumn()) {
        foreach ($pdo->query(
            'SELECT id, material_name, material_spec, material_model FROM ya_material_copy1'
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            if (!isset($nameMap[$id]) || $nameMap[$id]['name'] === '') {
                $model = trim((string) $r['material_model']);
                $spec = trim((string) $r['material_spec']);
                $nameMap[$id] = [
                    'name' => trim((string) $r['material_name']),
                    'spec' => $model !== '' ? $model : $spec,
                ];
            }
        }
    }

    $loadIndexes = static function (PDO $pdo): array {
        $newRows = $pdo->query(
            "SELECT id, material_name, material_model
             FROM ya_material
             WHERE is_delete = 0 AND material_property LIKE '%配件%'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $byNameExact = [];
        $byNameNorm = [];
        foreach ($newRows as $r) {
            $byNameExact[$r['material_name']][] = $r;
            $byNameNorm[normText($r['material_name'])][] = $r;
        }

        return [$byNameExact, $byNameNorm];
    };

    [$byNameExact, $byNameNorm] = $loadIndexes($pdo);

    $orphanOldIds = [];
    foreach ($pdo->query(
        "SELECT DISTINCT wm.material_id
         FROM ya_wms_material wm
         LEFT JOIN ya_material m ON m.id = wm.material_id AND m.is_delete = 0
         WHERE wm.is_delete = 0 AND wm.stock LIKE '%配件%' AND m.id IS NULL"
    ) as $r) {
        $orphanOldIds[(int) $r['material_id']] = true;
    }
    foreach ($pdo->query(
        "SELECT DISTINCT i.material_id
         FROM ya_wms_in i
         LEFT JOIN ya_material m ON m.id = i.material_id AND m.is_delete = 0
         WHERE i.is_delete = 0 AND i.warehouse LIKE '%配件%' AND m.id IS NULL"
    ) as $r) {
        $orphanOldIds[(int) $r['material_id']] = true;
    }

    $plan = [];
    $stats = [
        'reuse_match' => 0,
        'create_then_link' => 0,
        'skip_no_name' => 0,
    ];

    foreach (array_keys($orphanOldIds) as $oldId) {
        $info = $nameMap[$oldId] ?? null;
        if ($info === null || trim($info['name']) === '') {
            $stats['skip_no_name']++;
            $plan[$oldId] = ['action' => 'skip', 'reason' => 'no_name'];
            continue;
        }

        $name = trim($info['name']);
        $rawSpec = trim((string) $info['spec']);
        $model = normModel($rawSpec) === '' ? '' : $rawSpec;
        // 占位「无」等写成空型号
        if (normModel($rawSpec) === '' && $rawSpec !== '') {
            $model = '';
        }

        [$hitId, $why] = resolveTarget($name, $model, $byNameExact, $byNameNorm);
        if ($hitId !== null) {
            $stats['reuse_match']++;
            $plan[$oldId] = [
                'action' => 'link',
                'new_id' => $hitId,
                'name' => $name,
                'model' => $model,
                'strategy' => $why,
                'created' => false,
            ];
            continue;
        }

        // 同名多型号且无规格：用补建型号消歧，避免瞎挂
        if ($why === 'ambiguous' || $why === 'ambiguous_model') {
            if ($model === '') {
                $model = '补建-' . $oldId;
            } else {
                // 型号对不上任一候选：补建该型号
            }
        }

        // 规划阶段不写库，仅判断是否需要补建
        $ensured = ensureMaterial(
            $pdo,
            $name,
            $model,
            $fixedSpec,
            $propertyAccessory,
            $byNameExact,
            $byNameNorm,
            true
        );
        $stats['create_then_link']++;
        $plan[$oldId] = [
            'action' => 'link',
            'new_id' => $ensured['id'],
            'name' => $name,
            'model' => $model,
            'strategy' => $ensured['created'] ? 'create_archive' : 'create_found_existing',
            'created' => $ensured['created'],
        ];
    }

    $linkCount = 0;
    $createCount = 0;
    foreach ($plan as $p) {
        if (($p['action'] ?? '') === 'link') {
            $linkCount++;
            if (!empty($p['created'])) {
                $createCount++;
            }
        }
    }

    echo '剩余孤儿旧 ID: ' . count($orphanOldIds) . "\n";
    echo "可重挂: {$linkCount}（其中预计新建档案 {$createCount}）\n";
    echo "跳过无名称: {$stats['skip_no_name']}\n";
    echo "增强匹配复用已有档案: {$stats['reuse_match']}\n";
    echo "补建后挂接: {$stats['create_then_link']}\n";

    if ($dryRun) {
        echo "dry-run 结束。确认后执行：php gccd_server/scripts/rematch-accessory-stock-phase2.php --execute\n";
        exit(0);
    }

    $pdo->exec('DROP TABLE IF EXISTS `' . $mapTable . '`');
    $pdo->exec(
        'CREATE TABLE `' . $mapTable . '` (
            old_material_id INT NOT NULL PRIMARY KEY,
            new_material_id INT NOT NULL,
            material_name VARCHAR(255) NOT NULL DEFAULT \'\',
            material_model VARCHAR(255) NOT NULL DEFAULT \'\',
            strategy VARCHAR(64) NOT NULL DEFAULT \'\',
            created_archive TINYINT NOT NULL DEFAULT 0,
            create_time DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $insMap = $pdo->prepare(
        'INSERT INTO `' . $mapTable . '`
            (old_material_id, new_material_id, material_name, material_model, strategy, created_archive, create_time)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );

    $updStock = $pdo->prepare(
        "UPDATE ya_wms_material wm
         LEFT JOIN ya_material m ON m.id = wm.material_id AND m.is_delete = 0
         SET wm.material_id = ?
         WHERE wm.material_id = ? AND wm.is_delete = 0 AND wm.stock LIKE '%配件%' AND m.id IS NULL"
    );
    $updIn = $pdo->prepare(
        "UPDATE ya_wms_in i
         LEFT JOIN ya_material m ON m.id = i.material_id AND m.is_delete = 0
         SET i.material_id = ?,
             i.material_name1 = CASE WHEN TRIM(IFNULL(i.material_name1,'')) = '' THEN ? ELSE i.material_name1 END,
             i.material_spec1 = CASE WHEN TRIM(IFNULL(i.material_spec1,'')) = '' THEN ? ELSE i.material_spec1 END
         WHERE i.material_id = ? AND i.is_delete = 0 AND i.warehouse LIKE '%配件%' AND m.id IS NULL"
    );
    $updOut = $pdo->prepare(
        "UPDATE ya_wms_out o
         LEFT JOIN ya_material m ON m.id = o.material_id AND m.is_delete = 0
         SET o.material_id = ?
         WHERE o.material_id = ? AND o.is_delete = 0 AND m.id IS NULL"
    );

    $pdo->beginTransaction();

    // 先真正补建档案（dry-run 时 ensure 未写入）
    [$byNameExact, $byNameNorm] = $loadIndexes($pdo);
    $createdReal = 0;
    $stockUpdated = 0;
    $inUpdated = 0;
    $outUpdated = 0;

    foreach ($plan as $oldId => $p) {
        if (($p['action'] ?? '') !== 'link') {
            continue;
        }

        $name = $p['name'];
        $model = $p['model'];
        if (!empty($p['created']) || (int) $p['new_id'] <= 0) {
            $ensured = ensureMaterial(
                $pdo,
                $name,
                $model,
                $fixedSpec,
                $propertyAccessory,
                $byNameExact,
                $byNameNorm,
                false
            );
            $newId = $ensured['id'];
            $created = $ensured['created'];
            if ($created) {
                $createdReal++;
            }
            $strategy = $created ? 'create_archive' : ($p['strategy'] ?? 'link');
        } else {
            $newId = (int) $p['new_id'];
            $created = false;
            $strategy = $p['strategy'];
        }

        $insMap->execute([$oldId, $newId, $name, $model, $strategy, $created ? 1 : 0]);

        $updStock->execute([$newId, $oldId]);
        $stockUpdated += $updStock->rowCount();
        $updIn->execute([$newId, $name, $model, $oldId]);
        $inUpdated += $updIn->rowCount();
        $updOut->execute([$newId, $oldId]);
        $outUpdated += $updOut->rowCount();
    }

    $stillOrphan = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM ya_wms_material wm
         LEFT JOIN ya_material m ON m.id = wm.material_id AND m.is_delete = 0 AND m.material_property LIKE '%配件%'
         WHERE wm.is_delete = 0 AND wm.stock LIKE '%配件%' AND m.id IS NULL"
    )->fetchColumn();
    $linkedOk = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM ya_wms_material wm
         INNER JOIN ya_material m ON m.id = wm.material_id AND m.is_delete = 0 AND m.material_property LIKE '%配件%'
         WHERE wm.is_delete = 0 AND wm.stock LIKE '%配件%'"
    )->fetchColumn();
    $archiveCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM ya_material WHERE is_delete = 0 AND material_property LIKE '%配件%'"
    )->fetchColumn();

    $pdo->commit();

    echo "新建配件档案: {$createdReal}\n";
    echo "更新库存行: {$stockUpdated}\n";
    echo "更新入库行: {$inUpdated}\n";
    echo "更新出库行: {$outUpdated}\n";
    echo "现网配件库存已挂档: {$linkedOk}，仍孤儿: {$stillOrphan}\n";
    echo "现网配件档案总数: {$archiveCount}\n";
    echo "映射备份表: {$mapTable}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '执行失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
