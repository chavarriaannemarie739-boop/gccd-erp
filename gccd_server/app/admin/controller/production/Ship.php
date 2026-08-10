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
use app\common\service\admin\CheckService;
use app\common\service\admin\ShipService;
use app\common\service\utils\MaterialSearchHelper;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("发货管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Ship extends BaseController
{
    /**
    * @Apidoc\Title("发货单列表")
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
        $param = $this->params(ShipService::$search_field);
        $wh = [];
        if (!empty($param['material_name'])) {
            $wh[] = ['material_name', 'like', '%' . $param['material_name'] . '%'];
            $model = new MaterialModel();
            $data = $model->where($wh)->field('id')->select()->toArray();
            $data = array_column($data, 'id');
            $where[] = ['material_id', 'in', $data];
        }
        /*添加检索条件*/
        if (!empty($param['relation_code'])) {
            $where[] = ['relation_code', 'like', '%' . $param['relation_code'] . '%'];
        }
        if (!empty($param['ship_code'])) {
            $where[] = ['ship_code', 'like', '%' . $param['ship_code'] . '%'];
        }
        if (!empty($param['manufacturer'])) {
            $where[] = ['manufacturer', 'like', '%' . $param['manufacturer'] . '%'];
        }
        if (!empty($param['material_batch'])) {
            $where[] = ['material_batch', 'like', '%' . $param['material_batch'] . '%'];
        }
        if (!empty($param['material_num'])) {
            $where[] = ['material_num', '=', $param['material_num']];
        }
        if (!empty($param['customer_name'])) {
            $where[] = ['customer_name', 'like', '%' . $param['customer_name'] . '%'];
        }
        if (!empty($param['ship_date'])) {
            $where[] = ['ship_date', '=', $param['ship_date']];
        }
        /* [AI-2026-08] 发货圆钢四字段独立/组合检索 */
        $roundFilters = MaterialSearchHelper::resolveRoundSteelFilters($param);
        if (MaterialSearchHelper::hasRoundSteelFilters($roundFilters)) {
            $matchingCodes = MaterialSearchHelper::planCodesByRawMaterial(
                $roundFilters['spec'],
                $roundFilters['model'],
                $roundFilters['name'],
                $roundFilters['manufacturer']
            );
            if (empty($matchingCodes)) {
                $where[] = ['relation_code', '=', 'non-existent-code-to-force-empty-result'];
            } else {
                $where[] = ['relation_code', 'in', array_unique(array_filter($matchingCodes))];
            }
        }
        if (!empty($param['product_info'])) {
            $matchingCodes = MaterialSearchHelper::planCodesByFinishedKeyword((string) $param['product_info']);
            if (empty($matchingCodes)) {
                $where[] = ['relation_code', '=', 'non-existent-code-to-force-empty-result'];
            } else {
                $where[] = ['relation_code', 'in', array_unique(array_filter($matchingCodes))];
            }
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $data = ShipService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = ShipService::info($param['id']);
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
        $param = $this->params(ShipService::$edit_field);
        $data = ShipService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(ShipService::$edit_field);


        $data = ShipService::edit($param['id'], $param);

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

        $data = ShipService::dele($param['ids']);
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
        $data = ShipService::updatestatus($param);

        return success($data);
    }

    /**
     * @Apidoc\Title("获取客户历史列表")
     */
    public function customer_list()
    {
        $data = ShipService::customer_list();
        return success($data);
    }
}
