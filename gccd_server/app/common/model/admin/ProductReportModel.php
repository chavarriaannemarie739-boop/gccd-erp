<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 物料模型
 */
class ProductReportModel extends Model
{
    protected $name = "product_report";
    protected $pk = 'id';

    /*查询分类名称*/
    public function getMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'english_material')
            ->bind(
                [
                    'c_material_name' => 'material_name',
                    'c_material_spec' => 'material_spec',
                    'c_material_model' => 'material_model',
                ]
            );
    }
    public function getYMaterial()
    {
        return $this->hasOne(MaterialModel::class, 'id', 'material_id')
            ->bind(
                [
                    'y_material_name' => 'material_name',
                    'y_material_spec' => 'material_spec',
                    'y_material_model' => 'material_model',
                ]
            );
    }
    /*获取工序名称*/
    public function getProcess()
    {
        return $this->hasOne(ProcessesModel::class, 'processes_code', 'processes_code')
            ->bind(
                [
                    'processes_name' => 'processes_name'
                ]
            );
    }
    /*获取工序名称*/
    public function getTask()
    {
        return $this->hasOne(ProductTaskModel::class, 'task_code', 'relation_code')
            ->bind(
                [
                    'plan_code' => 'relation_code'
                ]
            );
    }
    public function getPlan()
    {
        return $this->hasOne(ProductionModel::class, 'production_plan_code', 'plan_code')
            ->bind(
                [
                    'production_plan_name' => 'production_plan_name' ?? 0,
                    'english_material' => 'english_material' ?? 0
                ]
            );
    }
    /*获取设备名称*/
    public function getDevice()
    {
        return $this->hasOne(DeviceModel::class, 'processes_code', 'processes_code')
            ->bind(
                [
                    'device_name' => 'device_name'
                ]
            );
    }
}
