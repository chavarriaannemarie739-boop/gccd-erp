<?php

namespace app\common\model\admin;

use think\Model;

class CheckModel extends Model
{
    protected $name = "check";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(CheckModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
