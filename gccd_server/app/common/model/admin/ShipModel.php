<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 发货单模型
 */
class ShipModel extends Model
{
    protected $name = "ship";
    protected $pk = 'id';

    /*查询仓库物料的数量*/
    public function getStock()
    {
        return $this->hasOne(WmsMaterialModel::class, 'material_id', 'id')
            ->bind(
                [
                    'material_num' => 'material_num' ?? 0
                ]
            );
    }
}
