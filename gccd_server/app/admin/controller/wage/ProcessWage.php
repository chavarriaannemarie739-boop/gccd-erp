<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\wage;

use app\common\controller\BaseController;
use app\common\service\admin\ProcessWageService;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("工序工资设置")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class ProcessWage extends BaseController
{
    /**
    * @Apidoc\Title("检验列表")
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
        $where = [['is_delete', '=', 0]];
        $param = $this->params([
            'processes_code' => '',
            'processes_name' => '',
            'post_name' => '',
            'create_start_date' => '',
            'create_end_date' => '',
        ]);
        if ($param['processes_code'] !== '' && $param['processes_code'] !== null) {
            $where[] = ['processes_code', 'like', '%' . trim((string) $param['processes_code']) . '%'];
        }
        if ($param['processes_name'] !== '' && $param['processes_name'] !== null) {
            $where[] = ['processes_name', 'like', '%' . trim((string) $param['processes_name']) . '%'];
        }
        if ($param['post_name'] !== '' && $param['post_name'] !== null) {
            $where[] = ['post_name', 'like', '%' . trim((string) $param['post_name']) . '%'];
        }
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' 00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' 23:59:59'];
        }
        $data = ProcessWageService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = ProcessWageService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加工序")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        /*创建*/
        $param = $this->params(ProcessWageService::$edit_field);
        $data = ProcessWageService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(ProcessWageService::$edit_field);


        $data = ProcessWageService::edit($param['id'], $param);

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

        $data = ProcessWageService::dele($param['ids']);
        return success($data);
    }

    /**
     * 是否启动工资
     * @return \json
     */
    public function is_start()
    {
        $param = $this->params(['id' => '','status' => '']);
        /*修改订单的状态*/
        $data = ProcessWageService::is_start($param);

        return success($data);
    }
}
