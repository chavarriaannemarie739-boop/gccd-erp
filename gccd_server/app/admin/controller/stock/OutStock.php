<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\admin\controller\stock;

use app\common\controller\BaseController;
use app\common\model\admin\MaterialModel;
use app\common\service\admin\OutStockService;
use app\common\service\admin\TraceabilityService;
use app\common\service\admin\PublicService;
use app\common\validate\system\OutValidate;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("入库管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class OutStock extends BaseController
{
    /**
    * @Apidoc\Title("入库列表")
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
        $param = $this->params(OutStockService::$search_field);
            /*入库单号*/
        if (!empty($param['out_code'])) {
            $where[] = ['out_code', 'like', '%' . $param['out_code'] . '%'];
        }
        /*关联单号信息*/
        if (!empty($param['relation_code'])) {
            $where[] = ['relation_code', 'like', '%' . $param['relation_code'] . '%'];
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $tabWh = trim((string) ($param['warehouse'] ?? ''));
        $hasMatName = !empty($param['material_name']);
        $hasMatSpec = !empty($param['material_spec']);
        $hasMatModel = !empty($param['material_model']);
        $hasTab = ($tabWh === '配件' || $tabWh === '原材料');
        if ($hasMatName || $hasMatSpec || $hasMatModel || $hasTab) {
            $matQuery = (new MaterialModel())->where('is_delete', 0);
            if ($hasMatName) {
                $matQuery->whereLike('material_name', '%' . $param['material_name'] . '%');
            }
            if ($hasMatSpec) {
                $matQuery->whereLike('material_spec', '%' . $param['material_spec'] . '%');
            }
            if ($hasMatModel) {
                $matQuery->whereLike('material_model', '%' . $param['material_model'] . '%');
            }
            if ($tabWh === '配件') {
                $matQuery->whereLike('material_property', '%配件%');
            } elseif ($tabWh === '原材料') {
                $matQuery->where(function ($sub) {
                    $sub->whereLike('material_property', '%原材料%')
                        ->whereOr('material_property', 'like', '%圆钢%');
                });
            }
            $matIds = $matQuery->column('id');
            if (empty($matIds)) {
                $where[] = ['material_id', 'in', [0]];
            } else {
                $where[] = ['material_id', 'in', $matIds];
            }
        }
        if (!empty($param['material_batch'])) {
            $where[] = ['material_batch', 'like', '%' . $param['material_batch'] . '%'];
        }
        $data = OutStockService::list($where, $this->page(), $this->limit(), $this->order());
        return success($data);
    }
    /**
    * @Apidoc\Title("入库详情")
    * @Apidoc\Query(ref="app\common\model\MesOrderModel", field="id")
    * @Apidoc\Returned(ref="app\common\model\MesOrderModel")
    */
    public function info()
    {
        $param = $this->params(['id/d' => '']);
        $data = OutStockService::info($param['id']);
        return success($data);
    }
    /**
    * @Apidoc\Title("添加入库信息")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="title,content,is_disable")
    */
    public function add()
    {
        /*创建调试单*/
        $param = $this->params(['list' => '']);
//        $param = [
//            'out_code' => PublicService::getCode(2)['code'],
//            'content'=>[[
//            'out_num'=>10, //出库数量
//            'material_id'=>'1', //物料id
//            'relation_code'=>'', //非必填值 关联单号
//            'remark'=>'测试', //非必填值  备注
 //             'picker'=>''
//        ]]
//            ];
        $data = OutStockService::add($param['list']);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->params(OutStockService::$edit_field);

        $data = OutStockService::edit($param['id'], $param);

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

        $data = OutStockService::dele($param['ids']);
        return success($data);
    }

    /**
     * 审核出库单
     * @return \json
     */
    public function editStatus()
    {
        $param = $this->params(['id' => '','material_batch' => '']);
        validate(OutValidate::class)->scene('edit')->check($param);
        $data = OutStockService::editStatus($param);

        return success($data);
    }

    /**
     * 物料流简易追溯：计划单 / 领料单 / 出库单 关键字查询
     */
    public function traceMaterialFlow()
    {
        $param = $this->params(['keyword' => '']);
        $data = TraceabilityService::materialFlowByKeyword((string) ($param['keyword'] ?? ''));

        return success($data);
    }
}
