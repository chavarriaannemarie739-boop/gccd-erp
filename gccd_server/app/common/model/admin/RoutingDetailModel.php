<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 物料模型
 */
class RoutingDetailModel extends Model
{
    protected $name = "routing_detail";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getProcessName()
    {
        return $this->hasOne(ProcessesModel::class, 'processes_code', 'process_code')
            ->bind(
                [
                    'processes_name' => 'processes_name'
                ]
            );
    }
}
