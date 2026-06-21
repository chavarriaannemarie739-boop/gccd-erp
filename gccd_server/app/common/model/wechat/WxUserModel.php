<?php

namespace app\common\model\wechat;

use app\common\model\admin\ProcessesModel;
use think\Model;

class WxUserModel extends Model
{
    protected $name = "wx_apply_user";
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
