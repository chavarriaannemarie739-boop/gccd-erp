<?php

namespace app\common\service\admin;

use app\common\model\admin\WmsMaterialModel;

/**
 * 配件/圆钢库存阈值预警
 */
class StockAlertService
{
    /**
     * 物料属性 → 仓库汇总关键字
     */
    public static function warehouseKeywordFromProperty(string $materialProperty): string
    {
        $p = trim($materialProperty);
        if ($p === '配件') {
            return '配件';
        }
        if ($p === '圆钢') {
            return '圆钢';
        }

        return '';
    }

    /**
     * 库存列表 Tab → 仓库汇总关键字
     */
    public static function warehouseKeywordFromTab(string $warehouseTab): string
    {
        $tab = trim($warehouseTab);
        $map = [
            '配件库' => '配件',
            '圆钢库' => '圆钢',
            '原料库' => '圆钢',
        ];

        return $map[$tab] ?? '';
    }

    /**
     * 规范化最低库存（空=不预警）
     *
     * @param mixed $raw
     */
    public static function normalizeMinStockQty($raw): ?float
    {
        return MaterialStockThresholdService::normalizeMinStockQty($raw);
    }

    /**
     * @param \think\db\Query|\think\Model $query
     */
    public static function applyStockWarehouseWhere($query, string $warehouseKeyword): void
    {
        $likes = InStockService::warehouseSearchOrLikesFromTabKeyword($warehouseKeyword);
        if ($likes === null || $likes === []) {
            return;
        }
        $query->where(function ($q) use ($likes) {
            $q->where('stock', 'like', $likes[0]);
            for ($i = 1, $n = count($likes); $i < $n; $i++) {
                $q->whereOr('stock', 'like', $likes[$i]);
            }
        });
    }

    /**
     * @param int[] $materialIds
     * @return array<int, float>
     */
    public static function sumStockByMaterialIds(array $materialIds, string $warehouseKeyword): array
    {
        if ($materialIds === [] || $warehouseKeyword === '') {
            return [];
        }
        $model = new WmsMaterialModel();
        $q = $model->where('is_delete', 0)
            ->whereIn('material_id', $materialIds)
            ->field('material_id, SUM(material_num) as total_stock')
            ->group('material_id');
        self::applyStockWarehouseWhere($q, $warehouseKeyword);
        $rows = $q->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['material_id']] = round((float) ($row['total_stock'] ?? 0), 3);
        }

        return $map;
    }

    public static function isStockAlert(float $totalStock, $minStockQty): bool
    {
        $min = self::normalizeMinStockQty($minStockQty);

        return $min !== null && round($totalStock, 3) < $min;
    }

    /**
     * 当前仓库维度下处于预警状态的物料 id
     *
     * @return int[]
     */
    public static function alertMaterialIds(string $materialProperty, string $warehouseKeyword): array
    {
        $scope = MaterialStockThresholdService::normalizeScope($warehouseKeyword);
        if ($scope === '' || $scope !== trim($materialProperty)) {
            return [];
        }
        /* [AI-2026-08] 运行时仅从新阈值表读取，库存仍按对应仓库范围汇总。 */
        $thresholds = MaterialStockThresholdService::activeThresholds($scope);
        if ($thresholds === []) {
            return [];
        }
        $ids = array_keys($thresholds);
        $totals = self::sumStockByMaterialIds($ids, $scope);
        $alertIds = [];
        foreach ($thresholds as $id => $minStockQty) {
            $total = $totals[$id] ?? 0.0;
            if (self::isStockAlert($total, $minStockQty)) {
                $alertIds[] = $id;
            }
        }

        return $alertIds;
    }

    public static function countAlertByProperty(string $materialProperty): int
    {
        $kw = self::warehouseKeywordFromProperty($materialProperty);

        return count(self::alertMaterialIds($materialProperty, $kw));
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    public static function enrichMaterialListRows(array $list, string $materialProperty): array
    {
        $kw = self::warehouseKeywordFromProperty($materialProperty);
        if ($kw === '' || $list === []) {
            return $list;
        }
        $ids = array_values(array_filter(array_map(static function ($row) {
            return (int) ($row['id'] ?? 0);
        }, $list)));
        $totals = self::sumStockByMaterialIds($ids, $kw);
        $thresholds = MaterialStockThresholdService::thresholdsByMaterialIds($ids, $kw);
        foreach ($list as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $total = $totals[$id] ?? 0.0;
            $min = $thresholds[$id] ?? null;
            $row['min_stock_qty'] = $min;
            $row['total_stock'] = $total;
            $row['is_stock_alert'] = self::isStockAlert($total, $min) ? 1 : 0;
        }
        unset($row);

        return $list;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    public static function enrichWmsListRows(array $list, string $warehouseKeyword): array
    {
        if ($warehouseKeyword === '' || $list === []) {
            return $list;
        }
        $materialIds = [];
        foreach ($list as $row) {
            $mid = (int) ($row['material_id'] ?? 0);
            if ($mid > 0) {
                $materialIds[$mid] = true;
            }
        }
        $ids = array_keys($materialIds);
        $totals = self::sumStockByMaterialIds($ids, $warehouseKeyword);
        /* [AI-2026-08] 同一物料可按配件/圆钢 scope 拥有隔离阈值。 */
        $thresholds = MaterialStockThresholdService::thresholdsByMaterialIds($ids, $warehouseKeyword);
        foreach ($list as &$row) {
            $mid = (int) ($row['material_id'] ?? 0);
            $total = $totals[$mid] ?? 0.0;
            $min = $thresholds[$mid] ?? null;
            $row['min_stock_qty'] = $min;
            $row['total_stock'] = $total;
            $row['is_stock_alert'] = self::isStockAlert($total, $min) ? 1 : 0;
        }
        unset($row);

        return $list;
    }

    /**
     * @param array<string, int> $counts
     * @param string[] $allowedTypes
     * @return array{accessory_count:int, round_steel_count:int, total_count:int}
     */
    public static function summaryForAllowedTypes(array $counts, array $allowedTypes): array
    {
        $accessory = in_array(WarehouseScopeService::TYPE_ACCESSORY, $allowedTypes, true)
            ? (int) ($counts[WarehouseScopeService::TYPE_ACCESSORY] ?? 0)
            : 0;
        $roundSteel = in_array(WarehouseScopeService::TYPE_ROUND, $allowedTypes, true)
            ? (int) ($counts[WarehouseScopeService::TYPE_ROUND] ?? 0)
            : 0;

        return [
            'accessory_count' => $accessory,
            'round_steel_count' => $roundSteel,
            'total_count' => $accessory + $roundSteel,
        ];
    }

    /**
     * [AI-2026-08] 预警汇总仅统计当前用户可见的库存仓库范围。
     *
     * @return array{accessory_count:int, round_steel_count:int, total_count:int}
     */
    public static function summary(): array
    {
        $allowedTypes = WarehouseScopeService::allowedTypes(
            WarehouseScopeService::ACTION_INVENTORY
        );
        $counts = [];
        if (in_array(WarehouseScopeService::TYPE_ACCESSORY, $allowedTypes, true)) {
            $counts[WarehouseScopeService::TYPE_ACCESSORY] = self::countAlertByProperty(
                WarehouseScopeService::TYPE_ACCESSORY
            );
        }
        if (in_array(WarehouseScopeService::TYPE_ROUND, $allowedTypes, true)) {
            $counts[WarehouseScopeService::TYPE_ROUND] = self::countAlertByProperty(
                WarehouseScopeService::TYPE_ROUND
            );
        }

        return self::summaryForAllowedTypes($counts, $allowedTypes);
    }
}
