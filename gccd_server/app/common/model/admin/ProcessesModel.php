<?php

namespace app\common\model\admin;

use think\Model;

class ProcessesModel extends Model
{
    protected $name = "processes";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(ProcessesModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
