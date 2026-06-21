<?php

namespace app\common\model\admin;

use think\Model;

class PowerModel extends Model
{
    protected $name = "power";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(PowerModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
