<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 物料模型
 */
class ProductPowerModel extends Model
{
    protected $name = "product_power";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getTrack()
    {
        return $this->hasOne(ProductPowerModel::class, 'id', 'track_id')
            ->bind(
                [
                    'track_name' => 'name'
                ]
            );
    }
    /*获取工序名称*/
    public function getProcess()
    {
        return $this->hasOne(ProcessesModel::class, 'processes_code', 'process_code')
            ->bind(
                [
                    'processes_name' => 'processes_name'
                ]
            );
    }
    /*获取设备名称*/
    public function getDevice()
    {
        return $this->hasOne(DeviceModel::class, 'device_code', 'device_code')
            ->bind(
                [
                    'device_name' => 'device_name',
                    'process_code' => 'processes_code'
                ]
            );
    }
}
