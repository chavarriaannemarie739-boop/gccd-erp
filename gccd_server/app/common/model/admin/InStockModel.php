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
                    'material_name' => 'material_name',
                    'material_spec' => 'material_spec',
                    'material_model' => 'material_model',
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
