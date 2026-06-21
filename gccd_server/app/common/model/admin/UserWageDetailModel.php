<?php

namespace app\common\model\admin;

use think\Model;

class UserWageDetailModel extends Model
{
    protected $name = "user_wage_details";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(UserWageDetailModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
