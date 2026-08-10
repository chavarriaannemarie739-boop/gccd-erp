<?php

namespace app\common\model\admin;

use think\Model;

class OutStockModel extends Model
{
    protected $name = "wms_out";
    protected $pk = 'id';

    /*与物料拓展表建立绑定关系*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'material_id')
            ->bind(
                [
                    // [AI-2026-08] 出库圆钢标准四字段，保留 material_* 兼容
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
    public function getStock()
    {
        return $this->hasOne(InStockModel::class, 'material_id', 'material_id')
            ->bind(
                [
                    'warehouse' => 'warehouse'
                ]
            );
    }
}
