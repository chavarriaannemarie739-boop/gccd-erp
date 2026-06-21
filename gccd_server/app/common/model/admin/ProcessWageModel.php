<?php

namespace app\common\model\admin;

use think\Model;

class ProcessWageModel extends Model
{
    protected $name = "process_wage";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(ProcessWageModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
