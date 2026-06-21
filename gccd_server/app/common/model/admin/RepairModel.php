<?php

namespace app\common\model\admin;

use think\Model;

class RepairModel extends Model
{
    protected $name = "repair";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(ProcessesModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'processes_name',
                ]
            );
    }
    /*获取设备名称*/
    public function getDeviceName()
    {
        return $this->hasOne(DeviceModel::class, 'device_code', 'device_code')
            ->bind(
                [
                    'device_name' => 'device_name'
                ]
            );
    }
}
