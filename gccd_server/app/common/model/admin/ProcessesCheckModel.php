<?php

namespace app\common\model\admin;

use think\Model;

class ProcessesCheckModel extends Model
{
    protected $name = "processes_check";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(ProcessesCheckModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
