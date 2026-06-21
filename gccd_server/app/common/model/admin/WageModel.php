<?php

namespace app\common\model\admin;

use think\Model;

class WageModel extends Model
{
    protected $name = "wage";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(WageModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
