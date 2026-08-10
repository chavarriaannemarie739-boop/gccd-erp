<?php

namespace app\common\model\admin;

use app\common\service\admin\InStockService;
use think\Model;

class WmsMaterialModel extends Model
{
    protected $name = "wms_material";
    protected $pk = 'id';

    public function getTrack()
    {
        return $this->hasOne(ProcessesModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
    /*获取物料的名称规格信息*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'material_id')
            ->bind(
                [
                    // [AI-2026-08] 库存圆钢标准四字段，保留 material_* 兼容
                    'material_name' => 'material_name',
                    'material_spec' => 'material_spec',
                    'material_thickness' => 'material_thickness',
                    'material_model' => 'material_model',
                    'supplier' => 'supplier',
                    'manufacturer' => 'manufacturer',
                    'material_property' => 'material_property',
                    'is_product' => 'is_product',
                ]
            );
    }
    /*获取物料库位和仓库*/
    public function getStock()
    {
        return $this->hasOne(InStockModel::class, 'material_id', 'material_id')
            ->bind(
                [
                    'warehouse' => 'warehouse',
                    'location' => 'location',
                    'relation_code' => 'relation_code',
                    'in_create_time' => 'create_time',
                    'legacy_y_material_spec' => 'material_spec1',
                ]
            );
    }
    /**
     * 取入库单行上的金额信息。必须按批次关联，否则会混入同一物料下其它批次的多行数据。
     *
     * @param int|string $material_id
     * @param string|null $material_batch 当前库存行批次；传入时只返回该批次对应的一条（或零条）
     */
    public function getMoney($material_id, $material_batch = null)
    {
        $q = $this->alias('a')
            ->field('b.material_batch,b.money,b.sum')
            ->leftJoin('wms_in b', 'a.material_id=b.material_id AND a.material_batch=b.material_batch')
            ->where('a.material_id', $material_id);
        if ($material_batch !== null && $material_batch !== '') {
            $q->where('a.material_batch', $material_batch);
        }
        return $q->select()->toArray();
    }
}
