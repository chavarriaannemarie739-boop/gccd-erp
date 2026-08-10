<?php

/**
 * [AI-2026-08] 圆钢档案四字段数据修正（可重复执行）。
 *
 * 用途：
 * 1) 名称「溯源」：空厂商且材质非「轴承钢」时，材质 → 厂商，材质改为「轴承钢」
 * 2) 材质误填钢厂名（巨能/天钢等）：挪到厂商并清空材质
 * 3) 名称异常「圆钢」且材质为钢厂名：名称改为「溯源」，材质 → 厂商，清空材质
 *
 * 用法：
 *   php gccd_server/scripts/fix-round-steel-four-fields.php           # 预览
 *   php gccd_server/scripts/fix-round-steel-four-fields.php --apply   # 执行
 *
 * 上线顺序：四字段代码与 SQL 就绪后，在目标库执行本脚本 --apply。
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new think\App($root);
$app->initialize();

use think\facade\Db;

const ROUND_STEEL_MILL_NAMES = [
    '巨能', '宣钢', '邯钢', '潍坊', '澳森', '富伦', '敬业', '天钢',
];

$apply = in_array('--apply', $argv, true);

/**
 * @param array<string, mixed> $row
 * @return array{manufacturer:string,material_spec:string,material_name:string}|null
 */
function planRoundSteelFourFieldFix(array $row): ?array
{
    $name = trim((string) ($row['material_name'] ?? ''));
    $manufacturer = trim((string) ($row['manufacturer'] ?? ''));
    $spec = trim((string) ($row['material_spec'] ?? ''));

    if ($name === '溯源' && $manufacturer === '' && $spec !== '' && $spec !== '轴承钢') {
        return [
            'material_name' => '溯源',
            'manufacturer' => $spec,
            'material_spec' => '轴承钢',
        ];
    }

    if ($name === '圆钢' && $manufacturer === '' && in_array($spec, ROUND_STEEL_MILL_NAMES, true)) {
        return [
            'material_name' => '溯源',
            'manufacturer' => $spec,
            'material_spec' => '',
        ];
    }

    if ($manufacturer === '' && in_array($spec, ROUND_STEEL_MILL_NAMES, true)) {
        return [
            'material_name' => $name,
            'manufacturer' => $spec,
            'material_spec' => '',
        ];
    }

    return null;
}

$rows = Db::name('material')
    ->where('is_delete', 0)
    ->where('material_property', '圆钢')
    ->field('id,material_name,manufacturer,material_spec,material_model')
    ->order('id')
    ->select()
    ->toArray();

$planned = [];
foreach ($rows as $row) {
    $after = planRoundSteelFourFieldFix($row);
    if ($after === null) {
        continue;
    }
    $planned[] = [
        'id' => (int) $row['id'],
        'before' => [
            'material_name' => $row['material_name'],
            'manufacturer' => $row['manufacturer'],
            'material_spec' => $row['material_spec'],
            'material_model' => $row['material_model'],
        ],
        'after' => $after + ['material_model' => $row['material_model']],
    ];
}

echo 'mode=' . ($apply ? 'apply' : 'preview') . PHP_EOL;
echo 'planned=' . count($planned) . PHP_EOL;
echo json_encode($planned, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (!$apply || $planned === []) {
    exit(0);
}

$backupDir = $root . '/runtime/round_steel_four_fields_fix';
if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "backup dir create failed\n");
    exit(1);
}
$backupFile = $backupDir . '/fix_' . date('Ymd_His') . '.json';
file_put_contents(
    $backupFile,
    json_encode(['created_at' => date('c'), 'rows' => $planned], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

Db::startTrans();
try {
    $now = date('Y-m-d H:i:s');
    foreach ($planned as $item) {
        $affected = Db::name('material')
            ->where('id', $item['id'])
            ->where('is_delete', 0)
            ->where('material_property', '圆钢')
            ->update([
                'material_name' => $item['after']['material_name'],
                'manufacturer' => $item['after']['manufacturer'],
                'material_spec' => $item['after']['material_spec'],
                'update_time' => $now,
            ]);
        if ((int) $affected !== 1) {
            throw new RuntimeException('update failed id=' . $item['id']);
        }
    }
    Db::commit();
} catch (Throwable $e) {
    Db::rollback();
    fwrite(STDERR, 'ROLLBACK: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'updated=' . count($planned) . PHP_EOL;
echo 'backup=' . $backupFile . PHP_EOL;
