<?php

namespace app\common\service\admin;

use app\common\model\admin\InStockModel;
use app\common\model\admin\OutStockModel;
use app\common\model\admin\ProductionModel;
use app\common\model\admin\ReceivingModel;

/**
 * 批次 / 计划 / 领料 / 出库 简易追溯（只读聚合，供 PC 追溯页使用）
 */
class TraceabilityService
{
    /**
     * @param string $keyword 生产计划单号、领料单号(receiving_code)、或出库单号(out_code) 等
     */
    public static function materialFlowByKeyword(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            exception('请输入计划单号、领料单号或出库单号');
        }

        $production = (new ProductionModel())
            ->where('production_plan_code', $keyword)
            ->find();

        $receivings = (new ReceivingModel())
            ->with(['getMaterial'])
            ->where('relation_code', $keyword)
            ->whereOr('receiving_code', $keyword)
            ->order('create_time', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        $relationKeys = [$keyword];
        foreach ($receivings as $r) {
            if (!empty($r['receiving_code'])) {
                $relationKeys[] = $r['receiving_code'];
            }
            if (!empty($r['relation_code'])) {
                $relationKeys[] = $r['relation_code'];
            }
        }
        $relationKeys = array_values(array_unique(array_filter($relationKeys)));

        $outModel = new OutStockModel();
        $outs = $outModel
            ->with(['getMaterial'])
            ->where('is_delete', 0)
            ->where(function ($q) use ($relationKeys, $keyword) {
                $q->whereIn('relation_code', $relationKeys)->whereOr('out_code', $keyword);
            })
            ->order('create_time', 'desc')
            ->limit(300)
            ->select()
            ->toArray();

        foreach ($outs as &$row) {
            $row['in_unit_price'] = null;
            $row['line_material_cost'] = null;
            if (!empty($row['material_batch']) && !empty($row['material_id'])) {
                $in = (new InStockModel())
                    ->where('material_id', $row['material_id'])
                    ->where('material_batch', $row['material_batch'])
                    ->field('money,sum')
                    ->find();
                if ($in) {
                    $inArr = $in->toArray();
                    $row['in_unit_price'] = $inArr['money'] ?? null;
                    $outNum = floatval($row['out_num'] ?? 0);
                    $price = floatval($inArr['money'] ?? 0);
                    $row['line_material_cost'] = round($outNum * $price, 4);
                }
            }
        }
        unset($row);

        return [
            'keyword' => $keyword,
            'production' => $production ? $production->toArray() : null,
            'receivings' => $receivings,
            'outbounds' => $outs,
        ];
    }
}
