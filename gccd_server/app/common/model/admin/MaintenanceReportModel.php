<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 机修电工日常报工模型
 */
class MaintenanceReportModel extends Model
{
    // 表名
    protected $name = 'maintenance_report';
    // 主键
    protected $pk = 'id';

    // 关联设备
    public function device()
    {
        return $this->belongsTo(DeviceModel::class, 'device_id', 'id');
    }

    // 关联维修项 (如果是从 product_count 表选的)
    public function repairItem()
    {
        return $this->belongsTo(ProductCountModel::class, 'repair_item_id', 'id');
    }
}
