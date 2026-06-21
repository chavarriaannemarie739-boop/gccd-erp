<?php

namespace app\common\model\admin;

use app\common\model\system\UserModel;
use think\Model;

class UserWageModel extends Model
{
    protected $name = "user_wage";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getUserName()
    {
        return $this->hasOne(UserModel::class, 'user_id', 'user_id')
            ->bind(
                [
                    'name' => 'nickname'
                ]
            );
    }
}
