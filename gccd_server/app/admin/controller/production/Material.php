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
use app\common\service\admin\MaterialService;
use app\common\service\admin\StockAlertService;
use app\common\service\utils\MaterialSearchHelper;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("工序管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class Material extends BaseController
{
    /**
    * @Apidoc\Title("工序列表")
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
        /* [AI-2026-08] 保留通用物料接口，圆钢名称、厂商、材质、型号可独立组合筛选。 */
        $param = $this->params(MaterialService::$search_field);
        $emptyFields = [];
        $identityFields = ['material_name', 'manufacturer', 'material_spec', 'material_model'];
        foreach ($identityFields as $identityField) {
            $raw = $param[$identityField] ?? '';
            if ($raw === MaterialService::ROUND_STEEL_TREE_EMPTY) {
                $emptyFields[] = $identityField;
            }
        }
        if (!empty($param['material_spec']) && $param['material_spec'] !== MaterialService::ROUND_STEEL_TREE_EMPTY) {
            $where[] = [
                'material_spec',
                'like',
                '%' . MaterialSearchHelper::likeKeyword((string) $param['material_spec']) . '%',
            ];
        }
        if (!empty($param['material_name']) && $param['material_name'] !== MaterialService::ROUND_STEEL_TREE_EMPTY) {
            $where[] = [
                'material_name',
                'like',
                '%' . MaterialSearchHelper::likeKeyword((string) $param['material_name']) . '%',
            ];
        }
        if (!empty($param['material_model']) && $param['material_model'] !== MaterialService::ROUND_STEEL_TREE_EMPTY) {
            $where[] = [
                'material_model',
                'like',
                '%' . MaterialSearchHelper::likeKeyword((string) $param['material_model']) . '%',
            ];
        }
        if (!empty($param['supplier'])) {
            $where[] = ['supplier', 'like', '%' . $param['supplier'] . '%'];
        }
        if (!empty($param['manufacturer']) && $param['manufacturer'] !== MaterialService::ROUND_STEEL_TREE_EMPTY) {
            $where[] = [
                'manufacturer',
                'like',
                '%' . MaterialSearchHelper::likeKeyword((string) $param['manufacturer']) . '%',
            ];
        }
        if (!empty($param['material_thickness'])) {
            $where[] = ['material_thickness', 'like', '%' . $param['material_thickness'] . '%'];
        }
        /*计件单价等选料：仅圆钢+成品，排除配件（与 RoundSteelList / ProductList 数据源一致）*/
        if (!empty($param['material_scope']) && $param['material_scope'] === 'steel_and_product') {
            $where[] = ['material_property', 'in', ['圆钢', '成品']];
        } elseif (!empty($param['material_property'])) {
            $where[] = ['material_property', 'like', '%' . $param['material_property'] . '%'];
        }
        /* [AI-2026-08] 档案列表按仓库档案权限收窄 material_property */
        $allowedProps = \app\common\service\admin\WarehouseScopeService::materialPropertyFilter(
            \app\common\service\admin\WarehouseScopeService::ACTION_ARCHIVE
        );
        if ($allowedProps !== null) {
            if (!empty($param['material_property'])) {
                $reqProp = trim((string) $param['material_property']);
                $hit = false;
                foreach ($allowedProps as $ap) {
                    if ($ap !== '' && mb_strpos($reqProp, $ap) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return success([
                        'count' => 0,
                        'pages' => 0,
                        'page' => $this->page(),
                        'limit' => $this->limit(),
                        'list' => [],
                    ]);
                }
            } else {
                $where[] = ['material_property', 'in', $allowedProps];
            }
        }
        /*
        if (!empty($param['stock'])) {
            $where[] = ['stock', '=', $param['stock']];
        }
        */
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        $materialProperty = '';
        if (!empty($param['material_property'])) {
            $materialProperty = trim((string) $param['material_property']);
        }
        if (!empty($param['stock_alert_only']) && in_array($materialProperty, ['配件', '圆钢'], true)) {
            $kw = StockAlertService::warehouseKeywordFromProperty($materialProperty);
            $alertIds = StockAlertService::alertMaterialIds($materialProperty, $kw);
            if ($alertIds === []) {
                return success([
                    'count' => 0,
                    'pages' => 0,
                    'page' => $this->page(),
                    'limit' => $this->limit(),
                    'list' => [],
                    'alert_count' => 0,
                ]);
            }
            $where[] = ['id', 'in', $alertIds];
        }
        $data = MaterialService::list($where, $this->page(), $this->limit(), $this->order(), [], $emptyFields);
        if (in_array($materialProperty, ['配件', '圆钢'], true)) {
            $kw = StockAlertService::warehouseKeywordFromProperty($materialProperty);
            $data['alert_count'] = count(StockAlertService::alertMaterialIds($materialProperty, $kw));
        }
        return success($data);
    }

    /**
     * [AI-2026-08] 圆钢档案左树导航：按名称>厂商>材质>型号聚合。
     *
     * @Apidoc\Title("圆钢档案导航树")
     * @Apidoc\Method("POST")
     */
    public function roundSteelTree()
    {
        $allowedProps = \app\common\service\admin\WarehouseScopeService::materialPropertyFilter(
            \app\common\service\admin\WarehouseScopeService::ACTION_ARCHIVE
        );
        if ($allowedProps !== null && !in_array('圆钢', $allowedProps, true)) {
            return success(['tree' => [], 'total' => 0]);
        }

        return success(MaterialService::roundSteelTree());
    }
    /**
    * @Apidoc\Title("信息")
    * @Apidoc\Query(ref="app\common\model\MesOrderModel", field="id")
    * @Apidoc\Returned(ref="app\common\model\MesOrderModel")
    */
    public function info()
    {
        $param = $this->params(['id/d' => '']);
        $data = MaterialService::info($param['id']);
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
        $param = $this->params(MaterialService::$edit_field);
        $data = MaterialService::add($param);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        /* [AI-2026-08] 仅保留实际提交的白名单字段，使服务层可从原档案补全未传字段。 */
        $submitted = $this->request->param();
        $param = array_intersect_key($submitted, MaterialService::$edit_field);
        $data = MaterialService::edit($param['id'] ?? 0, $param);

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

        $data = MaterialService::dele($param['ids']);
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
        $data = MaterialService::updatestatus($param);

        return success($data);
    }
}
