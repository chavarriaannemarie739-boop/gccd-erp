<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 物料模型
 */
class RoutingModel extends Model
{
    protected $name = "routing";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(RoutingModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
}
