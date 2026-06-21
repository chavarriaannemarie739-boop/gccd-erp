<?php

namespace app\common\service\admin;

use app\common\model\admin\MaterialModel;
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
        if ($raw === null || $raw === '') {
            return null;
        }
        $v = round((float) $raw, 3);

        return $v > 0 ? $v : null;
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
        if ($warehouseKeyword === '') {
            return [];
        }
        $likes = InStockService::warehouseSearchOrLikesFromTabKeyword($warehouseKeyword);
        if ($likes === null || $likes === []) {
            return [];
        }
        $materialModel = new MaterialModel();
        $rows = $materialModel
            ->where('is_delete', 0)
            ->where('material_property', $materialProperty)
            ->whereNotNull('min_stock_qty')
            ->where('min_stock_qty', '>', 0)
            ->field('id, min_stock_qty')
            ->select()
            ->toArray();
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows);
        $totals = self::sumStockByMaterialIds($ids, $warehouseKeyword);
        $alertIds = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $total = $totals[$id] ?? 0.0;
            if (self::isStockAlert($total, $row['min_stock_qty'])) {
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
        foreach ($list as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $total = $totals[$id] ?? 0.0;
            $row['total_stock'] = $total;
            $row['is_stock_alert'] = self::isStockAlert($total, $row['min_stock_qty'] ?? null) ? 1 : 0;
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
        $materialModel = new MaterialModel();
        $materials = $ids === [] ? [] : $materialModel->whereIn('id', $ids)->column('min_stock_qty', 'id');
        foreach ($list as &$row) {
            $mid = (int) ($row['material_id'] ?? 0);
            $total = $totals[$mid] ?? 0.0;
            $min = $materials[$mid] ?? null;
            $row['min_stock_qty'] = $min;
            $row['total_stock'] = $total;
            $row['is_stock_alert'] = self::isStockAlert($total, $min) ? 1 : 0;
        }
        unset($row);

        return $list;
    }

    /**
     * @return array{accessory_count:int, round_steel_count:int, total_count:int}
     */
    public static function summary(): array
    {
        $accessory = self::countAlertByProperty('配件');
        $roundSteel = self::countAlertByProperty('圆钢');

        return [
            'accessory_count' => $accessory,
            'round_steel_count' => $roundSteel,
            'total_count' => $accessory + $roundSteel,
        ];
    }
}
