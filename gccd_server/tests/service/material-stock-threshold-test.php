<?php

/**
 * [AI-2026-08] 物料库存阈值按仓库范围维护测试。
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/app/common/service/admin/MaterialStockThresholdService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL 缺少 MaterialStockThresholdService.php\n");
    exit(1);
}

require $root . '/app/common/service/admin/WarehouseScopeService.php';
require $servicePath;
require $root . '/app/common/service/admin/MaterialService.php';
require $root . '/app/common/service/admin/StockAlertService.php';

use app\common\service\admin\MaterialService;
use app\common\service\admin\MaterialStockThresholdService;
use app\common\service\admin\StockAlertService;

$passed = 0;
$failed = [];

/**
 * @param string $name
 * @param callable $callback
 * @return void
 */
function runStockThresholdCase(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;
    } catch (Throwable $exception) {
        $failed[] = $name . ': ' . $exception->getMessage();
    }
}

/**
 * @param bool $condition
 * @param string $message
 * @return void
 */
function expectStockThreshold(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

runStockThresholdCase('warehouse_tabs_normalize_to_isolated_scopes', static function (): void {
    expectStockThreshold(
        MaterialStockThresholdService::normalizeScope('配件库') === '配件',
        '配件库未规范为配件 scope'
    );
    expectStockThreshold(
        MaterialStockThresholdService::normalizeScope('圆钢仓库') === '圆钢',
        '圆钢仓库未规范为圆钢 scope'
    );
    expectStockThreshold(
        MaterialStockThresholdService::normalizeScope('原料库') === '圆钢',
        '原料库未兼容为圆钢 scope'
    );
    expectStockThreshold(
        MaterialStockThresholdService::normalizeScope('成品库') === '',
        '成品库不应允许维护最低库存'
    );
});

runStockThresholdCase('threshold_map_isolated_by_scope', static function (): void {
    $rows = [
        ['material_id' => 10, 'warehouse_scope' => '配件', 'min_stock_qty' => '5.000'],
        ['material_id' => 10, 'warehouse_scope' => '圆钢', 'min_stock_qty' => '12.000'],
        ['material_id' => 11, 'warehouse_scope' => '配件', 'min_stock_qty' => '3.500'],
    ];

    expectStockThreshold(
        MaterialStockThresholdService::thresholdMapForScopeRows($rows, '配件') === [
            10 => 5.0,
            11 => 3.5,
        ],
        '配件 scope 阈值映射错误'
    );
    expectStockThreshold(
        MaterialStockThresholdService::thresholdMapForScopeRows($rows, '圆钢') === [10 => 12.0],
        '相同物料的圆钢阈值未与配件隔离'
    );
});

runStockThresholdCase('material_ids_are_deduplicated_and_invalid_values_removed', static function (): void {
    expectStockThreshold(
        MaterialStockThresholdService::normalizeMaterialIds([7, '7', 0, -1, 8, '', null, 8])
            === [7, 8],
        '批量物料 ID 未去重或未过滤无效值'
    );
});

runStockThresholdCase('zero_or_empty_quantity_means_delete', static function (): void {
    foreach ([null, '', 0, '0', -1] as $raw) {
        expectStockThreshold(
            MaterialStockThresholdService::normalizeMinStockQty($raw) === null,
            '0、空或负数应规范为删除阈值'
        );
    }
    expectStockThreshold(
        MaterialStockThresholdService::normalizeMinStockQty('1.2346') === 1.235,
        '正数阈值应保留三位小数'
    );
});

runStockThresholdCase('runtime_contract_uses_new_table_only', static function () use ($root): void {
    $stockAlert = (string) file_get_contents(
        $root . '/app/common/service/admin/StockAlertService.php'
    );
    $materialService = (string) file_get_contents(
        $root . '/app/common/service/admin/MaterialService.php'
    );
    $materialController = (string) file_get_contents(
        $root . '/app/admin/controller/production/Material.php'
    );

    expectStockThreshold(
        str_contains($stockAlert, 'MaterialStockThresholdService'),
        '库存预警尚未改为读取新阈值服务'
    );
    expectStockThreshold(
        !str_contains($stockAlert, "column('min_stock_qty', 'id')")
            && !str_contains($stockAlert, "whereNotNull('min_stock_qty')"),
        '库存预警运行时仍读取 ya_material.min_stock_qty'
    );
    expectStockThreshold(
        !array_key_exists('min_stock_qty', MaterialService::$edit_field),
        '物料档案 edit_field 仍接受 min_stock_qty'
    );
    expectStockThreshold(
        !str_contains($materialService, 'batchUpdateMinStockQty')
            && !str_contains($materialController, 'batchUpdateMinStockQty'),
        '物料档案批量阈值入口尚未移除'
    );
});

runStockThresholdCase('migration_repairs_empty_stock_by_material_property', static function () use ($root): void {
    $sql = (string) file_get_contents($root . '/database/material_stock_threshold.sql');

    expectStockThreshold(
        str_contains($sql, 'UPDATE `ya_wms_material`')
            && str_contains($sql, 'INNER JOIN `ya_material`')
            && str_contains($sql, "`m`.`material_property` = '配件'")
            && str_contains($sql, "`m`.`material_property` = '圆钢'")
            && str_contains($sql, "'配件仓库'")
            && str_contains($sql, "'圆钢仓库'"),
        '迁移未按未删除物料属性补齐配件/圆钢仓库'
    );
    expectStockThreshold(
        str_contains($sql, "`w`.`stock` IS NULL")
            && str_contains($sql, "TRIM(`w`.`stock`) = ''")
            && str_contains($sql, "`w`.`is_delete` = 0")
            && str_contains($sql, "`m`.`is_delete` = 0"),
        '迁移未限制为未删除且仓库为空的库存行'
    );
    expectStockThreshold(
        !str_contains($sql, "'成品仓库'"),
        '迁移不应处理成品库存'
    );
});

runStockThresholdCase('summary_counts_only_inventory_allowed_scopes', static function (): void {
    $counts = ['配件' => 4, '圆钢' => 3];

    expectStockThreshold(
        StockAlertService::summaryForAllowedTypes($counts, ['配件']) === [
            'accessory_count' => 4,
            'round_steel_count' => 0,
            'total_count' => 4,
        ],
        '无圆钢库存权限时仍返回圆钢预警'
    );
    expectStockThreshold(
        StockAlertService::summaryForAllowedTypes($counts, ['圆钢']) === [
            'accessory_count' => 0,
            'round_steel_count' => 3,
            'total_count' => 3,
        ],
        '无配件库存权限时仍返回配件预警'
    );
    expectStockThreshold(
        StockAlertService::summaryForAllowedTypes($counts, []) === [
            'accessory_count' => 0,
            'round_steel_count' => 0,
            'total_count' => 0,
        ],
        '无库存权限时预警汇总应全部为零'
    );
});

runStockThresholdCase('summary_uses_inventory_action_permission', static function () use ($root): void {
    $source = (string) file_get_contents($root . '/app/common/service/admin/StockAlertService.php');

    expectStockThreshold(
        str_contains($source, 'WarehouseScopeService::ACTION_INVENTORY')
            && str_contains($source, 'WarehouseScopeService::allowedTypes'),
        '预警汇总未按 inventory action 权限裁剪'
    );
});

if ($failed !== []) {
    fwrite(STDERR, "FAIL " . count($failed) . " cases\n- " . implode("\n- ", $failed) . PHP_EOL);
    exit(1);
}

echo "PASS {$passed} cases\n";
