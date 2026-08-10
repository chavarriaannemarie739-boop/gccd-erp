<?php
/**
 * [AI-2026-08] 配件仓库孤儿库存/出入库：按入库冗余名称挂回新配件档案
 *
 * 背景：2026-07 清空重导配件档案后，旧 material_id（约 511–3355）全部失效；
 * 7 月备份表 ID 段与孤儿 ID 段不一致，无法按旧 ID 直挂。
 * 本脚本用 ya_wms_in.material_name1 + material_spec1（spec 对应当前档案 material_model）匹配新档案。
 *
 * 用法：
 *   php gccd_server/scripts/rematch-accessory-stock.php            # dry-run
 *   php gccd_server/scripts/rematch-accessory-stock.php --dry-run
 *   php gccd_server/scripts/rematch-accessory-stock.php --execute
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

$mapTable = 'ya_accessory_stock_remap_20260806';
$reportDir = dirname(__DIR__, 2) . '/tmp';

/**
 * @param mixed $s
 */
function normAccessoryText($s): string
{
    $s = trim((string) $s);
    $s = str_replace(["\xc2\xa0", '　'], ' ', $s);
    $s = preg_replace('/\s+/u', '', $s) ?? $s;
    $s = str_replace(['ø', 'Ø', 'Φ', 'φ', '∅'], 'Φ', $s);

    return mb_strtolower($s);
}

/**
 * @param array<int, array<string, mixed>> $byNameExact
 * @param array<string, array<int, array<string, mixed>>> $byNameNorm
 * @return array{0:?int,1:string}
 */
function resolveAccessoryTarget(string $name, string $spec, array $byNameExact, array $byNameNorm): array
{
    $cands = $byNameExact[$name] ?? [];
    if ($cands === []) {
        $cands = $byNameNorm[normAccessoryText($name)] ?? [];
        $uniq = [];
        foreach ($cands as $c) {
            $uniq[(int) $c['id']] = $c;
        }
        $cands = array_values($uniq);
    }
    if ($cands === []) {
        return [null, 'no_match'];
    }

    $specN = normAccessoryText($spec);
    if ($spec !== '') {
        $hit = [];
        foreach ($cands as $c) {
            if (normAccessoryText((string) $c['material_model']) === $specN) {
                $hit[] = $c;
            }
        }
        if (count($hit) === 1) {
            return [(int) $hit[0]['id'], 'name_model'];
        }
        if ($hit === []) {
            foreach ($cands as $c) {
                $m = normAccessoryText((string) $c['material_model']);
                if ($m !== '' && (str_contains($m, $specN) || str_contains($specN, $m))) {
                    $hit[] = $c;
                }
            }
            $uniq = [];
            foreach ($hit as $h) {
                $uniq[(int) $h['id']] = $h;
            }
            $hit = array_values($uniq);
            if (count($hit) === 1) {
                return [(int) $hit[0]['id'], 'name_model_fuzzy'];
            }
        } elseif (count($hit) > 1) {
            return [null, 'ambiguous_model'];
        }
    }

    if (count($cands) === 1) {
        return [(int) $cands[0]['id'], 'unique_name'];
    }

    if ($spec === '') {
        $empty = [];
        foreach ($cands as $c) {
            if (trim((string) $c['material_model']) === '') {
                $empty[] = $c;
            }
        }
        if (count($empty) === 1) {
            return [(int) $empty[0]['id'], 'prefer_empty_model'];
        }
    }

    return [null, 'ambiguous'];
}

try {
    $pdo = new PDO(
        'mysql:host=' . $mysql['hostname'] . ';port=' . $mysql['hostport'] . ';dbname=' . $mysql['database'] . ';charset=utf8mb4',
        $mysql['username'],
        $mysql['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo $dryRun ? "=== DRY RUN（不加 --execute 不写库）===\n" : "=== 正式执行 ===\n";

    // 旧 ID → 名称/规格（入库冗余字段优先，copy1 补洞）
    $nameMap = [];
    $inRows = $pdo->query(
        "SELECT material_id, material_name1, material_spec1
         FROM ya_wms_in
         WHERE is_delete = 0 AND warehouse LIKE '%配件%' AND TRIM(IFNULL(material_name1,'')) <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inRows as $r) {
        $id = (int) $r['material_id'];
        $n = trim((string) $r['material_name1']);
        $s = trim((string) $r['material_spec1']);
        if (!isset($nameMap[$id])) {
            $nameMap[$id] = ['name' => $n, 'spec' => $s];
        } elseif ($nameMap[$id]['spec'] === '' && $s !== '') {
            $nameMap[$id]['spec'] = $s;
        }
    }

    $copyExists = (bool) $pdo->query("SHOW TABLES LIKE 'ya_material_copy1'")->fetchColumn();
    if ($copyExists) {
        foreach ($pdo->query('SELECT id, material_name, material_spec, material_model FROM ya_material_copy1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            if (!isset($nameMap[$id])) {
                $specOrModel = trim((string) ($r['material_model'] ?: $r['material_spec']));
                $nameMap[$id] = [
                    'name' => trim((string) $r['material_name']),
                    'spec' => $specOrModel,
                ];
            }
        }
    }

    $newRows = $pdo->query(
        "SELECT id, material_name, material_spec, material_model
         FROM ya_material
         WHERE is_delete = 0 AND material_property LIKE '%配件%'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byNameExact = [];
    $byNameNorm = [];
    foreach ($newRows as $r) {
        $byNameExact[$r['material_name']][] = $r;
        $byNameNorm[normAccessoryText($r['material_name'])][] = $r;
    }

    $fullMap = [];
    $strategyStats = [];
    foreach ($nameMap as $oldId => $info) {
        [$newId, $why] = resolveAccessoryTarget($info['name'], $info['spec'], $byNameExact, $byNameNorm);
        $strategyStats[$why] = ($strategyStats[$why] ?? 0) + 1;
        if ($newId !== null) {
            $fullMap[$oldId] = [
                'new_id' => $newId,
                'name' => $info['name'],
                'spec' => $info['spec'],
                'strategy' => $why,
            ];
        }
    }

    echo '名称来源旧 ID: ' . count($nameMap) . "\n";
    echo '可映射旧 ID: ' . count($fullMap) . "\n";
    echo "匹配策略统计（按旧 ID）:\n";
    foreach ($strategyStats as $k => $v) {
        echo "  {$k}: {$v}\n";
    }

    $countMapped = static function (PDO $pdo, string $sql, array $fullMap): array {
        $total = 0;
        $mapped = 0;
        foreach ($pdo->query($sql) as $r) {
            $total++;
            if (isset($fullMap[(int) $r['material_id']])) {
                $mapped++;
            }
        }

        return [$total, $mapped];
    };

    [$stockTotal, $stockMapped] = $countMapped(
        $pdo,
        "SELECT wm.material_id
         FROM ya_wms_material wm
         LEFT JOIN ya_material m ON m.id = wm.material_id
         WHERE wm.is_delete = 0 AND wm.stock LIKE '%配件%' AND m.id IS NULL",
        $fullMap
    );
    [$inTotal, $inMapped] = $countMapped(
        $pdo,
        "SELECT i.material_id
         FROM ya_wms_in i
         LEFT JOIN ya_material m ON m.id = i.material_id
         WHERE i.is_delete = 0 AND i.warehouse LIKE '%配件%' AND m.id IS NULL",
        $fullMap
    );
    [$outTotal, $outMapped] = $countMapped(
        $pdo,
        "SELECT o.material_id
         FROM ya_wms_out o
         LEFT JOIN ya_material m ON m.id = o.material_id
         WHERE o.is_delete = 0 AND m.id IS NULL",
        $fullMap
    );

    echo "库存孤儿可挂: {$stockMapped} / {$stockTotal}\n";
    echo "入库孤儿可挂: {$inMapped} / {$inTotal}\n";
    echo "出库孤儿可挂: {$outMapped} / {$outTotal}\n";

    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0755, true);
    }

    $unresolvedPath = $reportDir . '/accessory-stock-unresolved.csv';
    $fp = fopen($unresolvedPath, 'w');
    if ($fp === false) {
        throw new RuntimeException('无法写入未匹配清单');
    }
    fwrite($fp, "old_material_id,name,spec,reason,stock_rows,in_rows\n");

    $stockByOld = [];
    foreach ($pdo->query(
        "SELECT wm.material_id, COUNT(*) c
         FROM ya_wms_material wm
         LEFT JOIN ya_material m ON m.id = wm.material_id
         WHERE wm.is_delete = 0 AND wm.stock LIKE '%配件%' AND m.id IS NULL
         GROUP BY wm.material_id"
    ) as $r) {
        $stockByOld[(int) $r['material_id']] = (int) $r['c'];
    }
    $inByOld = [];
    foreach ($pdo->query(
        "SELECT i.material_id, COUNT(*) c
         FROM ya_wms_in i
         LEFT JOIN ya_material m ON m.id = i.material_id
         WHERE i.is_delete = 0 AND i.warehouse LIKE '%配件%' AND m.id IS NULL
         GROUP BY i.material_id"
    ) as $r) {
        $inByOld[(int) $r['material_id']] = (int) $r['c'];
    }

    $unresolvedIds = [];
    foreach ($stockByOld as $oldId => $_) {
        if (!isset($fullMap[$oldId])) {
            $unresolvedIds[$oldId] = true;
        }
    }
    foreach ($inByOld as $oldId => $_) {
        if (!isset($fullMap[$oldId])) {
            $unresolvedIds[$oldId] = true;
        }
    }
    foreach (array_keys($unresolvedIds) as $oldId) {
        $info = $nameMap[$oldId] ?? ['name' => '', 'spec' => ''];
        $reason = 'no_name';
        if (isset($nameMap[$oldId])) {
            [, $reason] = resolveAccessoryTarget($info['name'], $info['spec'], $byNameExact, $byNameNorm);
        }
        fputcsv($fp, [
            $oldId,
            $info['name'],
            $info['spec'],
            $reason,
            $stockByOld[$oldId] ?? 0,
            $inByOld[$oldId] ?? 0,
        ]);
    }
    fclose($fp);
    echo "未匹配清单: {$unresolvedPath} （" . count($unresolvedIds) . " 个旧 ID）\n";

    if ($dryRun) {
        echo "dry-run 结束。确认后执行：php gccd_server/scripts/rematch-accessory-stock.php --execute\n";
        exit(0);
    }

    if ($fullMap === []) {
        throw new RuntimeException('没有可映射记录，中止');
    }

    $pdo->exec('DROP TABLE IF EXISTS `' . $mapTable . '`');
    $pdo->exec(
        'CREATE TABLE `' . $mapTable . '` (
            old_material_id INT NOT NULL PRIMARY KEY,
            new_material_id INT NOT NULL,
            material_name VARCHAR(255) NOT NULL DEFAULT \'\',
            material_spec VARCHAR(255) NOT NULL DEFAULT \'\',
            strategy VARCHAR(64) NOT NULL DEFAULT \'\',
            create_time DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $insMap = $pdo->prepare(
        'INSERT INTO `' . $mapTable . '` (old_material_id, new_material_id, material_name, material_spec, strategy, create_time)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );

    $pdo->beginTransaction();

    foreach ($fullMap as $oldId => $info) {
        $insMap->execute([$oldId, $info['new_id'], $info['name'], $info['spec'], $info['strategy']]);
    }
    echo "映射表 {$mapTable}: " . count($fullMap) . " 条\n";

    $updStock = $pdo->prepare(
        "UPDATE ya_wms_material wm
         LEFT JOIN ya_material m ON m.id = wm.material_id
         SET wm.material_id = ?
         WHERE wm.material_id = ? AND wm.is_delete = 0 AND wm.stock LIKE '%配件%' AND m.id IS NULL"
    );
    $updIn = $pdo->prepare(
        "UPDATE ya_wms_in i
         LEFT JOIN ya_material m ON m.id = i.material_id
         SET i.material_id = ?
         WHERE i.material_id = ? AND i.is_delete = 0 AND i.warehouse LIKE '%配件%' AND m.id IS NULL"
    );
    $updOut = $pdo->prepare(
        "UPDATE ya_wms_out o
         LEFT JOIN ya_material m ON m.id = o.material_id
         SET o.material_id = ?
         WHERE o.material_id = ? AND o.is_delete = 0 AND m.id IS NULL"
    );

    $stockUpdated = 0;
    $inUpdated = 0;
    $outUpdated = 0;
    foreach ($fullMap as $oldId => $info) {
        $updStock->execute([$info['new_id'], $oldId]);
        $stockUpdated += $updStock->rowCount();
        $updIn->execute([$info['new_id'], $oldId]);
        $inUpdated += $updIn->rowCount();
        $updOut->execute([$info['new_id'], $oldId]);
        $outUpdated += $updOut->rowCount();
    }

    // 验收：配件库存仍全孤儿则回滚
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

    if ($linkedOk < $stockMapped) {
        throw new RuntimeException("验收失败：挂上后 linked={$linkedOk}，预期至少 {$stockMapped}");
    }

    $pdo->commit();

    echo "已更新库存行: {$stockUpdated}\n";
    echo "已更新入库行: {$inUpdated}\n";
    echo "已更新出库行: {$outUpdated}\n";
    echo "现网配件库存已挂档: {$linkedOk}，仍孤儿: {$stillOrphan}\n";
    echo "映射备份表: {$mapTable}\n";
    echo "未匹配请人工处理: {$unresolvedPath}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '执行失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
