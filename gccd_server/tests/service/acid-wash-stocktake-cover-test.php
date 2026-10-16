<?php

/**
 * [AI-2026-10] 酸洗盘点覆盖成品库存：先进先出分摊，以及按成品规格扣发货。
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$inventoryPath = $root . '/app/common/service/admin/MaterialInventoryService.php';
$finishedPath = $root . '/app/common/service/admin/FinishedStockService.php';
if (!is_file($inventoryPath) || !is_file($finishedPath)) {
    fwrite(STDERR, "FAIL 缺少盘点或成品库存服务\n");
    exit(1);
}

require $inventoryPath;
require $finishedPath;

use app\common\service\admin\FinishedStockService;
use app\common\service\admin\MaterialInventoryService;

$passed = 0;
$failed = [];

/**
 * @param string $name
 * @param callable $callback
 * @return void
 */
function runAcidWashCoverCase(string $name, callable $callback): void
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
function expectAcidWashCover(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<int, array<string, mixed>> $adjusts
 * @param string $planCode
 * @param string $owner
 * @return array<string, mixed>|null
 */
function findCoverAdjust(array $adjusts, string $planCode, string $owner): ?array
{
    foreach ($adjusts as $adjust) {
        if ($adjust['plan_code'] === $planCode && $adjust['stock_owner'] === $owner) {
            return $adjust;
        }
    }

    return null;
}

runAcidWashCoverCase('single_plan_overwrites_to_counted_qty', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-202605120001',
            'stock_owner' => '溯源',
            'stock_num' => 19.64,
            'sort_time' => '2026-05-12 08:00:00',
            'plan_id' => 1,
        ],
    ], 10);

    expectAcidWashCover(count($adjusts) === 1, '单计划应只产生一笔调整');
    $adjust = $adjusts[0];
    expectAcidWashCover($adjust['before_qty'] === 19.64, '改前应是原库存');
    expectAcidWashCover($adjust['after_qty'] === 10.0, '改后应等于盘点吨数');
    expectAcidWashCover($adjust['adjust_qty'] === -9.64, '调整量应是差额');
});

runAcidWashCoverCase('shortage_deducts_earliest_plan_down_to_zero_then_next', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-old',
            'stock_owner' => '客户甲',
            'stock_num' => 5,
            'sort_time' => '2026-01-01 00:00:00',
            'plan_id' => 1,
        ],
        [
            'plan_code' => 'scjh-new',
            'stock_owner' => '客户乙',
            'stock_num' => 10,
            'sort_time' => '2026-06-01 00:00:00',
            'plan_id' => 2,
        ],
    ], 3);

    $old = findCoverAdjust($adjusts, 'scjh-old', '客户甲');
    $new = findCoverAdjust($adjusts, 'scjh-new', '客户乙');
    expectAcidWashCover($old !== null && $old['after_qty'] === 0.0, '最早计划应先扣到 0');
    expectAcidWashCover($new !== null && $new['after_qty'] === 3.0, '不够的部分再扣下一张');
});

runAcidWashCoverCase('within_plan_shortage_hits_inventory_row_before_customer', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-1',
            'stock_owner' => '客户甲',
            'stock_num' => 10,
            'sort_time' => '2026-03-01 00:00:00',
            'plan_id' => 1,
            'is_inventory_owner' => false,
        ],
        [
            'plan_code' => 'scjh-1',
            'stock_owner' => '库存',
            'stock_num' => 6,
            'sort_time' => '2026-03-01 00:00:00',
            'plan_id' => 1,
            'is_inventory_owner' => true,
        ],
    ], 8);

    $inventory = findCoverAdjust($adjusts, 'scjh-1', '库存');
    $customer = findCoverAdjust($adjusts, 'scjh-1', '客户甲');
    expectAcidWashCover($inventory !== null && $inventory['after_qty'] === 0.0, '同一计划应先把库存行扣到 0');
    expectAcidWashCover($customer !== null && $customer['after_qty'] === 8.0, '客户行只扣剩余差额，归属不被改记');
});

runAcidWashCoverCase('surplus_lands_on_newest_plan_customer_row', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-old',
            'stock_owner' => '客户甲',
            'stock_num' => 4,
            'sort_time' => '2026-01-01 00:00:00',
            'plan_id' => 1,
        ],
        [
            'plan_code' => 'scjh-new',
            'stock_owner' => '库存',
            'stock_num' => 1,
            'sort_time' => '2026-08-01 00:00:00',
            'plan_id' => 8,
            'is_inventory_owner' => true,
        ],
        [
            'plan_code' => 'scjh-new',
            'stock_owner' => '客户乙',
            'stock_num' => 3,
            'sort_time' => '2026-08-01 00:00:00',
            'plan_id' => 8,
        ],
    ], 12);

    expectAcidWashCover(findCoverAdjust($adjusts, 'scjh-old', '客户甲') === null, '旧计划盘盈时不动');
    expectAcidWashCover(findCoverAdjust($adjusts, 'scjh-new', '库存') === null, '库存行不接收盘盈');
    $customer = findCoverAdjust($adjusts, 'scjh-new', '客户乙');
    expectAcidWashCover($customer !== null && $customer['after_qty'] === 7.0, '盘盈应加到最新计划的客户行');
});

runAcidWashCoverCase('negative_rows_are_lifted_then_fifo_reaches_counted_total', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-neg',
            'stock_owner' => '客户甲',
            'stock_num' => -5,
            'sort_time' => '2026-02-01 00:00:00',
            'plan_id' => 2,
        ],
        [
            'plan_code' => 'scjh-pos',
            'stock_owner' => '客户乙',
            'stock_num' => 20,
            'sort_time' => '2026-04-01 00:00:00',
            'plan_id' => 4,
        ],
    ], 10);

    $negative = findCoverAdjust($adjusts, 'scjh-neg', '客户甲');
    $positive = findCoverAdjust($adjusts, 'scjh-pos', '客户乙');
    expectAcidWashCover($negative !== null && $negative['after_qty'] === 0.0, '负数行应先拉回 0');
    expectAcidWashCover($positive !== null && $positive['after_qty'] === 10.0, '拉平后再按差额扣到确认吨数');
    $total = $negative['after_qty'] + $positive['after_qty'];
    expectAcidWashCover(abs($total - 10) < 0.001, '覆盖后合计应等于确认吨数');
});

runAcidWashCoverCase('same_total_does_not_stack_another_adjust', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-1',
            'stock_owner' => '客户甲',
            'stock_num' => 10,
            'sort_time' => '2026-05-01 00:00:00',
            'plan_id' => 1,
        ],
    ], 10);

    expectAcidWashCover($adjusts === [], '已经等于盘点数时不再写调整');
});

runAcidWashCoverCase('negative_confirm_qty_is_not_allocated', static function (): void {
    $adjusts = FinishedStockService::allocateStocktakeCover([
        [
            'plan_code' => 'scjh-1',
            'stock_owner' => '客户甲',
            'stock_num' => 8,
            'sort_time' => '2026-05-01 00:00:00',
            'plan_id' => 1,
        ],
    ], -1);

    expectAcidWashCover($adjusts === [], '确认吨数为负数时不写发货调整');
});

runAcidWashCoverCase('duplicate_identity_keeps_the_last_qty', static function (): void {
    $lines = FinishedStockService::collapseAcidWashCoverLines([
        [
            'material_id' => 55,
            'c_material_spec' => '55*1.2',
            'c_material_model' => '53*6.5',
            'day_shift_qty' => '5',
            'night_shift_qty' => '0',
        ],
        [
            'material_id' => 55,
            'c_material_spec' => '55 * 1.20',
            'c_material_model' => '53*6.50',
            'day_shift_qty' => '8',
            'night_shift_qty' => '1',
        ],
    ]);

    expectAcidWashCover(count($lines) === 1, '同一圆钢同一成品应并成一行');
    expectAcidWashCover($lines[0]['qty'] === 9.0, '同一次提交同一身份保留最后一行，不把两行吨数相加');
});

runAcidWashCoverCase('acid_wash_ship_deducts_only_matching_finished_identity', static function (): void {
    $index = MaterialInventoryService::buildFinishedShipIndex([
        [
            'material_id' => 55,
            'qty' => 19.64,
            'date' => '2026-10-15',
            'relation_code' => 'scjh-202605120001',
        ],
        [
            'material_id' => 55,
            'qty' => 4,
            'date' => '2026-10-16',
            'relation_code' => 'scjh-other',
        ],
        [
            'material_id' => 55,
            'qty' => 3,
            'date' => '2026-10-16',
            'relation_code' => '',
        ],
    ], [
        'scjh-202605120001' => ['spec' => '55*1.2', 'model' => '53*6.5'],
        'scjh-other' => ['spec' => '55*1.5', 'model' => '53*6.5'],
    ]);

    $matched = MaterialInventoryService::sumFinishedShipDaily(
        $index,
        55,
        '55 * 1.20',
        '53*6.50',
        '2026-10-01',
        '2026-10-31'
    );
    $other = MaterialInventoryService::sumFinishedShipDaily(
        $index,
        55,
        '55*1.5',
        '53*6.5',
        '2026-10-01',
        '2026-10-31'
    );
    $unknown = MaterialInventoryService::sumFinishedShipDaily(
        $index,
        55,
        '',
        '',
        '2026-10-01',
        '2026-10-31'
    );

    expectAcidWashCover($matched === 19.64, '酸洗只扣本规格型号的发货');
    expectAcidWashCover($other === 4.0, '另一规格不串到这一行');
    expectAcidWashCover($unknown === null, '没有成品规格时不假装对上');
    $idTotal = 0.0;
    foreach ($index['id'][55] as $qty) {
        $idTotal += $qty;
    }
    expectAcidWashCover(abs($idTotal - 26.64) < 0.001, '没有计划单号的发货仍留在圆钢合计里，不摊进规格行');
});

echo 'passed=' . $passed . ' failed=' . count($failed) . PHP_EOL;
foreach ($failed as $message) {
    fwrite(STDERR, "FAIL {$message}\n");
}

exit($failed === [] ? 0 : 1);
