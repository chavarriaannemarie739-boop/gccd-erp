<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\check;

use app\common\controller\BaseController;
use app\common\service\admin\CheckService;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("检验项管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Check extends BaseController
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
        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(CheckService::$search_field);
            /*用户名*/
        if (!empty($param['check_code'])) {
            $where[] = ['check_code', 'like', '%' . $param['check_code'] . '%'];
        }
        if (!empty($param['check_name'])) {
            $where[] = ['check_name', 'like', '%' . $param['check_name'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = CheckService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = CheckService::info($param['id']);
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
        $param = $this->params(CheckService::$edit_field);
        $data = CheckService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(CheckService::$edit_field);


        $data = CheckService::edit($param['id'], $param);

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

        $data = CheckService::dele($param['ids']);
        return success($data);
    }
    /*工序创建检验项*/
    public function addCheck()
    {
        $param = $this->params(['processes_code' => '','check_code' => '','check_name' => '']);
        /*修改订单的状态*/
        $data = CheckService::addcheck($param);
        return success($data);
    }

    /**
     * 小程序质检
     * @return \json
     */
    public function check()
    {
        $params = $this->params(['user_id' => [],'check_code' => [],'report_code' => '','repair_list' => [],'device_name' => '','device_code' => '']);
        $data = CheckService::check($params);
        return success($data);
    }
}
