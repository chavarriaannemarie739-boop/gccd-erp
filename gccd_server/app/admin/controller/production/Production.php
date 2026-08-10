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
use app\common\model\wechat\WxUserModel;
use app\common\service\admin\FlowCardService;
use app\common\service\admin\ProductTaskService;
use app\common\service\admin\ProductionService;
use app\common\service\admin\PublicService;
use app\common\service\utils\MaterialSearchHelper;
use hg\apidoc\annotation as Apidoc;
use think\App;
use think\facade\Request;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("生产订单管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Production extends BaseController
{
    /**
    * @Apidoc\Title("生产订单 列表")
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
        $param = $this->params(ProductionService::$search_field);
            /*用户名*/
        if (!empty($param['production_plan_name'])) {
            $where[] = ['production_plan_name', 'like', '%' . $param['production_plan_name'] . '%'];
        }
        /*回填日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        if (!empty($param['production_plan_code'])) {
            $where[] = ['production_plan_code', 'like', '%' . $param['production_plan_code'] . '%'];
        }
        /* [AI-2026-08] 圆钢四字段组合搜索，兼容 y_mateial_* 读入 */
        $roundFilters = MaterialSearchHelper::resolveRoundSteelFilters($param);
        $ySpec = $roundFilters['spec'];
        $yModel = $roundFilters['model'];
        $planCodeFilters = [];
        if (!empty($param['material_spec']) || !empty($param['material_model'])) {
            $planCodeFilters[] = MaterialSearchHelper::planCodesByFinishedMaterial(
                (string) ($param['material_spec'] ?? ''),
                (string) ($param['material_model'] ?? '')
            );
        }
        if (MaterialSearchHelper::hasRoundSteelFilters($roundFilters)) {
            $planCodeFilters[] = MaterialSearchHelper::planCodesByRawMaterial(
                $roundFilters['spec'],
                $roundFilters['model'],
                $roundFilters['name'],
                $roundFilters['manufacturer']
            );
        }
        if (!empty($planCodeFilters)) {
            $planCodes = MaterialSearchHelper::intersectPlanCodeGroups($planCodeFilters);
            if (!empty($planCodes)) {
                $where[] = ['production_plan_code', 'in', $planCodes];
            } else {
                $where[] = ['production_plan_code', '=', ''];
            }
        }

        /* 进行中 / 已完成（与小程序工单完成状态一致） */
        if (isset($param['plan_finish']) && $param['plan_finish'] !== '' && $param['plan_finish'] !== null) {
            $pf = (string) $param['plan_finish'];
            $doneCodes = ProductTaskService::relationCodesAllTasksFinishedMiniStyle();
            if ($pf === '1') {
                if ($doneCodes === []) {
                    $where[] = ['id', '=', 0];
                } else {
                    $where[] = ['production_plan_code', 'in', $doneCodes];
                }
            } else {
                if ($doneCodes !== []) {
                    $where[] = ['production_plan_code', 'not in', $doneCodes];
                }
            }
        }

        /**/
        $listOptions = [];
        if (MaterialSearchHelper::hasRoundSteelFilters($roundFilters)) {
            $listOptions['yMaterialName'] = $roundFilters['name'];
            $listOptions['yMaterialManufacturer'] = $roundFilters['manufacturer'];
            $listOptions['yMaterialSpec'] = $roundFilters['spec'];
            $listOptions['yMaterialModel'] = $roundFilters['model'];
        }
        $data = ProductionService::list($where, $this->page(), $this->limit(), $this->order(), [], $listOptions);
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
        $data = ProductionService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加回填数据")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {

        $param = $this->param();

        $data = ProductionService::add($param);

        return success($data);
    }

    /**
     * @Apidoc\Title("微信小程序添加回填数据")
     * @Apidoc\Method("POST")
     * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
     */
    public function wechatadd()
    {
        /*创建调试单*/
        $param = $this->params(ProductionService::$edit_field);
        /**/
        $data = ProductionService::wechatadd($param);

        return success($data);
    }
    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(ProductionService::$edit_field);


        $data = ProductionService::edit($param['id'], $param);

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
        $data = ProductionService::dele($param['ids']);
        return success($data);
    }

    /**
     * 下推计划
     * @return
     *
     */
    public function push()
    {
        $param = $this->params(['production_plan_code' => '','content' => '','routing_code' => '']);
        /*修改订单的状态*/
        $data = ProductionService::push($param);

        return success($data);
    }
    /*查询某个计划的生产进度*/
    public function getProcess()
    {
        $param = $this->param('id');
        $data = ProductionService::getProcess($param);
        return success($data);
    }

    /**
     * 上传文件返回图片地址
     * @return \json
     *
     */
    public function upload()
    {
        $file = request()->file('src');
        // 验证文件是否存在
        if (empty($file)) {
            exception('图片不能为空');
        }
        $data = PublicService::upLoad($file);
        $field = [
            'img' => Request::domain() . '/storage/' . $data,
        ];
        return success($field);
    }
    /*微信小程序上传文档以日期和文件名上传*/
    public function uploaddoc()
    {
        $file = request()->file('src');
        // 验证文件是否存在
        if (empty($file)) {
            exception('图片不能为空');
        }
        $data = PublicService::upLoaddoc($file);
        $field = [
            'img' => Request::domain() . '/storage/' . $data,
        ];
        return success($field);
    }
    /**
     * 工序成本日报表数据（供前端页面展示，与导出同源统计）
     * @Apidoc\Title("工序成本日报表数据")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("start_date", type="string", desc="起始日期", example="2024-01-01")
     * @Apidoc\Param("end_date", type="string", desc="结束日期", example="2024-01-31")
     */
    public function getProcessCostDailyReport()
    {
        $param = $this->params([
            'start_date' => '',
            'end_date' => ''
        ]);
        $data = ProductionService::getProcessCostDailyReport(
            $param['start_date'] ?? '',
            $param['end_date'] ?? ''
        );

        return success($data);
    }

    /**
     * 导出工序成本日报表
     * @Apidoc\Title("导出工序成本日报表")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("start_date", type="string", desc="起始日期（不传则默认为今天）", example="2024-01-01")
     * @Apidoc\Param("end_date", type="string", desc="结束日期（不传则默认为今天）", example="2024-01-01")
     * @Apidoc\Returned("filename", type="string", desc="文件名")
     * @Apidoc\Returned("filepath", type="string", desc="文件路径")
     * @Apidoc\Returned("url", type="string", desc="文件访问URL")
     * @Apidoc\Returned("size", type="int", desc="文件大小")
     * @Apidoc\Returned("time", type="string", desc="生成时间")
     */
    public function exportProcessCostDailyReport()
    {
        $param = $this->params([
            'start_date' => '',
            'end_date' => ''
        ]);
        $result = ProductionService::exportProcessCostDailyReport(
            $param['start_date'] ?? '',
            $param['end_date'] ?? ''
        );

        return success($result['data'], $result['msg']);
    }

    /**
     * 流转卡：打印选项（多圆钢 / 双穿孔设备）
     */
    public function flowCardPrintOptions()
    {
        $param = $this->params(['id/d' => 0]);
        $data = FlowCardService::printOptions((int) $param['id']);

        return success($data);
    }

    /**
     * 流转卡：标签数据（含二维码）
     */
    public function flowCardLabel()
    {
        $param = $this->params([
            'id/d' => 0,
            'material_id/d' => 0,
            'device_code' => '',
        ]);
        $data = FlowCardService::labelData(
            (int) $param['id'],
            (int) $param['material_id'],
            (string) ($param['device_code'] ?? '')
        );

        return success($data);
    }
}
