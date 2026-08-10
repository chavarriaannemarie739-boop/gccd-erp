<?php

/**
 * [AI-2026-08] 最低库存阈值按“物料 + 仓库范围”统一读写。
 */

declare(strict_types=1);

namespace app\common\service\admin;

use app\common\model\admin\MaterialModel;
use app\common\model\admin\MaterialStockThresholdModel;
use think\facade\Db;

class MaterialStockThresholdService
{
    /**
     * @param string $warehouse 仓库 Tab、仓库名称或标准范围
     * @return string 配件|圆钢，非阈值仓库返回空串
     */
    public static function normalizeScope(string $warehouse): string
    {
        $scope = WarehouseScopeService::normalizeType($warehouse);

        return in_array($scope, [
            WarehouseScopeService::TYPE_ACCESSORY,
            WarehouseScopeService::TYPE_ROUND,
        ], true) ? $scope : '';
    }

    /**
     * @param array<int, mixed> $materialIds
     * @return int[]
     */
    public static function normalizeMaterialIds(array $materialIds): array
    {
        $unique = [];
        foreach ($materialIds as $materialId) {
            $id = (int) $materialId;
            if ($id > 0) {
                $unique[$id] = true;
            }
        }

        return array_keys($unique);
    }

    /**
     * @param mixed $raw
     * @return float|null null 表示删除阈值
     */
    public static function normalizeMinStockQty($raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $quantity = round((float) $raw, 3);

        return $quantity > 0 ? $quantity : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $scope
     * @return array<int, float>
     */
    public static function thresholdMapForScopeRows(array $rows, string $scope): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (($row['warehouse_scope'] ?? '') !== $scope) {
                continue;
            }
            $materialId = (int) ($row['material_id'] ?? 0);
            $quantity = self::normalizeMinStockQty($row['min_stock_qty'] ?? null);
            if ($materialId > 0 && $quantity !== null) {
                $map[$materialId] = $quantity;
            }
        }

        return $map;
    }

    /**
     * @param int[] $materialIds
     * @param string $scope
     * @return array<int, float>
     */
    public static function thresholdsByMaterialIds(array $materialIds, string $scope): array
    {
        $ids = self::normalizeMaterialIds($materialIds);
        $normalizedScope = self::normalizeScope($scope);
        if ($ids === [] || $normalizedScope === '') {
            return [];
        }
        $rows = (new MaterialStockThresholdModel())
            ->where('warehouse_scope', $normalizedScope)
            ->whereIn('material_id', $ids)
            ->field('material_id, warehouse_scope, min_stock_qty')
            ->select()
            ->toArray();

        return self::thresholdMapForScopeRows($rows, $normalizedScope);
    }

    /**
     * 获取有效物料在指定仓库范围内的全部阈值。
     *
     * @param string $scope
     * @return array<int, float>
     */
    public static function activeThresholds(string $scope): array
    {
        $normalizedScope = self::normalizeScope($scope);
        if ($normalizedScope === '') {
            return [];
        }
        $rows = (new MaterialStockThresholdModel())
            ->alias('t')
            ->join('ya_material m', 'm.id = t.material_id')
            ->where('t.warehouse_scope', $normalizedScope)
            ->where('m.is_delete', 0)
            ->where('m.material_property', $normalizedScope)
            ->field('t.material_id, t.warehouse_scope, t.min_stock_qty')
            ->select()
            ->toArray();

        return self::thresholdMapForScopeRows($rows, $normalizedScope);
    }

    /**
     * 批量设置或删除最低库存阈值。
     *
     * @param array<int, mixed> $materialIds
     * @param string $warehouse
     * @param mixed $minStockQty
     * @return array{material_ids:int[],warehouse_scope:string,min_stock_qty:?float,updated:int}
     * @throws \Throwable
     */
    public static function batchUpdate(array $materialIds, string $warehouse, $minStockQty): array
    {
        $ids = self::normalizeMaterialIds($materialIds);
        if ($ids === []) {
            exception('请选择要设置的库存物料');
        }
        $scope = self::normalizeScope($warehouse);
        if ($scope === '') {
            exception('仅配件库和圆钢库可设置最低库存');
        }
        if (!WarehouseScopeService::tabAllowed(WarehouseScopeService::ACTION_INVENTORY, $scope)) {
            exception('无该仓库的库存管理权限');
        }
        $quantity = self::normalizeMinStockQty($minStockQty);

        Db::startTrans();
        try {
            $materials = (new MaterialModel())
                ->whereIn('id', $ids)
                ->where('is_delete', 0)
                ->lock(true)
                ->field('id, material_property')
                ->select()
                ->toArray();
            if (count($materials) !== count($ids)) {
                exception('部分物料不存在或已删除');
            }
            foreach ($materials as $material) {
                if (trim((string) ($material['material_property'] ?? '')) !== $scope) {
                    exception('所选物料与当前仓库范围不匹配');
                }
            }

            $thresholdModel = new MaterialStockThresholdModel();
            $existingIds = $thresholdModel
                ->where('warehouse_scope', $scope)
                ->whereIn('material_id', $ids)
                ->lock(true)
                ->column('material_id');
            $existingIds = self::normalizeMaterialIds($existingIds);

            if ($quantity === null) {
                $updated = $thresholdModel
                    ->where('warehouse_scope', $scope)
                    ->whereIn('material_id', $ids)
                    ->delete();
            } else {
                $now = datetime();
                $userId = (int) user_id();
                $updated = 0;
                if ($existingIds !== []) {
                    $updated += (int) $thresholdModel
                        ->where('warehouse_scope', $scope)
                        ->whereIn('material_id', $existingIds)
                        ->update([
                            'min_stock_qty' => $quantity,
                            'update_time' => $now,
                            'update_uid' => $userId,
                        ]);
                }
                $existingLookup = array_fill_keys($existingIds, true);
                $insertRows = [];
                foreach ($ids as $id) {
                    if (isset($existingLookup[$id])) {
                        continue;
                    }
                    $insertRows[] = [
                        'material_id' => $id,
                        'warehouse_scope' => $scope,
                        'min_stock_qty' => $quantity,
                        'create_time' => $now,
                        'create_uid' => $userId,
                    ];
                }
                if ($insertRows !== []) {
                    $updated += (int) $thresholdModel->insertAll($insertRows);
                }
            }
            Db::commit();
        } catch (\Throwable $throwable) {
            Db::rollback();
            throw $throwable;
        }

        return [
            'material_ids' => $ids,
            'warehouse_scope' => $scope,
            'min_stock_qty' => $quantity,
            'updated' => (int) $updated,
        ];
    }
}
