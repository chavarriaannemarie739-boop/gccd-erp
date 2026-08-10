<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\InStockModel;
use app\common\model\admin\StockMaterialModel;
use app\common\model\admin\WmsMaterialModel;
use app\common\model\system\RoleModel;
use app\common\model\system\UserAttributesModel;
use app\common\model\system\UserModel;
use app\common\service\utils\MaterialSearchHelper;
use http\Client\Curl\User;
use think\facade\Db;
use think\facade\Filesystem;
use think\Model;

/**
 *
 */
class InStockService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'in_code' => '', //文章分类id
        'in_num' => '', //文章标题
        'material_id' => '',
        'remark' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'material_name' => '', //物料名称
        'manufacturer' => '', // [AI-2026-08] 厂商
        'material_spec' => '', //物料规格
        'in_code' => '', //入库编码
        'warehouse' => '',
        'location' => '',
        'material_batch' => '',
        'supplier' => '',
        'relation_code' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'material_model' => '',
        'y_material_name' => '',
        'y_material_manufacturer' => '',
        'y_material_spec' => '',
        'y_material_model' => '',
        'y_mateial_name' => '',
        'y_mateial_manufacturer' => '',
        'y_mateial_spec' => '',
        'y_mateial_model' => '',
        'stock_alert_only' => '',
    ];
    /**
     * 列表
     *
     * @param array  $where 条件
     * @param int    $page  页数
     * @param int    $limit 数量
     * @param array  $order 排序
     * @param string $field 字段
     *
     * @return array
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {

        $allowedWh = self::getUserWarehouseAllowedValues();
        if ($allowedWh !== []) {
            /* 多角色时合并可访问仓库，避免只认第一个角色导致圆钢 Tab 与限仓条件互斥 */
            $where[] = ['warehouse', 'in', $allowedWh];
        }
        $model = new InStockModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            $list = $model->with(['getMaterial'])->where($where)->order($order)->select()->toArray();
            $list = MaterialSearchHelper::enrichRoundSteelArchiveRows($list);
            return self::enrichFinishedWarehouseInboundListRows($list);
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getMaterial','getUser'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        $list = MaterialSearchHelper::enrichRoundSteelArchiveRows($list);
        $list = self::enrichFinishedWarehouseInboundListRows($list);
        /*为列表添加采购前的数据*/
        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 成品入库列表：按 relation_code 补「成品」三字段（计划 english_material）；
     * 若计划下能解析到任务单圆钢物料，则用其覆盖行上 material_*，与「圆钢名称/规格/型号」列一致。
     *
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private static function enrichFinishedWarehouseInboundListRows(array $list): array
    {
        $codes = [];
        foreach ($list as $row) {
            $wh = (string) ($row['warehouse'] ?? '');
            if (strpos($wh, '成品') === false) {
                continue;
            }
            $rc = trim((string) ($row['relation_code'] ?? ''));
            if ($rc !== '') {
                $codes[] = $rc;
            }
        }
        if ($codes === []) {
            return $list;
        }
        $map = self::planSteelAndFinishedMaterialsByRelationCodes($codes);
        foreach ($list as &$row) {
            $wh = (string) ($row['warehouse'] ?? '');
            if (strpos($wh, '成品') === false) {
                continue;
            }
            $rc = trim((string) ($row['relation_code'] ?? ''));
            if ($rc === '' || !isset($map[$rc])) {
                continue;
            }
            $b = $map[$rc];
            $row['finished_material_name'] = (string) ($b['yg_name'] ?? '');
            $row['finished_material_spec'] = (string) ($b['yg_spec'] ?? '');
            $row['finished_material_model'] = (string) ($b['yg_model'] ?? '');
            $hasCp = trim((string) ($b['cp_name'] ?? '')) !== ''
                || trim((string) ($b['cp_spec'] ?? '')) !== ''
                || trim((string) ($b['cp_model'] ?? '')) !== '';
            if ($hasCp) {
                $row['y_material_name'] = (string) ($b['cp_name'] ?? '');
                $row['y_material_manufacturer'] = (string) ($b['cp_manufacturer'] ?? '');
                $row['y_material_spec'] = (string) ($b['cp_spec'] ?? '');
                $row['y_material_model'] = (string) ($b['cp_model'] ?? '');
                $row['material_name'] = (string) ($b['cp_name'] ?? '');
                $row['material_spec'] = (string) ($b['cp_spec'] ?? '');
                $row['material_model'] = (string) ($b['cp_model'] ?? '');
            }
        }
        unset($row);

        return $list;
    }

    /**
     * 按计划单号：cp=计划明细+工单圆钢聚合，yg=计划 english_material(成品)
     *
     * @param string[] $codes production_plan_code / relation_code
     * @return array<string, array<string, string>>
     */
    private static function planSteelAndFinishedMaterialsByRelationCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static function ($c) {
            return is_string($c) ? trim($c) : '';
        }, $codes))));
        if ($codes === []) {
            return [];
        }
        $rows = Db::name('production')
            ->alias('p')
            ->leftJoin('material m_yg', 'p.english_material=m_yg.id')
            ->whereIn('p.production_plan_code', $codes)
            ->where('p.is_delete', 0)
            ->field(
                'p.production_plan_code AS relation_code,m_yg.material_name AS yg_name,'
                . 'm_yg.material_spec AS yg_spec,m_yg.material_model AS yg_model'
            )
            ->select()
            ->toArray();
        $roundSteelMap = MaterialSearchHelper::roundSteelMaterialsByPlanCodes($codes);
        $out = [];
        foreach ($rows as $row) {
            $rc = (string) ($row['relation_code'] ?? '');
            if ($rc === '') {
                continue;
            }
            $materials = $roundSteelMap[$rc] ?? [];
            $row['cp_name'] = self::aggregateMaterialField($materials, 'material_name');
            $row['cp_manufacturer'] = self::aggregateMaterialField($materials, 'manufacturer');
            $row['cp_spec'] = self::aggregateMaterialField($materials, 'material_spec');
            $row['cp_model'] = self::aggregateMaterialField($materials, 'material_model');
            $out[$rc] = $row;
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $materials
     * @param string $field
     * @return string
     */
    private static function aggregateMaterialField(array $materials, string $field): string
    {
        return implode(',', array_values(array_unique(array_filter(array_map(
            static fn (array $material): string => trim((string) ($material[$field] ?? '')),
            $materials
        ), static fn (string $value): bool => $value !== ''))));
    }

    /**
     * 信息
     *
     * @param int  $id   id
     * @param bool $exce 不存在是否抛出异常
     *
     * @return array|Exception
     */
    public static function info($id, $exce = true)
    {
        $info = null;

        if (empty($info)) {
            $model = new InStockModel();
            $info = $model->find($id);

            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的申请信息不存在');
                }
                return [];
            }
            /*检查入库单查找相同单号的数据*/
            $content = $model->with(['getMaterial'])->where('in_code', $info['in_code'])->select()->toArray();
            $info['content'] = MaterialSearchHelper::enrichRoundSteelArchiveRows($content);
            $info = $info->toArray();
        }
        /*生成调试单号*/
        return $info;
    }
    /**
     * 添加
     *
     * @param array $param 信息
     *
     * @return array|Exception
     */
    public static function add($param)
    {
        Db::startTrans();
        try {
            $model = new InStockModel();
            /* 如果没有传递计划单号，生成单号 */
            if (empty($param['in_code'])) {
                $codeResult = PublicService::getCode(1);
                // 检查返回类型
                if (is_array($codeResult) && isset($codeResult['code'])) {
                    $param['in_code'] = $codeResult['code'];
                } elseif (is_string($codeResult)) {
                    $param['in_code'] = $codeResult;
                } else {
                    throw new \Exception('生成单号失败：返回格式错误');
                }
            }
            /*获取入库人员的仓库信息*/
            if (empty($param['warehouse'])) {
                $warehouseResult = self::getUserWarehouse();
                // 检查返回类型
                if (is_array($warehouseResult)) {
                    $param['warehouse'] = $warehouseResult;
                } elseif (is_string($warehouseResult)) {
                    $param['warehouse'] = $warehouseResult; // 或者根据需求转换
                } else {
                    throw new \Exception('获取仓库信息失败');
                }
            }
            /* 创建入库单单填写出库信息 */
            // 首先检查 content 的结构
            if (empty($param['content']) || !is_array($param['content'])) {
                throw new \Exception('入库内容不能为空');
            }
            foreach ($param['content'] as $k => &$v) {
                // 确保 $v 是数组
                if (!is_array($v)) {
                    throw new \Exception("第 {$k} 条入库内容格式错误");
                }
                /*设置物料批次信息*/
                if ($param['warehouse'] == '成品仓库') {
                    // 成品入库强制使用计划单号/任务单号作为批次号
                    $v['material_batch'] = $param['relation_code'];
                } elseif (isset($param['material_batch']) && !empty($param['material_batch'])) {
                    $v['material_batch'] = $param['material_batch'];
                } else {
                    $v['material_batch'] =  PublicService::getCode(10)['code'];
                }
                if (isset($param['relation_code'])) {
                    $v['relation_code'] = $param['relation_code'];
                }
                $v['warehouse'] = $param['warehouse'] ?? '';
                $v['in_code'] = $param['in_code'];
                if ($v['in_num'] < 0 || empty($v['in_num'])) {
                    throw new \Exception('请检查入库数量为正常值');
                }
                $v['supplier'] = isset($v['supplier']) ? trim((string) $v['supplier']) : '';
                foreach (['material_name', 'material_spec', 'material_model', 'id'] as $rk) {
                    unset($v[$rk]);
                }
                $pk = $model->getPk();
                $v['create_time'] = datetime();
                if (!empty($param['inbound_date'])) {
                    $v['inbound_date'] = $param['inbound_date'];
                }
                $v['create_uid'] = user_id();
                $model->insert($v);
                /* 修改仓库中物料的数量 */
                self::AddmaterialNum($v['material_id'], $v['in_num'], $v['material_batch'], $v['warehouse'] ?? '');
            }
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('入库单创建失败:' . $e->getMessage());
        }
        return $param;
    }

     /**
     * 修改入库单：按 in_code 整单更新明细；支持改数量/金额/备注/仓与库位、新增行、删除行（删行会软删除并冲减库存）
     *
     * @param array $param 与前端 list 一致：in_code, warehouse, location, relation_code?, content[]
     *
     * @return array|Exception
     */
    public static function edit($param = [])
    {
        if (!is_array($param)) {
            exception('参数错误');
        }
        $inCode = trim((string) ($param['in_code'] ?? ''));
        if ($inCode === '') {
            exception('缺少入库单号');
        }
        $content = $param['content'] ?? null;
        if (!is_array($content) || $content === []) {
            exception('入库明细不能为空');
        }

        $model = new InStockModel();
        $pk = $model->getPk();
        $warehouseTop = isset($param['warehouse']) ? trim((string) $param['warehouse']) : '';
        $locationTop = isset($param['location']) ? trim((string) $param['location']) : '';
        $relationTop = isset($param['relation_code']) ? trim((string) $param['relation_code']) : '';

        $existingIds = $model->where('in_code', $inCode)->where('is_delete', 0)->column('id');
        if ($existingIds === []) {
            exception('入库单不存在：' . $inCode);
        }
        if ($relationTop === '') {
            $firstRow = $model->where('in_code', $inCode)->where('is_delete', 0)->find();
            $relationTop = $firstRow ? trim((string) ($firstRow['relation_code'] ?? '')) : '';
        }

        $submittedLineIds = [];

        Db::startTrans();
        try {
            foreach ($content as $k => $item) {
                if (!is_array($item)) {
                    throw new \Exception('第 ' . ((int) $k + 1) . ' 条明细格式错误');
                }
                $linePk = isset($item['id']) ? (int) $item['id'] : 0;
                $materialId = isset($item['material_id']) ? (int) $item['material_id'] : 0;

                $inNum = isset($item['in_num']) ? (float) $item['in_num'] : 0;
                if ($inNum < 0) {
                    throw new \Exception('入库数量不能为负数');
                }
                $money = isset($item['money']) ? $item['money'] : 0;
                $sum = isset($item['sum']) ? $item['sum'] : 0;
                $remark = isset($item['remark']) ? trim((string) $item['remark']) : '';
                $supplier = isset($item['supplier']) ? trim((string) $item['supplier']) : '';

                $row = null;
                if ($linePk > 0) {
                    $row = $model->where($pk, $linePk)->where('in_code', $inCode)->where('is_delete', 0)->find();
                }

                if ($row) {
                    $submittedLineIds[] = $linePk;
                    $oldNum = (float) $row['in_num'];
                    $batch = trim((string) ($row['material_batch'] ?? ''));
                    $oldMid = (int) $row['material_id'];
                    if ($materialId > 0 && $materialId !== $oldMid) {
                        throw new \Exception('不支持修改明细行的物料，请删除该行后重新添加');
                    }
                    $useWh = $warehouseTop !== '' ? $warehouseTop : (string) ($row['warehouse'] ?? '');
                    $delta = $inNum - $oldNum;
                    $upd = [
                        'in_num' => $inNum,
                        'money' => $money,
                        'sum' => $sum,
                        'remark' => $remark,
                        'warehouse' => $useWh,
                        'location' => $locationTop,
                        'update_uid' => user_id(),
                        'update_time' => datetime(),
                    ];
                    if ($supplier !== '') {
                        $upd['supplier'] = $supplier;
                    }
                    $resUp = $model->where($pk, $linePk)->update($upd);
                    if ($resUp === false) {
                        throw new \Exception('更新明细失败');
                    }
                    if (abs($delta) > 0.00001 && $batch !== '') {
                        self::AddmaterialNum((string) $oldMid, $delta, $batch, $useWh);
                    }
                    continue;
                }

                if ($linePk > 0) {
                    throw new \Exception('明细行不存在或不属于当前入库单');
                }

                if ($materialId < 1) {
                    throw new \Exception('新明细缺少物料 ID');
                }

                $lineWh = $warehouseTop !== '' ? $warehouseTop : self::getUserWarehouse();
                if ($lineWh === '' || $lineWh === null) {
                    throw new \Exception('请选择仓库');
                }
                $batch = PublicService::getCode(10)['code'];
                if ($lineWh === '成品仓库' && $relationTop !== '') {
                    $batch = $relationTop;
                } elseif (isset($param['material_batch']) && !empty($param['material_batch'])) {
                    $batch = $param['material_batch'];
                }

                $insert = [
                    'in_code' => $inCode,
                    'material_id' => $materialId,
                    'in_num' => $inNum,
                    'warehouse' => $lineWh,
                    'location' => $locationTop,
                    'material_batch' => $batch,
                    'money' => $money,
                    'sum' => $sum,
                    'remark' => $remark,
                    'supplier' => $supplier,
                    'create_time' => datetime(),
                    'create_uid' => user_id(),
                ];
                if ($relationTop !== '') {
                    $insert['relation_code'] = $relationTop;
                }
                $newId = $model->insertGetId($insert);
                if (empty($newId)) {
                    throw new \Exception('新增明细失败');
                }
                $submittedLineIds[] = (int) $newId;
                self::AddmaterialNum((string) $materialId, $inNum, $batch, $lineWh);
            }

            $toRemove = array_diff(array_map('intval', $existingIds), array_map('intval', $submittedLineIds));
            foreach ($toRemove as $rid) {
                $delRow = $model->where($pk, $rid)->where('in_code', $inCode)->where('is_delete', 0)->find();
                if (!$delRow) {
                    continue;
                }
                $batch = trim((string) ($delRow['material_batch'] ?? ''));
                $num = (float) $delRow['in_num'];
                $mid = (string) $delRow['material_id'];
                $wh = (string) ($delRow['warehouse'] ?? '');
                if ($batch !== '' && $num > 0.00001) {
                    self::AddmaterialNum($mid, -$num, $batch, $wh);
                }
                $model->where($pk, $rid)->update([
                    'is_delete' => 1,
                    'delete_uid' => user_id(),
                    'delete_time' => datetime(),
                ]);
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('入库单更新失败:' . $e->getMessage());
        }

        return $param;
    }
    /**
     * 删除
     *
     * @param array $ids  id
     * @param bool  $real 是否真实删除
     *
     * @return array|Exception
     */
    public static function dele($ids, $real = true)
    {
        $model = new InStockModel();
        $pk = $model->getPk();
        /*删除对应文件*/
        if ($real) {
            $res = $model->where($pk, 'in', $ids)->delete();
        } else {
            $update = delete_update();
            $res = $model->where($pk, 'in', $ids)->update($update);
        }
        if (empty($res)) {
            exception();
        }
        $update['ids'] = $ids;
        return $update;
    }
    /**
     * 修改仓库物料的数量
     * @param $param
     * @return InStockModel|array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function AddmaterialNum($material_id, $num, $material_batch, $warehouse = '')
    {
        $model = new StockMaterialModel();
        $data = $model
            ->where('material_id', $material_id)
            ->where('material_batch', $material_batch)
            ->where('is_delete', 0)
            ->find();
        /*不存在*/
        if (empty($data)) {
            $add = [
                'material_id' => $material_id,
                'material_num' => $num,
                'material_batch' => $material_batch,
                'stock' => $warehouse,
                'create_time' => datetime(),
                'create_uid' => user_id(),
            ];
            $model->save($add);
        } else {
            /*修改物料的数量*/
            $update_data = ['material_num' => (float)$data['material_num'] + (float)$num];
            // 如果传递了仓库信息且当前记录的stock为空，则更新stock字段
            if (!empty($warehouse) && empty($data['stock'])) {
                $update_data['stock'] = $warehouse;
            }
            $model->where('id', $data['id'])->update($update_data);
        }

        return $data;
    }

    /**
     * 获取非管理员用户是属于什么仓库
     * @return mixed|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    /**
     * 角色侧「仓库」与 ya_wms_in.warehouse 实际取值的多写法兼容（用于 IN 查询）
     *
     * @return string[]
     */
    /**
     * 入库列表 Tab / 短关键字 → 多个 LIKE，用于 OR（与 ya_wms_in.warehouse 多写法一致）
     *
     * @return string[]|null null 表示走默认单一 like %keyword%
     */
    public static function warehouseSearchOrLikesFromTabKeyword(string $keyword): ?array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }
        $needles = [
            '圆钢' => ['圆钢', '圆钢仓库', '圆钢库', '原料库', '原料仓库'],
            '配件' => ['配件', '配件仓库', '配件库'],
            '成品' => ['成品', '成品仓库', '成品库', '成品仓'],
        ];
        if (!isset($needles[$keyword])) {
            return null;
        }
        $likes = [];
        foreach ($needles[$keyword] as $n) {
            $likes[] = '%' . $n . '%';
        }
        return array_values(array_unique($likes));
    }

    private static function warehouseRoleMatchValues(string $w): array
    {
        $groups = [
            '配件仓库' => ['配件仓库', '配件库'],
            '配件库' => ['配件仓库', '配件库'],
            '圆钢仓库' => ['圆钢仓库', '圆钢库', '原料库', '原料仓库'],
            '圆钢库' => ['圆钢仓库', '圆钢库', '原料库', '原料仓库'],
            '原料库' => ['圆钢仓库', '圆钢库', '原料库', '原料仓库'],
            '原料仓库' => ['圆钢仓库', '原料仓库'],
            '成品仓库' => ['成品仓库', '成品库', '成品仓'],
            '成品库' => ['成品仓库', '成品库', '成品仓'],
            '成品仓' => ['成品仓', '成品仓库', '成品库'],
        ];

        return $groups[$w] ?? [$w];
    }

    /**
     * 当前用户允许查看的 ya_wms_in.warehouse 取值（多角色/菜单权限取并集）；空数组表示不限仓
     *
     * [AI-2026-08] 委托 WarehouseScopeService（入库动作）
     *
     * @return string[]
     */
    public static function getUserWarehouseAllowedValues(): array
    {
        $values = WarehouseScopeService::warehouseFilterValues(WarehouseScopeService::ACTION_INBOUND);
        if ($values === null) {
            return [];
        }

        return $values;
    }

    public static function getUserWarehouse()
    {
        $roleModel = new RoleModel();
        $userModel = new UserModel();
        $userAttrModel = new UserAttributesModel();
        $user_info = $userModel->find(user_id());
        $warehouse = '';
        /* 角色名称 => wms_in.warehouse 实际取值（与入库 Tab、历史角色名兼容） */
        $roleToWarehouse = [
            '配件仓库' => '配件仓库',
            '配件库' => '配件仓库',
            '圆钢仓库' => '圆钢仓库',
            '圆钢库' => '圆钢仓库',
            '原料库' => '圆钢仓库',
            '原料仓库' => '圆钢仓库',
            '成品仓库' => '成品仓库',
            '成品库' => '成品仓库',
            '成品仓' => '成品仓',
        ];
        if (($user_info['is_super'] ?? 0) != 1 && user_id() != 1) {
            $roleIds = $userAttrModel->where('user_id', user_id())->column('role_id');
            foreach ($roleIds as $rid) {
                $rid = (int) $rid;
                if ($rid <= 0) {
                    continue;
                }
                $roleRow = $roleModel->find($rid);
                if (!$roleRow) {
                    continue;
                }
                $roleName = (string) ($roleRow['role_name'] ?? '');
                if (isset($roleToWarehouse[$roleName])) {
                    $warehouse = $roleToWarehouse[$roleName];
                    break;
                }
            }
        }
        return $warehouse;
    }
    /**
     * 仓库物料列表
     * @param $where
     * @param $page
     * @param $limit
     * @param $order
     * @param $field
     * @return array
     */
    public static function materiallist($where = [], $page = 1, $limit = 10, $order = [], $field = [], $warehouseTab = '')
    {
        // 判断是否为成品仓库查询（兼容'成品库'和'成品仓库'）
        $is_product_warehouse = false;
        foreach ($where as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            if (isset($condition[0]) && $condition[0] === 'stock' && isset($condition[2])) {
                // 支持 like 和 = 两种条件
                if (strpos((string) $condition[2], '成品') !== false) {
                    $is_product_warehouse = true;
                    break;
                }
            }
        }

        // 成品仓库特殊处理：直接查询入库记录
        if ($is_product_warehouse) {
            return self::productWarehouseList($where, $page, $limit, $order);
        }

        // 其他仓库：查询库存表
        $model = new WmsMaterialModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            $list = $model->with(['getMaterial','getStock'])->where($where)->order($order)->select()->toArray();
            return MaterialSearchHelper::enrichRoundSteelArchiveRows($list);
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        /*对list的方法进行处理*/
        $list = $model->with(['getMaterial','getStock'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        $list = MaterialSearchHelper::enrichRoundSteelArchiveRows($list);
        foreach ($list as $k => &$v) {
            // 仅补充当前行对应批次的单价/总价；禁止把同物料多批次拼接到一行（会导致批次号、金额列出现超长逗号串）
            $batch = (string) ($v['material_batch'] ?? '');
            $reuslt = $model->getMoney($v['material_id'] ?? '', $batch);
            if (!empty($reuslt[0])) {
                $row0 = $reuslt[0];
                if (array_key_exists('money', $row0)) {
                    $v['money'] = $row0['money'];
                }
                if (array_key_exists('sum', $row0)) {
                    $v['sum'] = $row0['sum'];
                }
            }
        }
        $warehouseKeyword = StockAlertService::warehouseKeywordFromTab((string) $warehouseTab);
        if ($warehouseKeyword !== '') {
            $list = StockAlertService::enrichWmsListRows($list, $warehouseKeyword);
        }
        $alert_count = 0;
        if ($warehouseKeyword !== '') {
            $prop = $warehouseKeyword === '配件' ? '配件' : '圆钢';
            $alert_count = count(StockAlertService::alertMaterialIds($prop, $warehouseKeyword));
        }
        /*为列表添加采购前的数据*/
        return compact('count', 'pages', 'page', 'limit', 'list', 'alert_count');
    }

    /**
     * 与成品库虚拟列表同一套展示规则：圆钢信息列 / 成品信息列
     *
     * @param array $row 查询行需含 yg_name/yg_spec/yg_model、cp_name/cp_spec/cp_model
     * @return array<string,string>
     */
    private static function productWarehouseDisplayLabels(array $row): array
    {
        // 圆钢信息列：来自 product_task 侧物料（cp_*）
        $parts = [];
        if (!empty($row['cp_name'])) {
            $parts[] = $row['cp_name'];
        }
        if (!empty($row['cp_spec'])) {
            $parts[] = $row['cp_spec'];
        }
        if (!empty($row['cp_model'])) {
            $parts[] = $row['cp_model'];
        }
        $y_material_info = implode(' · ', $parts);
        $y_material_name = (string) ($row['cp_name'] ?? '');
        $y_material_manufacturer = (string) ($row['cp_manufacturer'] ?? '');
        $y_material_spec = (string) ($row['cp_spec'] ?? '');
        $y_material_model = (string) ($row['cp_model'] ?? '');

        // 成品信息列：来自 production.english_material（yg_*）
        $cpParts = [];
        if (!empty($row['yg_name'])) {
            $cpParts[] = $row['yg_name'];
        }
        if (!empty($row['yg_spec'])) {
            $cpParts[] = $row['yg_spec'];
        }
        if (!empty($row['yg_model'])) {
            $cpParts[] = $row['yg_model'];
        }
        $product_info = implode(' · ', $cpParts);

        return compact(
            'y_material_name',
            'y_material_manufacturer',
            'y_material_spec',
            'y_material_model',
            'y_material_info',
            'product_info'
        );
    }

    /**
     * 按计划单号批量解析「圆钢信息 / 成品信息」（发货单列表等补全用）
     *
     * @param string[] $codes relation_code / 生产计划单号
     * @return array<string, array{y_material_spec:string, product_info:string}>
     */
    public static function labelsForShipRelationCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static function ($c) {
            return is_string($c) ? trim($c) : '';
        }, $codes))));
        if ($codes === []) {
            return [];
        }
        $rows = self::planSteelAndFinishedMaterialsByRelationCodes($codes);
        $out = [];
        foreach ($rows as $rc => $row) {
            $out[$rc] = self::productWarehouseDisplayLabels($row);
        }

        return $out;
    }

    /**
     * 成品仓库列表（按生产计划单分组，汇总酸洗报工数量）
     * 批次号 = 计划单号 = relation_code (scjh-XXXX)
     * 圆钢信息 = 生产计划单的圆钢物料(name+spec+model)
     * 成品信息 = 生产任务单的成品物料(spec+model)
     * 入库数量 = 该计划单下酸洗工序(gx_0014)的报工数量合计
     */
    private static function productWarehouseList($where = [], $page = 1, $limit = 10, $order = [])
    {
        // [AI-2026-08] 去掉逐行发货相关子查询；物料 ID 缺失改由 expandOwnerRows 批量补全
        $sql = "
            SELECT 
                p.production_plan_code as plan_code,
                p.production_plan_name as production_plan_name,
                p.production_plan_code as material_batch,
                p.production_plan_code as relation_code,
                MAX(pt.material_id) as material_id,
                m_yg.material_name as yg_name,
                m_yg.material_spec as yg_spec,
                m_yg.material_model as yg_model,
                m_cp.material_spec as cp_spec,
                m_cp.material_model as cp_model,
                m_cp.material_name as cp_name,
                m_cp.manufacturer as cp_manufacturer,
                COALESCE(SUM(pr.report_num), 0) as in_num,
                0 as shipped_num,
                MAX(pr.create_time) as create_time
            FROM ya_production p
            LEFT JOIN ya_material m_yg ON p.english_material = m_yg.id
            LEFT JOIN ya_product_task pt ON pt.relation_code = p.production_plan_code AND pt.is_delete = 0
            LEFT JOIN ya_material m_cp ON pt.material_id = m_cp.id
                AND m_cp.is_delete = 0
                AND m_cp.material_property = '圆钢'
            LEFT JOIN ya_product_report pr ON pr.relation_code = pt.task_code 
                AND pr.processes_code = 'gx_0014' 
                AND pr.is_delete = 0
            WHERE p.is_delete = 0
        ";

        $params = [];
        $materialIds = null;

        // 搜索条件（$where 可能含 ThinkPHP 闭包，不可当下标数组访问）
        foreach ($where as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $field = $condition[0] ?? '';
            $op = strtolower((string) ($condition[1] ?? ''));
            $val = $condition[2] ?? null;
            if ($field === 'material_batch' && $val !== null && $val !== '') {
                $sql .= ' AND p.production_plan_code LIKE ?';
                // controller 已拼好 %keyword%，此处不再二次包裹
                $params[] = (string) $val;
            }
            if ($field === 'material_name' && $val !== null && $val !== '') {
                $like = (string) $val;
                if (strpos($like, '%') === false) {
                    $like = '%' . $like . '%';
                }
                $sql .= ' AND (m_yg.material_name LIKE ? OR m_cp.material_name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
            if ($field === 'material_id' && $op === 'in') {
                $materialIds = is_array($val) ? $val : [];
            }
        }

        if ($materialIds !== null) {
            $materialIds = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
            if ($materialIds === []) {
                return [
                    'count' => 0,
                    'pages' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'list' => [],
                    'alert_count' => 0,
                    'stockPermissions' => FinishedStockService::actionPermissions(),
                ];
            }
            $idPh = implode(',', array_fill(0, count($materialIds), '?'));
            $sql .= " AND (
                p.english_material IN ({$idPh})
                OR pt.material_id IN ({$idPh})
                OR EXISTS (
                    SELECT 1
                    FROM ya_production_detail pd_filter
                    WHERE pd_filter.production_plan_code = p.production_plan_code
                      AND pd_filter.is_delete = 0
                      AND pd_filter.material_id IN ({$idPh})
                )
            )";
            $params = array_merge($params, $materialIds, $materialIds, $materialIds);
        }

        $sql .= " GROUP BY p.production_plan_code, p.production_plan_name, p.english_material, 
                   m_yg.material_name, m_yg.material_spec, m_yg.material_model,
                   m_cp.material_spec, m_cp.material_model, m_cp.material_name, m_cp.manufacturer";
        // [AI-2026-06] 先取有酸洗报工的计划，再按归属拆行并过滤库存>0
        $sql .= " HAVING in_num > 0";
        $sql .= " ORDER BY create_time DESC";

        $baseList = Db::query($sql, $params);
        $displayMap = self::labelsForShipRelationCodes(array_column($baseList, 'relation_code'));

        // 处理数据格式（注意：english_material实际存的是成品物料，product_task.material_id存的是圆钢物料）
        foreach ($baseList as &$item) {
            $relationCode = (string) ($item['relation_code'] ?? '');
            $labels = $displayMap[$relationCode] ?? self::productWarehouseDisplayLabels($item);
            $item['y_material_name'] = $labels['y_material_name'];
            $item['y_material_manufacturer'] = $labels['y_material_manufacturer'];
            $item['y_material_spec'] = $labels['y_material_spec'];
            $item['y_material_model'] = $labels['y_material_model'];
            $item['y_material_info'] = $labels['y_material_info'];
            $item['product_info'] = $labels['product_info'];
            $item['in_num'] = round((float) $item['in_num'], 2);
            $item['shipped_num'] = 0;
        }
        unset($item);

        $expanded = FinishedStockService::expandOwnerRows($baseList);
        $count = count($expanded);
        $pages = $limit > 0 ? (int) ceil($count / $limit) : 0;
        $offset = ($page - 1) * $limit;
        $list = $limit > 0 ? array_slice($expanded, $offset, $limit) : $expanded;

        $alert_count = 0;
        $stockPermissions = FinishedStockService::actionPermissions();

        return compact('count', 'pages', 'page', 'limit', 'list', 'alert_count', 'stockPermissions');
    }
}
