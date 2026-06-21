<?php

namespace app\common\model\admin;

use think\Model;

class ProductionModel extends Model
{
    protected $name = "production";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'english_material')
            ->bind(
                [
                    'material_name' => 'material_name',
                    'material_spec' => 'material_spec',
                    'material_model' => 'material_model',
                ]
            );
    }
    /*计算所属物料的数量*/
    public function getPlanWithJoinStats($code)
    {
        return $this->alias('a')
            ->where('a.production_plan_code', '=', $code)
            ->leftJoin('production_detail b', 'a.production_plan_code = b.production_plan_code')
            ->sum('b.plan_num');
    }
}
