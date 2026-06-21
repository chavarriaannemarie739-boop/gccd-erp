<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\production;

use app\common\controller\BaseController;
use app\common\model\admin\MaterialModel;
use app\common\model\admin\ProductTaskModel;
use app\common\service\admin\ProductReportService;
use app\common\service\admin\ProductTaskService;
use app\common\service\utils\MaterialSearchHelper;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\console\output\Ask;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("报工记录管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class ProductReport extends BaseController
{
    /**
    * @Apidoc\Title("报工列表")
    * @Apidoc\Query(ref="pagingQuery")
    * @Apidoc\Query(ref="sortQuery")
    * @Apidoc\Query(ref="searchQuery")
    * @Apidoc\Query(ref="dateQuery")
    * @Apidoc\Returned(ref="expsReturn")
    * @Apidoc\Returned(ref="pagingReturn")
    * @Apidoc\Returned("list", type="array", desc="列表", children={
    *   @Apidoc\Returned(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable,create_time,update_time")
    * })
    */
    public function list()
    {
        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(ProductReportService::$search_field);
            /*用户名*/
        if (!empty($param['routing_code'])) {
            $where[] = ['routing_code', 'like', '%' . $param['routing_code'] . '%'];
        }
        if (!empty($param['report_code'])) {
            $where[] = ['report_code', 'like', '%' . $param['report_code'] . '%'];
        }
        if (!empty($param['relation_code'])) {
            $where[] = ['relation_code', 'like', '%' . $param['relation_code'] . '%'];
        }
        if (!empty($param['processes_code'])) {
            $where[] = ['processes_code', 'like', '%' . $param['processes_code'] . '%'];
        }
        if (!empty($param['processes_name'])) {
            $processModel = new \app\common\model\admin\ProcessesModel();
            $processCodes = $processModel->where('processes_name', 'like', '%' . $param['processes_name'] . '%')
                ->column('processes_code');

            if (!empty($processCodes)) {
                $where[] = ['processes_code', 'in', $processCodes];
            } else {
                $where[] = ['processes_code', '=', 'non-existent-code-to-force-empty-result'];
            }
        }
        /*设备编码*/
        if (!empty($param['device_code'])) {
            $where[] = ['device_code', 'like', '%' . $param['device_code'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        /*归属日期筛选*/
        if (!empty($param['report_date_start'])) {
            $where[] = ['report_date', '>=', $param['report_date_start']];
        }
        if (!empty($param['report_date_end'])) {
            $where[] = ['report_date', '<=', $param['report_date_end']];
        }
        /*班次筛选（白班=0，夜班=1）*/
        if ($param['class'] !== '' && $param['class'] !== null) {
            $where[] = ['class', '=', $param['class']];
        }

        /*需要特殊处理的搜索条件*/
        $reportCodes = [];

        // 1. 设备名称搜索（存储在 data JSON 字段中）
        if (!empty($param['device_name'])) {
            // 先查询所有数据，然后过滤
            $allReports = \think\facade\Db::name('product_report')
                ->where('is_delete', 0)
                ->field('id, report_code, data')
                ->select()
                ->toArray();

            $matchedIds = [];
            foreach ($allReports as $report) {
                $data = json_decode($report['data'], true);
                if (!empty($data['device_name']) && strpos($data['device_name'], $param['device_name']) !== false) {
                    $matchedIds[] = $report['id'];
                }
            }

            if (!empty($matchedIds)) {
                $where[] = ['id', 'in', $matchedIds];
            } else {
                $where[] = ['id', '=', 0]; // 无匹配结果
            }
        }

        // 2. 创建人姓名搜索（需要关联 user 表）
        if (!empty($param['create_name'])) {
            $userModel = new \app\common\model\system\UserModel();
            $users = $userModel->where('nickname', 'like', '%' . $param['create_name'] . '%')
                ->column('user_id');

            if (!empty($users)) {
                $where[] = ['create_uid', 'in', $users];
            } else {
                $where[] = ['create_uid', '=', 0];
            }
        }

        if (!empty($param['create_uid'])) {
            $where[] = ['create_uid', '=', $param['create_uid']];
        }

        // 3. 生产计划名称搜索（需要关联 production 表和 product_task 表）
        if (!empty($param['production_plan_name'])) {
            $planModel = new \app\common\model\admin\ProductionModel();
            $plans = $planModel->where('production_plan_name', 'like', '%' . $param['production_plan_name'] . '%')
                ->column('production_plan_code');

            if (!empty($plans)) {
                // 通过计划单号查找对应的工单号
                $taskModel = new \app\common\model\admin\ProductTaskModel();
                $tasks = $taskModel->whereIn('relation_code', $plans)
                    ->column('task_code');

                if (!empty($tasks)) {
                    $where[] = ['relation_code', 'in', $tasks];
                } else {
                    $where[] = ['relation_code', '=', ''];
                }
            } else {
                $where[] = ['relation_code', '=', ''];
            }
        }

        // 4. 计划单号搜索（需要通过 product_task 表关联查询）
        if (!empty($param['plan_code'])) {
            // 先找到符合条件的工单号
            $taskModel = new \app\common\model\admin\ProductTaskModel();
            $tasks = $taskModel->where('relation_code', 'like', '%' . $param['plan_code'] . '%')
                ->column('task_code');

            if (!empty($tasks)) {
                $where[] = ['relation_code', 'in', $tasks];
            } else {
                $where[] = ['relation_code', '=', ''];
            }
        }

//        return success($where);

        // 5/6. 成品/圆钢规格型号（MaterialSearchHelper，与列表 enrichReportListRow 一致）
        if (!empty($param['c_material_spec']) || !empty($param['c_material_model'])) {
            $tasks = MaterialSearchHelper::taskCodesByFinishedMaterial(
                (string) ($param['c_material_spec'] ?? ''),
                (string) ($param['c_material_model'] ?? '')
            );
            if (!empty($tasks)) {
                $where[] = ['relation_code', 'in', $tasks];
            } else {
                $where[] = ['relation_code', '=', ''];
            }
        }
        if (!empty($param['y_material_spec']) || !empty($param['y_material_model'])) {
            $tasks = MaterialSearchHelper::taskCodesByRawMaterial(
                (string) ($param['y_material_spec'] ?? ''),
                (string) ($param['y_material_model'] ?? '')
            );
            if (!empty($tasks)) {
                $where[] = ['relation_code', 'in', $tasks];
            } else {
                $where[] = ['relation_code', '=', ''];
            }
        }

        $data = ProductReportService::list($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    public function powerlist()
    {
        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(ProductReportService::$search_field);
        /*用户名*/
        if (!empty($param['routing_code'])) {
            $where[] = ['routing_code', 'like', '%' . $param['routing_code'] . '%'];
        }
        if (!empty($param['relation_code'])) {
            $where[] = ['relation_code', 'like', '%' . $param['relation_code'] . '%'];
        }
        if ($param['class'] !== '' && $param['class'] !== null) {
            $where[] = ['class', '=', $param['class']];
        }
        if (!empty($param['device_code'])) {
            $where[] = ['device_code', 'like', '%' . $param['device_code'] . '%'];
        }

        // 工序名称搜索（需要通过设备表关联查询）
        if (!empty($param['processes_name'])) {
            // 先根据工序名称找到工序编码
            $processModel = new \app\common\model\admin\ProcessesModel();
            $processCodes = $processModel->where('processes_name', 'like', '%' . $param['processes_name'] . '%')
                ->column('processes_code');

            if (!empty($processCodes)) {
                // 再通过工序编码在设备表中找到对应的设备编码
                $deviceModel = new \app\common\model\admin\DeviceModel();
                $devices = $deviceModel->whereIn('processes_code', $processCodes)
                    ->column('device_code');

                if (!empty($devices)) {
                    $where[] = ['device_code', 'in', $devices];
                } else {
                    // 没有找到匹配的设备，返回空结果
                    return success(['list' => [], 'total' => 0]);
                }
            } else {
                // 没有找到匹配的工序，返回空结果
                return success(['list' => [], 'total' => 0]);
            }
        }

        // 设备名称搜索（需要关联 device 表）
        if (!empty($param['device_name'])) {
            // 如果前面已经有 device_code 的精确搜索，这里应该合并条件而不是覆盖
            $deviceModel = new \app\common\model\admin\DeviceModel();
            $devices = $deviceModel->where('device_name', 'like', '%' . $param['device_name'] . '%')
                ->column('device_code');

            if (!empty($devices)) {
                $where[] = ['device_code', 'in', $devices];
            } else {
                // 没有找到匹配的设备，返回空结果
                return success(['list' => [], 'total' => 0]);
            }
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        /*归属日期筛选*/
        if (!empty($param['report_date_start'])) {
            $where[] = ['report_date', '>=', $param['report_date_start']];
        }
        if (!empty($param['report_date_end'])) {
            $where[] = ['report_date', '<=', $param['report_date_end']];
        }
        $data = ProductReportService::powerlist($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }
    /**
    * @Apidoc\Title("信息")
    * @Apidoc\Query(ref="app\common\model\MesOrderModel", field="id")
    * @Apidoc\Returned(ref="app\common\model\MesOrderModel")
    */
    public function info()
    {
        $param = $this->params(['id/d' => '']);
        $data = ProductReportService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加工序")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        $param = $this->params(ProductReportService::$edit_field);
        $data = ProductReportService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(ProductReportService::$edit_field);


        $data = ProductReportService::edit($param['id'], $param);

        return success($data);
    }
    /**
    * @Apidoc\Title("删除")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="idsParam")
    */
    public function dele()
    {
        $param = $this->params(['ids/a' => []]);
        $data = ProductReportService::dele($param['ids']);
        return success($data);
    }
    public function deletePowerRecord()
    {
        $param = $this->params(['ids/a' => []]);

        if (empty($param['ids'])) {
            exception('请选择要删除的记录');
        }

        $data = ProductReportService::deletePowerRecord($param['ids'], true);
        return success($data);
    }
    /**
     * @return
     * 统计能源消耗
     */
    public function powerStat()
    {
        $data = ProductReportService::powerStat();
        return success($data);
    }

    /**
     * 分工序报工新接口
     * @Apidoc\Title("分工序报工")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/production.ProductReport/report")
     * @Apidoc\Param("relation_code", type="string", require=true, desc="生产任务 task_code")
     * @Apidoc\Param("routing_code", type="string", require=true, desc="工艺编码")
     * @Apidoc\Param("processes_code", type="string", require=true, desc="工序编码")
     * @Apidoc\Param("material_id", type="int", require=true, desc="成品物料ID")
     * @Apidoc\Param("report_num", type="float", require=true, desc="报工数量(吨等)")
     * @Apidoc\Param("defective_num", type="array", require=true, desc="不良品明细")
     * @Apidoc\Param("is_replace", type="int", desc="矫直代班长：0否 1是（与正式班长津贴互斥，由后端合并计算）")
     * @Apidoc\Param("not_full_attend", type="array", desc="可选。不满勤列表，每项含 name(姓名)、hours(实际上班小时)；后端会写入 work_hours 供穿孔/球化/酸洗/矫直等按工时折算工资")
     * @Apidoc\Param("product_user", type="array", desc="穿孔/球化/酸洗等：生产人员昵称列表")
     * @Apidoc\Param("card_user", type="array", desc="穿孔：开车人员昵称列表")
     * @Apidoc\Param("device_code", type="string", desc="设备编码")
     * @Apidoc\Param("yarn_count", type="string", desc="支数（穿孔/球化/矫直等）")
     * @return \json
     */
    public function report()
    {
        $param = $this->request->post();
        $data = ProductReportService::report($param);
        return success($data);
    }

    /**
     * 填报能耗接口
     * @return \json
     */
    public function reportpower()
    {
        $param = $this->request->post();
        $data = ProductReportService::reportpower($param);
        return success($data);
    }

    /**
     * 获取能源信息
     * @return \json
     */
    public function getPower()
    {
        $param = $this->param('device_name');
        $data = ProductReportService::getPower($param);
        return success($data);
    }

    /**
     * 型号和长度和支数计算吨数
     * @return \json
     */
    public function count()
    {
        $param = $this->params(['task_code' => 0,'count' => 0]);
        /*这次需要他传递物料id*/
        $task_model = new ProductTaskModel();
        $task_data = $task_model->where('task_code', 'like', '%' . $param['task_code'] . '%')->find();
        $material_length = 0;
        $material_model = 0;
        if (!empty($task_data)) {
            $task = ProductTaskService::info($task_data['id']);
            /*处理*号之前的数据*/
            $material_length = (float)explode('*', $task['c_material_spec'])[0];
            $material_model  =  (float)explode('*', $task['c_material_spec'])[0];
            $circle = (float)explode('*', $task['c_material_spec'])[1];
        } else {
            exception('没有找到对应生产工单数据');
        }
        if ($material_length > 0  && $param['count'] > 0) {
            /*计算吨数保留两位小数*/
            $result = ($material_model * $material_length * $circle * 0.006165 * 0.97 * $param['count']) / 1000;
            $result = round($result, 2);
        } else {
            exception('检查传递的参数是否有效');
        }
        return success($result);
    }

    /**
     * 统计指定日期范围内的能耗吨数
     * @Apidoc\Title("能耗吨数统计")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("start_date", type="string", desc="起始日期（不传则统计全部）", example="2024-01-01")
     * @Apidoc\Param("end_date", type="string", desc="结束日期（不传则统计全部）", example="2024-01-31")
     * @Apidoc\Returned("phosphating_total", type="float", desc="磷化吨数总和")
     * @Apidoc\Returned("saponification_total", type="float", desc="皂化吨数总和")
     * @Apidoc\Returned("alkali_total", type="float", desc="碱吨数总和")
     */
    public function getPowerConsumptionStats()
    {
        $param = $this->params([
            'start_date' => '',
            'end_date' => '',
            'processes_name' => '',
        ]);

        $data = ProductReportService::getPowerConsumptionStats(
            $param['start_date'] ?? '',
            $param['end_date'] ?? '',
            $param['processes_name'] ?? ''
        );

        return success($data);
    }

}
