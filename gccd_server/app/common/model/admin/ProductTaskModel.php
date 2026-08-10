<?php

namespace app\common\model\admin;

use think\Model;

/**
 * 物料模型
 */
class ProductTaskModel extends Model
{
    protected $name = "product_task";
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
    public function getGyName()
    {
        return $this->hasOne(RoutingModel::class, 'routing_code', 'routing_code')
            ->bind(
                [
                    'routing_name' => 'routing_name'
                ]
            );
    }
    public function getPlanName()
    {
        return $this->hasOne(ProductionModel::class, 'production_plan_code', 'relation_code')
            ->bind(
                [
                    'production_plan_id' => 'id',
                    'production_plan_name' => 'production_plan_name',
                    'english_material' => 'english_material',
                ]
            );
    }
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
                    // [AI-2026-08] 圆钢标准四字段 DTO
                    'y_material_name' => 'material_name',
                    'y_material_manufacturer' => 'manufacturer',
                    'y_material_spec' => 'material_spec',
                    'y_material_model' => 'material_model'
                ]
            );
    }
}
