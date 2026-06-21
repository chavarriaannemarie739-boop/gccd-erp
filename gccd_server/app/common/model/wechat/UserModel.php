<?php

namespace app\common\model\wechat;

use app\common\model\admin\ProductionModel;
use app\common\model\admin\ProcessesModel;
use think\Model;

class UserModel extends Model
{
    protected $name = "wx_user";
    protected $pk = 'id';

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
