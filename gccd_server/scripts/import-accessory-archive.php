<?php
/**
 * [AI-2026-07] 一次性：备份并清空配件档案，从易合进货明细 Excel 导入名称+型号
 *
 * 用法（项目根目录）：
 *   php gccd_server/scripts/import-accessory-archive.php
 *   php gccd_server/scripts/import-accessory-archive.php --dry-run
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$excelPath = dirname(__DIR__, 2) . '/配件库存表-易合.xlsx';
$backupTable = 'ya_material_accessory_backup_20260707';
$propertyAccessory = '配件';
$fixedSpec = '-';

$mysql = [
    'hostname' => '42.192.67.226',
    'hostport' => '13306',
    'database' => 'gxgccd',
    'username' => 'gxgccd',
    'password' => 'gxgccd@123',
];

/**
 * 大文件 Excel 用 Python openpyxl 流式解析，避免 PhpSpreadsheet 内存溢出
 *
 * @return array<int, array{code:string,name:string,model:string,spec:string}>
 */
function loadAccessoryRowsFromExcel(string $excelPath, string $fixedSpec): array
{
    if (!is_file($excelPath)) {
        throw new RuntimeException('Excel 文件不存在: ' . $excelPath);
    }

    $py = <<<'PY'
import json, sys
import openpyxl
path, fixed_spec = sys.argv[1], sys.argv[2]
wb = openpyxl.load_workbook(path, read_only=True, data_only=True)
ws = wb['第一页']
headers = {}
for row in ws.iter_rows(min_row=7, max_row=7, values_only=True):
    for idx, v in enumerate(row):
        if v:
            headers[idx] = str(v).strip()
code_i = next(i for i, h in headers.items() if h == '存货编码')
name_i = next(i for i, h in headers.items() if h == '存货')
model_i = next(i for i, h in headers.items() if h == '规格型号')
by_code = {}
for row in ws.iter_rows(min_row=8, values_only=True):
    if all(c in (None, '') for c in row):
        continue
    code = str(row[code_i] or '').strip()
    name = str(row[name_i] or '').strip()
    if not code:
        continue
    if code not in by_code:
        by_code[code] = {
            'code': code,
            'name': name,
            'model': str(row[model_i] or '').strip(),
            'spec': fixed_spec,
        }
wb.close()
print(json.dumps(list(by_code.values()), ensure_ascii=False))
PY;

    $cmd = 'python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($excelPath) . ' ' . escapeshellarg($fixedSpec);
    $json = shell_exec($cmd);
    if (!is_string($json) || trim($json) === '') {
        throw new RuntimeException('Excel 解析失败');
    }

    $rows = json_decode(trim($json), true);
    if (!is_array($rows)) {
        throw new RuntimeException('Excel 解析结果无效');
    }

    return $rows;
}

/**
 * 名称+规格+型号冲突时，用存货编码后缀消歧（仍写入 material_model）
 *
 * @param array<int, array{code:string,name:string,model:string,spec:string}> $rows
 * @return array<int, array{code:string,name:string,model:string,spec:string}>
 */
function disambiguateRows(array $rows): array
{
    $seen = [];
    $out = [];

    foreach ($rows as $row) {
        $key = $row['name'] . "\0" . $row['spec'] . "\0" . $row['model'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $row;
            continue;
        }
        $row['model'] = $row['model'] === '' ? $row['code'] : ($row['model'] . '/' . $row['code']);
        $out[] = $row;
    }

    return $out;
}

/**
 * @param PDO $pdo
 */
function assertOtherPropertiesUntouched(PDO $pdo, int $beforeRound, int $beforeProduct): void
{
    $round = (int) $pdo->query("SELECT COUNT(*) FROM ya_material WHERE material_property LIKE '%圆钢%' AND is_delete = 0")->fetchColumn();
    $product = (int) $pdo->query("SELECT COUNT(*) FROM ya_material WHERE material_property LIKE '%成品%' AND is_delete = 0")->fetchColumn();

    if ($round !== $beforeRound || $product !== $beforeProduct) {
        throw new RuntimeException('圆钢/成品档案数量发生变化，已中止校验');
    }
}

try {
    $pdo = new PDO(
        'mysql:host=' . $mysql['hostname'] . ';port=' . $mysql['hostport'] . ';dbname=' . $mysql['database'] . ';charset=utf8mb4',
        $mysql['username'],
        $mysql['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo $dryRun ? "=== DRY RUN ===\n" : "=== 正式执行 ===\n";

    $excelRows = loadAccessoryRowsFromExcel($excelPath, $fixedSpec);
    $excelRows = disambiguateRows($excelRows);
    echo 'Excel 去重后待导入: ' . count($excelRows) . " 条\n";

    $beforeAccessory = (int) $pdo->query("SELECT COUNT(*) FROM ya_material WHERE material_property LIKE '%配件%'")->fetchColumn();
    $beforeRound = (int) $pdo->query("SELECT COUNT(*) FROM ya_material WHERE material_property LIKE '%圆钢%' AND is_delete = 0")->fetchColumn();
    $beforeProduct = (int) $pdo->query("SELECT COUNT(*) FROM ya_material WHERE material_property LIKE '%成品%' AND is_delete = 0")->fetchColumn();

    echo "执行前配件档案: {$beforeAccessory} 条\n";
    echo "执行前圆钢档案: {$beforeRound} 条\n";
    echo "执行前成品档案: {$beforeProduct} 条\n";

    if ($dryRun) {
        echo "dry-run 结束，未写入数据库。\n";
        exit(0);
    }

    $pdo->exec('DROP TABLE IF EXISTS `' . $backupTable . '`');
    $pdo->exec(
        'CREATE TABLE `' . $backupTable . '` AS SELECT * FROM ya_material WHERE material_property LIKE ' . $pdo->quote('%配件%')
    );
    $backupCount = (int) $pdo->query('SELECT COUNT(*) FROM `' . $backupTable . '`')->fetchColumn();
    echo "已备份配件档案到 {$backupTable}: {$backupCount} 条\n";

    $pdo->beginTransaction();

    $deleted = $pdo->exec("DELETE FROM ya_material WHERE material_property LIKE '%配件%'");
    echo "已删除配件档案: {$deleted} 条\n";

    $insertSql = 'INSERT INTO ya_material (
        material_name, material_spec, material_model, material_property,
        is_product, is_delete, create_time, create_uid, unit_price, material_num
    ) VALUES (?, ?, ?, ?, 0, 0, NOW(), 0, 0.00, 0)';

    $stmt = $pdo->prepare($insertSql);
    $inserted = 0;
    $skipped = 0;

    foreach ($excelRows as $row) {
        if ($row['name'] === '') {
            $skipped++;
            continue;
        }

        if (strlen($row['name']) > 255) {
            $row['name'] = mb_substr($row['name'], 0, 255);
        }
        if (strlen($row['spec']) > 50) {
            $row['spec'] = mb_substr($row['spec'], 0, 50);
        }
        if (strlen($row['model']) > 50) {
            $row['model'] = mb_substr($row['model'], 0, 50);
        }

        try {
            $stmt->execute([$row['name'], $row['spec'], $row['model'], $propertyAccessory]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++;
            echo '跳过: ' . $row['code'] . ' | ' . $row['name'] . ' | ' . $e->getMessage() . "\n";
        }
    }

    assertOtherPropertiesUntouched($pdo, $beforeRound, $beforeProduct);

    $afterAccessory = (int) $pdo->query("SELECT COUNT(*) FROM ya_material WHERE material_property LIKE '%配件%' AND is_delete = 0")->fetchColumn();

    $pdo->commit();

    echo "导入完成: {$inserted} 条，跳过 {$skipped} 条\n";
    echo "现网配件档案: {$afterAccessory} 条\n";
    echo "圆钢档案仍为: {$beforeRound} 条，成品档案仍为: {$beforeProduct} 条\n";
    echo "备份表: {$backupTable}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '执行失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
