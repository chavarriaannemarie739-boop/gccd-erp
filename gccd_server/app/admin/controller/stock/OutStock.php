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
use app\common\service\utils\MaterialSearchHelper;
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
        /* [AI-2026-08] 出库 Tab → 标准仓类型并校验权限 */
        $scopeType = null;
        if ($tabWh !== '') {
            if ($tabWh === '原材料' || $tabWh === '圆钢') {
                $scopeType = \app\common\service\admin\WarehouseScopeService::TYPE_ROUND;
            } elseif ($tabWh === '配件') {
                $scopeType = \app\common\service\admin\WarehouseScopeService::TYPE_ACCESSORY;
            } elseif ($tabWh === '成品') {
                $scopeType = \app\common\service\admin\WarehouseScopeService::TYPE_PRODUCT;
            } else {
                $scopeType = \app\common\service\admin\WarehouseScopeService::normalizeType($tabWh);
            }
            if ($scopeType === null || !\app\common\service\admin\WarehouseScopeService::allows(
                \app\common\service\admin\WarehouseScopeService::ACTION_OUTBOUND,
                $scopeType
            )) {
                return success([
                    'count' => 0,
                    'pages' => 0,
                    'page' => $this->page(),
                    'limit' => $this->limit(),
                    'list' => [],
                ]);
            }
        }
        $hasMatName = !empty($param['material_name']);
        $hasManufacturer = !empty($param['manufacturer']);
        $hasMatSpec = !empty($param['material_spec']);
        $hasMatModel = !empty($param['material_model']);
        $roundFilters = MaterialSearchHelper::resolveRoundSteelFilters($param);
        $hasRoundFilters = MaterialSearchHelper::hasRoundSteelFilters($roundFilters);
        $hasTab = ($tabWh === '配件' || $tabWh === '原材料' || $tabWh === '成品' || $tabWh === '圆钢');
        if ($hasMatName || $hasManufacturer || $hasMatSpec || $hasMatModel || $hasRoundFilters || $hasTab) {
            $matQuery = (new MaterialModel())->alias('m')->where('m.is_delete', 0);
            if ($hasMatName) {
                $matQuery->whereLike('material_name', '%' . $param['material_name'] . '%');
            }
            /* [AI-2026-08] 厂商筛选与出库列表展示字段保持一致 */
            if ($hasManufacturer) {
                $matQuery->whereLike('manufacturer', '%' . $param['manufacturer'] . '%');
            }
            if ($hasMatSpec) {
                $matQuery->whereLike('material_spec', '%' . $param['material_spec'] . '%');
            }
            if ($hasMatModel) {
                $matQuery->whereLike('material_model', '%' . $param['material_model'] . '%');
            }
            if ($hasRoundFilters) {
                /* [AI-2026-08] 出库圆钢四字段组合检索 */
                MaterialSearchHelper::applyRoundSteelFilters($matQuery, $roundFilters, 'm');
                $matQuery->where('m.material_property', 'like', '%圆钢%');
            }
            if ($tabWh === '配件' || $scopeType === \app\common\service\admin\WarehouseScopeService::TYPE_ACCESSORY) {
                $matQuery->whereLike('material_property', '%配件%');
            } elseif ($tabWh === '原材料' || $tabWh === '圆钢' || $scopeType === \app\common\service\admin\WarehouseScopeService::TYPE_ROUND) {
                $matQuery->where(function ($sub) {
                    $sub->whereLike('material_property', '%原材料%')
                        ->whereOr('material_property', 'like', '%圆钢%');
                });
            } elseif ($tabWh === '成品' || $scopeType === \app\common\service\admin\WarehouseScopeService::TYPE_PRODUCT) {
                $matQuery->whereLike('material_property', '%成品%');
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
