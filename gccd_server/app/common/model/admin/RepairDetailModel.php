<?php

namespace app\common\model\admin;

use think\Model;

class RepairDetailModel extends Model
{
    protected $name = "repair_record";
    protected $pk = 'id';
    /*查询分类名称*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'material_id')
            ->bind(
                [
                    'material_name' => 'material_name'
                ]
            );
    }
}
