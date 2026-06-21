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
use app\common\service\admin\ProductCountService;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("维修项列表")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class ProductCount extends BaseController
{
    /**
    * @Apidoc\Title("报工支数列表")
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
        $param = $this->params(ProductCountService::$search_field);
        if (!empty($param['repair_name'])) {
            $where[] = ['repair_name', 'like', '%' . $param['repair_name'] . '%'];
        }
        if (!empty($param['remark'])) {
            $where[] = ['remark', 'like', '%' . $param['remark'] . '%'];
        }
        if (!empty($param['parent_id'])) {
            $where[] = ['parent_id', '=', $param['parent_id']];
        }
        /*添加检索条件*/
        if (!empty($param['material_model'])) {
            $where[] = ['material_model', 'like', '%' . $param['material_model'] . '%'];
        }
        if (!empty($param['supplier'])) {
            $where[] = ['supplier', 'like', '%' . $param['supplier'] . '%'];
        }
        if (!empty($param['manufacturer'])) {
            $where[] = ['manufacturer', 'like', '%' . $param['manufacturer'] . '%'];
        }
        if (!empty($param['material_model'])) {
            $where[] = ['material_model', 'like', '%' . $param['material_model'] . '%'];
        }
        if (!empty($param['material_property'])) {
            $where[] = ['material_property', 'like', '%' . $param['material_property'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = ProductCountService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = ProductCountService::info($param['id']);
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
        $param = $this->params(ProductCountService::$edit_field);
        $data = ProductCountService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(ProductCountService::$edit_field);


        $data = ProductCountService::edit($param['id'], $param);

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

        $data = ProductCountService::dele($param['ids']);
        return success($data);
    }

    /**
     * @Apidoc\Title("大类列表")
     */
    public function category()
    {
        $data = ProductCountService::category();
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
        $data = ProductCountService::updatestatus($param);

        return success($data);
    }
}
