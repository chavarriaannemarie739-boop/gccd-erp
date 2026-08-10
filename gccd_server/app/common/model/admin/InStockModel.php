<?php

namespace app\common\model\admin;

use app\common\model\system\UserModel;
use think\Model;

class InStockModel extends Model
{
    protected $name = "wms_in";
    protected $pk = 'id';

    /*与物料拓展表建立绑定关系*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'material_id')
            ->bind(
                [
                    // [AI-2026-08] 仓储圆钢标准四字段，保留 material_* 兼容
                    'material_name' => 'material_name',
                    'material_spec' => 'material_spec',
                    'material_model' => 'material_model',
                    'manufacturer' => 'manufacturer',
                    'material_property' => 'material_property',
                ]
            );
    }
    public function getUser()
    {
        return $this->hasOne(UserModel::class, 'user_id', 'create_uid')
            ->bind(
                [
                    'create_name' => 'nickname'
                ]
            );
    }
}
