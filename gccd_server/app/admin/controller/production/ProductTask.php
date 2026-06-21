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
use app\common\service\admin\FlowCardService;
use app\common\service\admin\MiniProgramWorkService;
use app\common\service\admin\ProductTaskService;
use app\common\service\admin\RoutingProcessHelper;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("生产任务管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class ProductTask extends BaseController
{
    /**
    * @Apidoc\Title("工艺列表")
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
        $param = $this->params(ProductTaskService::$search_field);
            /*用户名*/
        if (!empty($param['task_code'])) {
            $where[] = ['task_code', 'like', '%' . $param['task_code'] . '%'];
        }
        if (!empty($param['processes_code'])) {
            $where[] = ['processes_code', 'like', '%' . $param['processes_code'] . '%'];
        }
        if (!empty($param['relation_code'])) {
            $where[] = ['relation_code', 'like', '%' . $param['relation_code'] . '%'];
        }
        if (!empty($param['routing_code'])) {
            $where[] = ['routing_code', '=', $param['routing_code']];
        }
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = ProductTaskService::list($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }

    /**
     * 小程序：按工序/设备筛选工单
     */
    public function listForMini()
    {
        $param = $this->param();
        $processesCode = trim((string) ($param['processes_code'] ?? ''));
        $deviceCode = trim((string) ($param['device_code'] ?? ''));
        if ($processesCode === '' && !empty($param['processes_name'])) {
            $processesCode = RoutingProcessHelper::getProcessCodeByName((string) $param['processes_name']);
        }
        $keyword = trim((string) ($param['keyword'] ?? ''));
        $data = ProductTaskService::listForMini(
            $processesCode,
            $deviceCode,
            $this->page(),
            $this->limit(),
            $keyword
        );

        return success($data);
    }

    /**
     * 小程序：当前用户报工入口上下文
     */
    public function workContext()
    {
        $data = MiniProgramWorkService::resolveWorkContext((int) user_id());

        return success($data);
    }

    /**
     * 小程序：扫码分流（设备码 / 流转卡码）
     */
    public function flowCardScan()
    {
        $param = $this->params(['payload' => '']);
        $data = FlowCardService::resolveScan((string) $param['payload'], (int) user_id());

        return success($data);
    }

    /**
     * 按多个生产计划单号（relation_code）一次查询关联工单，供生产计划列表工序报工汇总，减少前端请求次数
     */
    public function listByRelationCodes()
    {
        $param = $this->param();
        $codes = $param['relation_codes'] ?? [];
        if (!is_array($codes)) {
            $codes = [];
        }
        $codes = array_values(array_unique(array_filter(array_map(static function ($c) {
            return is_string($c) ? trim($c) : '';
        }, $codes))));
        if (count($codes) > 150) {
            $codes = array_slice($codes, 0, 150);
        }
        if ($codes === []) {
            return success(['list' => [], 'count' => 0, 'page' => 1, 'limit' => 0, 'pages' => 0]);
        }
        $where = [
            ['is_delete', '=', '0'],
            ['relation_code', 'in', $codes],
        ];
        $data = ProductTaskService::list($where, 0, 0, $this->order());
        return success($data);
    }

    /**
    * @Apidoc\Title("信息")
    * @Apidoc\Query(ref="app\common\model\MesOrderModel", field="id")
    * @Apidoc\Returned(ref="app\common\model\MesOrderModel")
    */
    public function info()
    {
        $param = $this->params(['id/d' => '','processes_code' => '']);
        $data = ProductTaskService::info($param['id'], true, $param['processes_code']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加工序")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        $param = $this->param();
//        $param['relation_code'] = 'scjh-001';
//        $param['task_code']='';
//        $param['routing_code']=10;
//        $param['content'] = [
//            [
//                'material_id'=>10,
//                'task_num'=>10,
//                'processes_code'=>'444s44s4s',
//            ]
//        ];
        $data = ProductTaskService::add($param);

        return success($data);
    }

    public function addProcess()
    {
        /*创建*/
        $param = $this->param();
        $data = ProductTaskService::addProcess($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(ProductTaskService::$edit_field);


        $data = ProductTaskService::edit($param['id'], $param);

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
        $data = ProductTaskService::dele($param['ids']);
        return success($data);
    }

    /**
     * @return
     * 修改调试单的状态
     */
    public function updatestatus()
    {
        $param = $this->params(['status' => '','id' => '','refuse_reason' => '']);
        /*修改订单的状态*/
        $data = ProductTaskService::updatestatus($param);

        return success($data);
    }
}
