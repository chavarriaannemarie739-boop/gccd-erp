<?php

namespace app\common\model\admin;

use think\Model;

class ProductionDetailModel extends Model
{
    protected $name = "production_detail";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'english_material')
            ->bind(
                [
                    'material_name' => 'material_name',
                    'material_spec' => 'material_spec',
                ]
            );
    }
    public function orderDetails()
    {
        return $this->hasMany(Prod::class, 'plan_id', 'id');
    }
}
