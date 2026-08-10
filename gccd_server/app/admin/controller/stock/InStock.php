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
use app\common\model\admin\WmsMaterialModel;
use app\common\service\admin\FinishedStockService;
use app\common\service\admin\InStockService;
use app\common\service\admin\MaterialStockThresholdService;
use app\common\service\admin\StockAlertService;
use app\common\service\admin\PublicService;
use app\common\service\utils\MaterialSearchHelper;
use app\Request;
use hg\apidoc\annotation as Apidoc;
use think\Filesystem;
use think\Model;

/**
 * @Apidoc\Title("入库管理")
 * @Apidoc\Group("content")
 * @Apidoc\Sort("250")
 */
class InStock extends BaseController
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
        $param = $this->params(InStockService::$search_field);
            /*入库单号*/
        if (!empty($param['in_code'])) {
            $where[] = ['in_code', 'like', '%' . $param['in_code'] . '%'];
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
        /*需要处理数据*/
        $wh = [];
        if (!empty($param['material_name'])) {
            $wh[] = ['material_name', 'like', '%' . $param['material_name'] . '%'];
        }
        /* [AI-2026-08] 厂商筛选与入库列表展示字段保持一致 */
        if (!empty($param['manufacturer'])) {
            $wh[] = ['manufacturer', 'like', '%' . $param['manufacturer'] . '%'];
        }
        if (!empty($param['material_spec'])) {
            $wh[] = ['material_spec','like','%' . $param['material_spec'] . '%'];
        }
        if (!empty($param['material_model'])) {
            $wh[] = ['material_model', 'like', '%' . $param['material_model'] . '%'];
        }
        if (!empty($param['material_batch'])) {
            $wh[] = ['material_batch','like','%' . $param['material_batch'] . '%'];
        }
        if (!empty($param['supplier'])) {
            $where[] = ['supplier', 'like', '%' . $param['supplier'] . '%'];
        }
        if (!empty($wh)) {
            $model = new MaterialModel();
            $data = $model->where($wh)->field('id')->select()->toArray();
            $data = array_column($data, 'id');
            $where[] = ['material_id', 'in', $data];
        }
        /* [AI-2026-08] 入库圆钢四字段独立/组合检索 */
        $roundFilters = MaterialSearchHelper::resolveRoundSteelFilters($param);
        if (MaterialSearchHelper::hasRoundSteelFilters($roundFilters)) {
            $roundQuery = (new MaterialModel())->alias('m')
                ->where('m.is_delete', 0)
                ->where('m.material_property', 'like', '%圆钢%');
            MaterialSearchHelper::applyRoundSteelFilters($roundQuery, $roundFilters, 'm');
            $where[] = ['material_id', 'in', $roundQuery->column('m.id') ?: [0]];
        }
        /*库位--代码*/
        if (!empty($param['location'])) {
            $where[] = ['location','like','%' . $param['location'] . '%'];
        }
        if (!empty($param['warehouse'])) {
            /* [AI-2026-08] 无对应入库仓权限时直接空结果 */
            if (!\app\common\service\admin\WarehouseScopeService::tabAllowed(
                \app\common\service\admin\WarehouseScopeService::ACTION_INBOUND,
                (string) $param['warehouse']
            )) {
                return success([
                    'count' => 0,
                    'pages' => 0,
                    'page' => $this->page(),
                    'limit' => $this->limit(),
                    'list' => [],
                ]);
            }
            $likes = InStockService::warehouseSearchOrLikesFromTabKeyword($param['warehouse']);
            if ($likes !== null && $likes !== []) {
                $where[] = static function ($query) use ($likes) {
                    $query->where(function ($q) use ($likes) {
                        $q->where('warehouse', 'like', $likes[0]);
                        for ($i = 1, $n = count($likes); $i < $n; $i++) {
                            $q->whereOr('warehouse', 'like', $likes[$i]);
                        }
                    });
                };
            } else {
                $where[] = ['warehouse', 'like', '%' . $param['warehouse'] . '%'];
            }
        }
        $data = InStockService::list($where, $this->page(), $this->limit(), $this->order());
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
        $data = InStockService::info($param['id']);
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
        $list = $param['list'] ?? [];
        if (!is_array($list)) {
            return error('参数错误');
        }
        $warehouse = (string) ($list['warehouse'] ?? '');
        if ($warehouse !== '' && preg_match('/成品/u', $warehouse)) {
            return error('成品仓库不允许手动新增入库，成品入库由酸洗工序报工自动生成');
        }
        /* [AI-2026-08] 入库写入校验仓库权限 */
        if ($warehouse !== '' && !\app\common\service\admin\WarehouseScopeService::tabAllowed(
            \app\common\service\admin\WarehouseScopeService::ACTION_INBOUND,
            $warehouse
        )) {
            return error('无该仓库的入库权限');
        }
//        return success($param);
//        $param = [
//            'in_code' => '',
//            'content'=>[
//                [
//                    'in_num'=>10, //入库数量
//                    'material_id'=>'1', //物料id
//                    'relation_code'=>'', //非必填值--关联单号
//                    'remark'=>'测试', //非必填值
//                    'warehouse'=>'', //仓库
//                    'location'=>'',  //库位
//                    'money'=>'20',  //单价
//                    'sum'=>'200'    //总额
//
//                ]
//            ]
//        ];
        $data = InStockService::add($list);

        return success($data);
    }

    /**
    * @Apidoc\Title("修改")
    * @Apidoc\Method("POST")
    * @Apidoc\Param(ref="app\common\model\MesOrderModel", field="id,title,content,is_disable")
    */
    public function edit()
    {
        $param = $this->param('list');
        if (!is_array($param)) {
            return error('参数错误：需要 list 对象');
        }

        $data = InStockService::edit($param);

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
        $data = InStockService::dele($param['ids']);
        return success($data);
    }

    /**
     * 仓库物料列表
     * @return \json
     */
    public function mateiallist()
    {
        $model = new MaterialModel();
        $where = [['is_delete','=','0']];
        /*添加搜索条件*/
        $param = $this->params(InStockService::$search_field);
        /*入库单号*/
        $wh = [];
        if (!empty($param['material_batch'])) {
            $where[] = ['material_batch', 'like', '%' . $param['material_batch'] . '%'];
        }
        if (!empty($param['location'])) {
            $where[] = ['location', 'like', '%' . $param['location'] . '%'];
        }
        /* ya_wms_material.stock 多为「配件仓库/圆钢仓库」等，与 Tab「配件库/圆钢库」字面不一致；与入库 list 一致用 OR-LIKE */
        $warehouseTab = trim((string) ($param['warehouse'] ?? ''));
        if ($warehouseTab !== '') {
            /* [AI-2026-08] 库存 Tab 权限 */
            if (!\app\common\service\admin\WarehouseScopeService::tabAllowed(
                \app\common\service\admin\WarehouseScopeService::ACTION_INVENTORY,
                $warehouseTab
            )) {
                return success([
                    'count' => 0,
                    'pages' => 0,
                    'page' => $this->page(),
                    'limit' => $this->limit(),
                    'list' => [],
                    'alert_count' => 0,
                ]);
            }
            $tabToKeyword = [
                '配件库'   => '配件',
                '原料库'   => '圆钢',
                '圆钢库'   => '圆钢',
                '成品库'   => '成品',
            ];
            $keyword = $tabToKeyword[$warehouseTab] ?? $warehouseTab;
            $isProductTab = ($warehouseTab === '成品库')
                || (strpos($warehouseTab, '成品') !== false)
                || ($keyword === '成品');
            if ($isProductTab) {
                $where[] = ['stock', 'like', '%成品%'];
            } else {
                $likes = InStockService::warehouseSearchOrLikesFromTabKeyword($keyword);
                if ($likes !== null && $likes !== []) {
                    $where[] = static function ($query) use ($likes) {
                        $query->where(function ($q) use ($likes) {
                            $q->where('stock', 'like', $likes[0]);
                            for ($i = 1, $n = count($likes); $i < $n; $i++) {
                                $q->whereOr('stock', 'like', $likes[$i]);
                            }
                        });
                    };
                } else {
                    $where[] = ['stock', 'like', '%' . $warehouseTab . '%'];
                }
            }
        }
        /*起止日期*/
        if (!empty($param['create_start_date'])) {
            $where[] = ['create_time', '>=', $param['create_start_date'] . ' ' . '00:00:00'];
        }
        if (!empty($param['create_end_date'])) {
            $where[] = ['create_time', '<=', $param['create_end_date'] . ' ' . '23:59:59'];
        }
        /*需要处理数据*/
        if (
            !empty($param['material_name'])
            || !empty($param['manufacturer'])
            || !empty($param['material_spec'])
            || !empty($param['material_model'])
        ) {
            /* [AI-2026-08] 库存厂商筛选与展示字段保持一致 */
            if (!empty($param['manufacturer'])) {
                $wh[] = ['manufacturer', 'like', '%' . $param['manufacturer'] . '%'];
            }
            if (!empty($param['material_spec'])) {
                $wh[] = ['material_spec','like','%' . $param['material_spec'] . '%'];
            }
            if (!empty($param['material_model'])) {
                $wh[] = ['material_model', 'like', '%' . $param['material_model'] . '%'];
            }
            if (!empty($param['material_name'])) {
                $wh[] = ['material_name','like','%' . $param['material_name'] . '%'];
            }
            /*检索出对应物料ID信息*/
            $result = $model->field('id')->where($wh)->select()->toArray();
            $result = array_column($result, 'id');
            $where[] = ['material_id', 'in', $result];
        }
        /* [AI-2026-08] 库存圆钢四字段独立/组合检索 */
        $roundFilters = MaterialSearchHelper::resolveRoundSteelFilters($param);
        if (MaterialSearchHelper::hasRoundSteelFilters($roundFilters)) {
            $roundQuery = (new MaterialModel())->alias('m')
                ->where('m.is_delete', 0)
                ->where('m.material_property', 'like', '%圆钢%');
            MaterialSearchHelper::applyRoundSteelFilters($roundQuery, $roundFilters, 'm');
            $where[] = ['material_id', 'in', $roundQuery->column('m.id') ?: [0]];
        }
        if (!empty($param['stock_alert_only'])) {
            $kw = StockAlertService::warehouseKeywordFromTab($warehouseTab);
            $prop = $kw === '配件' ? '配件' : ($kw === '圆钢' ? '圆钢' : '');
            if ($prop !== '') {
                $alertIds = StockAlertService::alertMaterialIds($prop, $kw);
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
                $where[] = ['material_id', 'in', $alertIds];
            }
        }
        $data = InStockService::materiallist($where, $this->page(), $this->limit(), $this->order(), [], $warehouseTab);
        return success($data);
    }

    /**
     * 配件/圆钢库存预警汇总（菜单角标、页头提示）
     */
    public function stockAlertSummary()
    {
        return success(StockAlertService::summary());
    }

    /**
     * [AI-2026-08] 库存管理按物料与仓库范围批量设置最低库存。
     *
     * @return \json
     */
    public function batchUpdateStockThreshold()
    {
        $param = $this->params([
            'material_ids/a' => [],
            'warehouse' => '',
            'min_stock_qty' => null,
        ]);
        $data = MaterialStockThresholdService::batchUpdate(
            $param['material_ids'] ?? [],
            (string) ($param['warehouse'] ?? ''),
            $param['min_stock_qty'] ?? null
        );

        return success($data);
    }

    /*根据物料ID获取批次号*/
    public function getMaterialBatch()
    {
        $model = new WmsMaterialModel();
        $material_id = $this->param('material_id');
        /*获取物料ID*/
        $data = $model
            ->alias('a')
            ->leftJoin('ya_material b', 'a.material_id = b.id')
            ->field('a.material_id,a.material_batch,a.batch_balance,a.price,b.supplier,b.manufacturer,b.material_name,b.material_spec,b.material_model')
            ->where('material_id', $material_id)
            ->select()
            ->toArray();

        return success($data);
    }
    /*获取所有物料的批次信息*/
    public function getBatch()
    {
        $model = new WmsMaterialModel();
        /*获取物料ID*/
        $data = $model->alias('a')
            ->leftJoin('ya_material b', 'a.material_id = b.id')
            ->field('a.material_id,a.material_batch,a.batch_balance,a.price,b.supplier,b.manufacturer,b.material_name,b.material_spec,b.material_model')
            ->where('a.material_batch', '<>', null)
            ->select()
            ->toArray();

        return success($data);
    }

    /**
     * 成品库操作权限（转库存/清零）
     */
    public function stockActionPermissions()
    {
        return success(FinishedStockService::actionPermissions());
    }

    /**
     * 客户余量部分转库存
     */
    public function transferToStock()
    {
        $param = $this->params([
            'plan_code' => '',
            'transfer_qty' => '',
            'reason' => '',
        ]);
        $data = FinishedStockService::transferToStock($param);

        return success($data);
    }

    /**
     * 成品库归属清零（主管）
     */
    public function writeOffStock()
    {
        $param = $this->params([
            'plan_code' => '',
            'stock_owner' => '',
            'write_off_qty' => '',
            'reason' => '',
        ]);
        $data = FinishedStockService::writeOff($param);

        return success($data);
    }
}
