<?php

namespace app\common\model\admin;

use think\Model;

class MessageModel extends Model
{
    protected $name = "message";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(MessageModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
