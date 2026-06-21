<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +---------------------------------------------------------------------
namespace app\common\service\admin;

use app\common\model\admin\MaterialInventoryModel;
use app\common\model\admin\MaterialModel;
use think\facade\Db;
use app\common\service\admin\PublicService;

class MaterialInventoryService
{
    /** 穿孔→断钢消耗折算系数（报工吨数 / 0.97 = 圆钢消耗） */
    private const PROCESS_YIELD_RATIO = 0.97;

    public static $search_field = [
        'check_name' => '',
        'check_code' => '',
    ];

    /**
     * 盘点搜索字段
     */
    public static $inventory_search_field = [
        'material_name' => '',      // 物料名称
        'finished_product_spec' => '', // 成品型号（历史别名）
        'current_process_spec' => '',  // 当前加工型号（历史别名）
        'y_material_spec' => '',       // 圆钢规格
        'y_material_model' => '',      // 圆钢型号
        'c_material_spec' => '',       // 工序/成品规格
        'c_material_model' => '',      // 成品型号
        'process_name' => '',          // 工序名称
        'inventory_start_date' => '',  // 盘点开始日期
        'inventory_end_date' => '',    // 盘点结束日期
    ];

    /**
     * 盘点添加/修改字段
     */
    public static $inventory_edit_field = [
        'id' => '',                        // 盘点记录ID
        'material_id' => '',               // 物料ID
        'material_name' => '',             // 物料名称
        'finished_product_spec' => '',     // 成品型号规格
        'current_process_spec' => '',      // 当前加工型号规格
        'process_name' => '',              // 工序名称
        'day_shift_tonnage' => '',         // 白班吨数
        'night_shift_tonnage' => '',       // 夜班吨数
        'inventory_date' => '',            // 盘点日期
        'remark' => '',                    // 备注
    ];

    /**
     * 规格归一化：去空格、统一乘号/X/x → *、小数末尾去零
     * 如 "50 * 1.20" → "50*1.2"
     */
    public static function normalizeSpec(string $spec): string
    {
        $spec = trim($spec);
        $spec = str_replace([' ', 'X', 'ｘ', 'x', '×', '＊', '·', '．'], '*', $spec);
        $spec = preg_replace('/\*+/', '*', $spec);
        // 小数末尾去零：50*1.20 → 50*1.2
        $spec = preg_replace_callback('/\d+\.\d+/', function ($m) {
            return rtrim(rtrim($m[0], '0'), '.');
        }, $spec);
        return $spec;
    }

    /**
     * 从规格中提取 * 号后匹配值
     * 断钢："50*1.2" → "1.2"（长度）
     * 穿孔："51*5" → "5"（壁厚）
     */
    public static function extractSpecKey(string $spec): string
    {
        $spec = self::normalizeSpec($spec);
        $parts = explode('*', $spec);
        return count($parts) >= 2 ? trim($parts[1]) : $spec;
    }

    /**
     * 同一工序、同一圆钢下多规格行按期初吨数占比（断钢长度 / 穿孔壁厚等）
     */
    private static function calcProcessBaselineRatio(
        array $baseline,
        string $processName,
        int $stockMaterialId,
        string $materialName,
        string $matchSpec,
        string $matchModel,
        string $matchKey
    ): float {
        $specQtyMap = [];
        $totalBaseline = 0.0;
        foreach ($baseline as $bs) {
            if (($bs['process_name'] ?? '') !== $processName) {
                continue;
            }
            if ($stockMaterialId > 0) {
                if (intval($bs['material_id'] ?? 0) !== $stockMaterialId) {
                    continue;
                }
            } else {
                if (
                    trim($bs['material_name'] ?? '') !== trim($materialName)
                    || trim($bs['y_material_spec'] ?? '') !== trim($matchSpec)
                    || trim($bs['y_material_model'] ?? '') !== trim($matchModel)
                ) {
                    continue;
                }
            }
            $bsKey = self::extractSpecKey($bs['c_material_spec'] ?? '');
            $bsQty = floatval($bs['total_tonnage']);
            $specQtyMap[$bsKey] = ($specQtyMap[$bsKey] ?? 0.0) + $bsQty;
            $totalBaseline += $bsQty;
        }
        if ($totalBaseline <= 0.000001) {
            return count($specQtyMap) === 1 ? 1.0 : 0.0;
        }
        return ($specQtyMap[$matchKey] ?? 0.0) / $totalBaseline;
    }

    /**
     * 圆钢当日报工：仅按「类别+规格+型号」或 material_id 精确匹配，禁止 name+spec 汇总回退（避免同规格不同型号重复同一消耗）
     */
    private static function getTodayRoundSteelReport(
        string $code,
        int $mid,
        string $name,
        string $spec,
        string $model,
        array $todayReportIdx,
        array $todayVarietyReportIdx
    ): float {
        $spec = trim($spec);
        $model = trim($model);
        $name = trim($name);
        if (
            $name !== '' && $spec !== '' && $model !== ''
            && isset($todayVarietyReportIdx[$name][$spec][$model][$code])
        ) {
            return floatval($todayVarietyReportIdx[$name][$spec][$model][$code]);
        }
        if ($mid > 0 && isset($todayReportIdx[$mid][$code])) {
            return floatval($todayReportIdx[$mid][$code]);
        }
        return 0.0;
    }

    /**
     * 圆钢当日报工明细（与 getTodayRoundSteelReport 同一匹配规则）
     *
     * @return array<int, array{date: string, qty: float}>
     */
    private static function getTodayRoundSteelReportDetails(
        string $code,
        int $mid,
        string $name,
        string $spec,
        string $model,
        array $todayReportDetails,
        array $todayVarietyReportDetails
    ): array {
        $spec = trim($spec);
        $model = trim($model);
        $name = trim($name);
        if (
            $name !== '' && $spec !== '' && $model !== ''
            && !empty($todayVarietyReportDetails[$name][$spec][$model][$code])
        ) {
            return $todayVarietyReportDetails[$name][$spec][$model][$code];
        }
        if ($mid > 0 && !empty($todayReportDetails[$mid][$code])) {
            return $todayReportDetails[$mid][$code];
        }
        return [];
    }

    /**
     * @return array<int, array{date: string, qty: float}>
     */
    private static function pickTodayReportDetails(
        string $code,
        int $mid,
        string $name,
        string $spec,
        string $model,
        array $todayReportDetails,
        array $todayVarietyReportDetails,
        array $todaySpecModelReportDetails,
        array $todayNameReportDetails
    ): array {
        $spec = trim($spec);
        $model = trim($model);
        $name = trim($name);
        if (
            $name !== '' && $spec !== '' && $model !== ''
            && !empty($todayVarietyReportDetails[$name][$spec][$model][$code])
        ) {
            return $todayVarietyReportDetails[$name][$spec][$model][$code];
        }
        if ($spec !== '' && $model !== '' && !empty($todaySpecModelReportDetails[$spec][$model][$code])) {
            return $todaySpecModelReportDetails[$spec][$model][$code];
        }
        if ($mid > 0 && !empty($todayReportDetails[$mid][$code])) {
            return $todayReportDetails[$mid][$code];
        }
        if ($name !== '' && $spec !== '' && !empty($todayNameReportDetails[$name][$spec][$code])) {
            $qty = floatval($todayNameReportDetails[$name][$spec][$code]);
            if ($qty <= 0.000001) {
                return [];
            }
            return [['date' => '', 'qty' => round($qty, 3)]];
        }
        return [];
    }

    /**
     * @param array<int, array{date: string, qty: float}> $details
     * @return array<int, array{date: string, qty: float}>
     */
    private static function scaleTodayReportDetails(array $details, float $ratio, float $divisor = 1.0): array
    {
        if ($ratio <= 0.000001 || empty($details)) {
            return [];
        }
        $divisor = $divisor > 0.000001 ? $divisor : 1.0;
        $out = [];
        foreach ($details as $detail) {
            $qty = round(floatval($detail['qty'] ?? 0) / $divisor * $ratio, 3);
            if ($qty <= 0.000001) {
                continue;
            }
            $out[] = [
                'date' => (string) ($detail['date'] ?? ''),
                'qty'  => $qty,
            ];
        }
        return $out;
    }

    /**
     * 列表
     */
    public static function inventoryList($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new MaterialInventoryModel();
        if (empty($order)) {
            $order = ['inventory_date' => 'desc', 'create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->with(['getMaterial', 'getCreator'])->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getMaterial', 'getCreator'])
            ->where($where)
            ->page($page)
            ->limit($limit)
            ->order($order)
            ->select()
            ->toArray();
        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 盘点详情
     */
    public static function inventoryInfo($id, $exce = true)
    {
        $model = new MaterialInventoryModel();
        $info = $model->with(['getMaterial', 'getCreator'])->find($id);
        if (empty($info)) {
            if ($exce) {
                exception('当前id为:' . $id . '的盘点记录不存在');
            }
            return [];
        }
        return $info->toArray();
    }

    /**
     * 添加盘点记录
     */
    public static function addInventory($param)
    {
        Db::startTrans();
        try {
            $model = new MaterialInventoryModel();
            $pk = $model->getPk();

            if (empty($param['inventory_code'])) {
                $param['inventory_code'] = PublicService::getCode(10)['code'];
            }

            if (empty($param['inventory_date'])) {
                $param['inventory_date'] = datetime();
            }

            if (empty($param['material_name'])) {
                if (!empty($param['material_id'])) {
                    $materialModel = new MaterialModel();
                    $material = $materialModel->find($param['material_id']);
                    if (!empty($material)) {
                        $param['material_name'] = $material['material_name'];
                    }
                }
            }

            $dayTonnage = floatval($param['day_shift_tonnage'] ?? 0);
            $nightTonnage = floatval($param['night_shift_tonnage'] ?? 0);
            $param['total_tonnage'] = round($dayTonnage + $nightTonnage, 3);

            $param['confirmed'] = 1;
            $param['source'] = 'manual';
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();

            $id = $model->insertGetId($param);
            $param[$pk] = $id;

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }

    /**
     * 将「圆钢规格」与「断钢型号」合并为加工规格一列（表多数无 duangang_spec 列；报工用别的表，不受影响）
     */
    private static function mergeStocktakeProcessSpec(array $item): string
    {
        $y = trim($item['y_material_spec'] ?? '');
        $c = trim($item['c_material_spec'] ?? '');
        if ($c === '') {
            return $y;
        }
        if ($y === '') {
            return $c;
        }
        return $y . ' / ' . $c;
    }

    /**
     * 小程序/API 批量提交盘点数据
     */
    public static function batchSubmitStocktake($data)
    {
        Db::startTrans();
        try {
            $stocktakeDate = $data['stocktake_date'] ?? date('Y-m-d');
            $processName = $data['process_name'] ?? '';
            $remark = $data['remark'] ?? '';
            $items = $data['items'] ?? [];
            $userId = user_id() ?: ($data['user_id'] ?? 0);

            if (empty($items)) {
                exception('盘点明细不能为空');
            }

            // 调试日志：检查传入数据
            trace('[盘点提交] process_name: ' . $processName, 'info');
            trace('[盘点提交] items count: ' . count($items), 'info');

            $model = new MaterialInventoryModel();
            $insertData = [];

            foreach ($items as $index => $item) {
                $dayTonnage = floatval($item['day_shift_qty'] ?? 0);
                $nightTonnage = floatval($item['night_shift_qty'] ?? 0);

                // 归一化规格：确保格式一致以支持匹配
                $rawSpec = $item['c_material_spec'] ?? '';
                $normalizedSpec = $rawSpec ? self::normalizeSpec($rawSpec) : '';

                $yName = $item['y_material_name'] ?? '';
                $ySpec = $item['y_material_spec'] ?? '';
                $yModel = $item['y_material_model'] ?? '';
                $materialId = intval($item['material_id'] ?? 0);
                if ($processName === '圆钢') {
                    $resolvedMid = self::resolveRoundSteelMaterialId($yName, $ySpec, $yModel);
                    if ($resolvedMid > 0) {
                        $materialId = $resolvedMid;
                    }
                }

                $insertData[] = [
                    'inventory_code' => PublicService::getCode(10)['code'],
                    'inventory_date' => $stocktakeDate,
                    'process_name' => $processName,
                    'material_id' => $materialId,
                    'material_name' => $yName,
                    'y_material_spec' => $ySpec,
                    'y_material_model' => $yModel,
                    'c_material_spec' => $normalizedSpec, // 归一化后存入
                    'c_material_model' => $item['c_material_model'] ?? '',
                    'c_product_spec' => $item['c_product_spec'] ?? '',
                    'finished_product_spec' => $item['c_product_model'] ?? $item['c_material_model'] ?? '',
                    'day_shift_tonnage' => $dayTonnage,
                    'night_shift_tonnage' => $nightTonnage,
                    'total_tonnage' => round($dayTonnage + $nightTonnage, 3),
                    'remark' => $remark,
                    'source' => 'manual',
                    'confirmed' => 1,
                    'create_uid' => $userId,
                    'create_time' => datetime(),
                    'update_uid' => $userId,
                    'update_time' => datetime(),
                ];

                // 调试日志：第一条数据
                if ($index === 0) {
                    trace('[盘点提交] 示例数据: ' . json_encode($insertData[0]), 'info');
                }
            }

            // 提报前先软删除当天该工序已有的盘点记录，避免网络重复提交/快速点击导致数据双重写入翻倍
            Db::name('material_inventory')
                ->where('inventory_date', $stocktakeDate)
                ->where('process_name', $processName)
                ->where('is_delete', 0)
                ->update([
                    'is_delete' => 1,
                    'update_uid' => $userId,
                    'update_time' => datetime(),
                ]);

            $model->insertAll($insertData);

            $itemCount = count($insertData);
            Db::commit();
            return "盘点提报成功，已入库 {$itemCount} 条明细记录";
        } catch (\Exception $e) {
            Db::rollback();
            exception('提报失败:' . $e->getMessage());
        }
    }

    /**
     * 修改盘点记录
     */
    public static function editInventory($ids, $param = [])
    {
        Db::startTrans();
        try {
            $model = new MaterialInventoryModel();
            $pk = $model->getPk();

            unset($param[$pk], $param['ids'], $param['inventory_code']);

            $record = $model->find($ids);
            if (empty($record)) {
                exception('盘点记录不存在');
            }

            $dayTonnage = isset($param['day_shift_tonnage']) ? floatval($param['day_shift_tonnage']) : floatval($record['day_shift_tonnage']);
            $nightTonnage = isset($param['night_shift_tonnage']) ? floatval($param['night_shift_tonnage']) : floatval($record['night_shift_tonnage']);
            $param['total_tonnage'] = round($dayTonnage + $nightTonnage, 3);

            $param['update_uid'] = user_id();
            $param['update_time'] = datetime();

            $res = $model->where($pk, $ids)->update($param);
            if (empty($res)) {
                exception('修改失败');
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('修改失败:' . $e->getMessage());
        }

        $param['ids'] = $ids;
        return $param;
    }

    /**
     * 是否可作为智能推算期初的手动盘点（排除 import / auto）
     */
    private static function isManualInventorySource($source): bool
    {
        $source = trim((string)$source);
        if ($source === 'import' || $source === 'auto') {
            return false;
        }
        return $source === '' || $source === 'manual';
    }

    /**
     * 月台账类别：优先盘点记录的 material_name（溯源/凯亚等），避免下游工序 material_id 指到成品档案导致类别错乱
     */
    private static function resolveMonthlyLedgerCategory(array $stock, array $midCategoryMap): string
    {
        $known = ['溯源', '凯亚', '易合', '高齿', '马强济'];
        $name = trim($stock['material_name'] ?? '');

        foreach ($known as $cat) {
            if ($name === $cat) {
                return $cat;
            }
        }

        if (($stock['process_name'] ?? '') === '圆钢') {
            $identity = self::parseRoundSteelIdentity($stock);
            if ($identity['category'] !== '') {
                return $identity['category'];
            }
        }

        $mid = intval($stock['material_id'] ?? 0);
        if ($mid > 0 && isset($midCategoryMap[$mid])) {
            $mapped = trim($midCategoryMap[$mid]);
            foreach ($known as $cat) {
                if ($mapped === $cat) {
                    return $cat;
                }
            }
            if (($stock['process_name'] ?? '') === '圆钢' && $mapped !== '') {
                return $mapped;
            }
        }

        if (isset($midCategoryMap['name:' . $name])) {
            return $midCategoryMap['name:' . $name];
        }

        return $name;
    }

    /**
     * 盘点品种唯一键（工序 + 圆钢 + 工序规格）
     */
    private static function baselineItemKey(array $row): string
    {
        return implode("\x00", [
            $row['process_name'] ?? '',
            trim($row['material_name'] ?? ''),
            trim($row['y_material_spec'] ?? ''),
            trim($row['y_material_model'] ?? ''),
            self::normalizeSpec($row['c_material_spec'] ?? ''),
        ]);
    }

    /**
     * 圆钢品种身份：类别(溯源) + 规格(邯钢) + 型号(55/70)
     * 兼容历史 material_name = "邯钢｜70" 写法
     */
    private static function parseRoundSteelIdentity(array $row): array
    {
        $category = trim($row['material_name'] ?? '');
        $spec = trim($row['y_material_spec'] ?? '');
        $model = trim($row['y_material_model'] ?? '');

        if (preg_match('/^(.+)[｜|](.+)$/u', $category, $m)) {
            $left = trim($m[1]);
            $right = trim($m[2]);
            if ($spec === '' && $model === '') {
                $spec = $left;
                $model = $right;
            } elseif ($model === '') {
                $model = $right;
                if ($spec === '') {
                    $spec = $left;
                }
            }
            if ($category !== '溯源' && $category !== '凯亚') {
                $category = '溯源';
            }
        }

        if ($category === '' && $spec !== '') {
            $category = '溯源';
        }

        return ['category' => $category, 'spec' => $spec, 'model' => $model];
    }

    /**
     * 按规格+型号解析圆钢档案 material_id（避免多条盘点共用错误 id）
     */
    private static function resolveRoundSteelMaterialId(string $category, string $spec, string $model): int
    {
        if ($spec === '' || $model === '') {
            return 0;
        }
        $query = Db::name('material')
            ->where('is_delete', 0)
            ->where('material_property', '圆钢')
            ->where('material_spec', $spec)
            ->where('material_model', $model);
        if ($category !== '') {
            $query->where('material_name', $category);
        }
        return intval($query->value('id') ?: 0);
    }

    private static function normalizeRoundSteelRow(array $row): array
    {
        if (($row['process_name'] ?? '') !== '圆钢') {
            return $row;
        }
        $identity = self::parseRoundSteelIdentity($row);
        $row['material_name'] = $identity['category'];
        $row['y_material_spec'] = $identity['spec'];
        $row['y_material_model'] = $identity['model'];
        $resolvedMid = self::resolveRoundSteelMaterialId(
            $identity['category'],
            $identity['spec'],
            $identity['model']
        );
        if ($resolvedMid > 0) {
            $row['material_id'] = $resolvedMid;
        }
        return $row;
    }

    private static function calcResultDedupeKey(array $row): string
    {
        if (($row['process_name'] ?? '') === '圆钢') {
            $identity = self::parseRoundSteelIdentity($row);
            return '圆钢' . "\x00" . $identity['spec'] . "\x00" . $identity['model'];
        }
        return self::baselineItemKey($row);
    }

    private static function dedupeCalcResults(array $result): array
    {
        $seen = [];
        $out = [];
        foreach ($result as $item) {
            $item = self::normalizeRoundSteelRow($item);
            $key = self::calcResultDedupeKey($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 12 小时班次日历日（与入库 create_time 回退规则一致）
     */
    private static function shiftCalendarDateFromDatetime(?string $datetime): ?string
    {
        if (empty($datetime)) {
            return null;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts - 12 * 3600);
    }

    /**
     * 每个品种取最后一次手动盘点（inventory_date、create_time 倒序，不按工序统一某一天）
     */
    private static function buildLatestManualBaseline(string $calcDate): array
    {
        $processNames = ['圆钢', '断钢', '穿孔', '球化', '矫直', '酸洗', '次成品', '半成品'];
        $rows = Db::name('material_inventory')
            ->where('is_delete', 0)
            ->where('confirmed', 1)
            ->whereIn('process_name', $processNames)
            ->where('inventory_date', '<=', $calcDate)
            ->where(function ($q) {
                $q->where('source', 'manual')
                    ->whereOr('source', '')
                    ->whereOr('source', null);
            })
            ->order(['inventory_date' => 'desc', 'create_time' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        $seen = [];
        $baseline = [];
        foreach ($rows as $row) {
            if (!self::isManualInventorySource($row['source'] ?? '')) {
                continue;
            }
            $row = self::normalizeRoundSteelRow($row);
            $key = self::calcResultDedupeKey($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row['inventory_date'] = date('Y-m-d', strtotime($row['inventory_date']));
            $baseline[] = $row;
        }
        return $baseline;
    }

    /**
     * 月台账：补全月初快照里没有、但本月有业务发生的圆钢品种（上月结余记 0，不动已有期初）
     */
    private static function appendMonthlyMissingRoundSteelBaseline(
        array $baseline,
        string $monthStart,
        string $monthEnd,
        array $inboundRows,
        array $reportRows
    ): array {
        $existingKeys = [];
        foreach ($baseline as $row) {
            $row = self::normalizeRoundSteelRow($row);
            if (($row['process_name'] ?? '') === '圆钢') {
                $existingKeys[self::calcResultDedupeKey($row)] = true;
            }
        }

        $candidates = [];

        $pushCandidate = function (string $category, string $spec, string $model, int $mid = 0) use (&$candidates) {
            $spec = trim($spec);
            $model = trim($model);
            if ($spec === '' || $model === '') {
                return;
            }
            $row = self::normalizeRoundSteelRow([
                'process_name'     => '圆钢',
                'material_name'    => $category,
                'y_material_spec'  => $spec,
                'y_material_model' => $model,
                'material_id'      => $mid,
                'c_material_spec'  => '',
                'total_tonnage'    => 0,
            ]);
            $key = self::calcResultDedupeKey($row);
            if (!isset($candidates[$key])) {
                $candidates[$key] = $row;
            }
        };

        foreach ($inboundRows as $r) {
            $pushCandidate(
                trim($r['material_name'] ?? ''),
                trim($r['material_spec'] ?? ''),
                trim($r['material_model'] ?? ''),
                intval($r['material_id'] ?? 0)
            );
        }

        foreach ($reportRows as $r) {
            if (($r['processes_code'] ?? '') !== 'gx_0010') {
                continue;
            }
            $pushCandidate(
                trim($r['material_name'] ?? ''),
                trim($r['y_spec'] ?? ''),
                trim($r['y_model'] ?? ''),
                intval($r['material_id'] ?? 0)
            );
        }

        $manualRows = Db::name('material_inventory')
            ->where('is_delete', 0)
            ->where('process_name', '圆钢')
            ->where('inventory_date', '>=', $monthStart)
            ->where('inventory_date', '<=', $monthEnd)
            ->where(function ($q) {
                $q->where('source', 'manual')
                    ->whereOr('source', '')
                    ->whereOr('source', null);
            })
            ->select()
            ->toArray();

        foreach ($manualRows as $mr) {
            if (!self::isManualInventorySource($mr['source'] ?? '')) {
                continue;
            }
            $mr = self::normalizeRoundSteelRow($mr);
            $key = self::calcResultDedupeKey($mr);
            if (!isset($candidates[$key])) {
                $candidates[$key] = array_merge($mr, ['total_tonnage' => 0]);
            }
        }

        foreach ($candidates as $key => $row) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            $existingKeys[$key] = true;
            $baseline[] = $row;
        }

        return $baseline;
    }

    /**
     * 月台账期初去重：同一品种只保留一条（保留 total_tonnage 较大者作为上月结余）
     */
    private static function dedupeMonthlyBaseline(array $baseline): array
    {
        $map = [];
        foreach ($baseline as $row) {
            if (($row['process_name'] ?? '') === '圆钢') {
                $row = self::normalizeRoundSteelRow($row);
                $key = 'YG' . "\x00" . self::calcResultDedupeKey($row);
            } else {
                $key = 'OT' . "\x00" . self::baselineItemKey($row);
            }
            if (!isset($map[$key])) {
                $map[$key] = $row;
                continue;
            }
            $existQty = floatval($map[$key]['total_tonnage'] ?? 0);
            $newQty = floatval($row['total_tonnage'] ?? 0);
            if ($newQty > $existQty) {
                $map[$key]['total_tonnage'] = $newQty;
            }
        }
        return array_values($map);
    }

    /**
     * 月台账：补全断钢/穿孔/球化/矫直/酸洗——月初快照没有但本月有盘点或报工的品种（上月结余记 0）
     */
    private static function appendMonthlyMissingProcessBaseline(
        array $baseline,
        string $monthStart,
        string $monthEnd
    ): array {
        $existingKeys = [];
        foreach ($baseline as $row) {
            if (($row['process_name'] ?? '') === '圆钢') {
                $row = self::normalizeRoundSteelRow($row);
                $existingKeys[self::calcResultDedupeKey($row)] = true;
            } else {
                $existingKeys[self::baselineItemKey($row)] = true;
            }
        }

        $codeToProcess = [
            'gx_0010' => '断钢',
            'gx_0011' => '穿孔',
            'gx_0012' => '球化',
            'gx_0013' => '矫直',
            'gx_0014' => '酸洗',
        ];

        $pushRow = function (array $candidate) use (&$baseline, &$existingKeys) {
            $processName = $candidate['process_name'] ?? '';
            if ($processName === '' || $processName === '圆钢') {
                return;
            }
            $candidate['material_name'] = trim($candidate['material_name'] ?? '');
            $candidate['y_material_spec'] = trim($candidate['y_material_spec'] ?? '');
            $candidate['y_material_model'] = trim($candidate['y_material_model'] ?? '');
            $candidate['c_material_spec'] = self::normalizeSpec($candidate['c_material_spec'] ?? '');
            $candidate['material_id'] = intval($candidate['material_id'] ?? 0);
            $candidate['total_tonnage'] = 0;

            $key = self::baselineItemKey($candidate);
            if (isset($existingKeys[$key])) {
                return;
            }
            $existingKeys[$key] = true;
            $baseline[] = $candidate;
        };

        $manualRows = Db::name('material_inventory')
            ->where('is_delete', 0)
            ->whereIn('process_name', array_values($codeToProcess))
            ->where('inventory_date', '>=', $monthStart)
            ->where('inventory_date', '<=', $monthEnd)
            ->where(function ($q) {
                $q->where('source', 'manual')
                    ->whereOr('source', '')
                    ->whereOr('source', null);
            })
            ->select()
            ->toArray();

        foreach ($manualRows as $mr) {
            if (!self::isManualInventorySource($mr['source'] ?? '')) {
                continue;
            }
            $pushRow([
                'process_name'     => $mr['process_name'] ?? '',
                'material_name'    => $mr['material_name'] ?? '',
                'y_material_spec'  => $mr['y_material_spec'] ?? '',
                'y_material_model' => $mr['y_material_model'] ?? '',
                'c_material_spec'  => $mr['c_material_spec'] ?? '',
                'material_id'      => $mr['material_id'] ?? 0,
            ]);
        }

        return $baseline;
    }

    private static function fetchReportDailyIndex(string $minDate, string $maxDate): array
    {
        $idIndex = [];
        $nameIndex = [];
        $specModelIndex = [];
        $varietyIndex = [];
        if (strtotime($minDate) > strtotime($maxDate)) {
            return ['id' => $idIndex, 'name' => $nameIndex, 'spec_model' => $specModelIndex, 'variety' => $varietyIndex];
        }

        $reportRows = Db::name('product_report')
            ->alias('pr')
            ->join('material m', 'pr.material_id = m.id')
            ->where('pr.report_date', '>=', $minDate)
            ->where('pr.report_date', '<=', $maxDate)
            ->where('pr.is_delete', 0)
            ->whereNotNull('m.material_name')
            ->where('m.material_name', '<>', '')
            ->field('m.id as material_id, m.material_name, m.material_spec, m.material_model, pr.processes_code, pr.report_date, SUM(pr.report_num) as total_qty')
            ->group('m.id, m.material_name, m.material_spec, m.material_model, pr.processes_code, pr.report_date')
            ->select()
            ->toArray();

        foreach ($reportRows as $r) {
            $mid = intval($r['material_id']);
            $code = $r['processes_code'];
            $date = date('Y-m-d', strtotime($r['report_date']));
            $qty = floatval($r['total_qty']);
            if ($mid > 0) {
                $idIndex[$mid][$code][$date] = ($idIndex[$mid][$code][$date] ?? 0) + $qty;
            }
            $name = $r['material_name'] ?? '';
            $spec = trim((string) ($r['material_spec'] ?? ''));
            $model = trim((string) ($r['material_model'] ?? ''));
            $nameIndex[$name][$spec][$code][$date] = ($nameIndex[$name][$spec][$code][$date] ?? 0) + $qty;
            if ($spec !== '' && $model !== '') {
                $specModelIndex[$spec][$model][$code][$date] = ($specModelIndex[$spec][$model][$code][$date] ?? 0) + $qty;
            }
            if ($name !== '' && $spec !== '' && $model !== '') {
                $varietyIndex[$name][$spec][$model][$code][$date] = ($varietyIndex[$name][$spec][$model][$code][$date] ?? 0) + $qty;
            }
        }
        return ['id' => $idIndex, 'name' => $nameIndex, 'spec_model' => $specModelIndex, 'variety' => $varietyIndex];
    }

    /**
     * @param string $model 圆钢型号；非空时优先按「规格+型号」汇总（溯源/凯亚改报工后仍计入）
     */
    private static function sumReportDaily(array $dailyIndex, int $mid, string $code, string $startDate, string $endDate, string $name, string $spec, string $model = ''): float
    {
        if (strtotime($startDate) > strtotime($endDate)) {
            return 0;
        }
        $spec = trim($spec);
        $model = trim($model);
        $name = trim($name);
        // 圆钢推算：优先「类别+规格+型号」（如 溯源|巨能|50），避免改工单后漏计或串到凯亚
        if ($name !== '' && $spec !== '' && $model !== '' && !empty($dailyIndex['variety'][$name][$spec][$model][$code])) {
            $varietySum = 0.0;
            foreach ($dailyIndex['variety'][$name][$spec][$model][$code] as $d => $qty) {
                if ($d >= $startDate && $d <= $endDate) {
                    $varietySum += floatval($qty);
                }
            }
            return $varietySum;
        }
        if ($spec !== '' && $model !== '' && !empty($dailyIndex['spec_model'][$spec][$model][$code])) {
            $specModelSum = 0.0;
            foreach ($dailyIndex['spec_model'][$spec][$model][$code] as $d => $qty) {
                if ($d >= $startDate && $d <= $endDate) {
                    $specModelSum += floatval($qty);
                }
            }
            if ($specModelSum > 0.000001) {
                return $specModelSum;
            }
        }

        $sum = 0.0;
        if ($mid > 0 && !empty($dailyIndex['id'][$mid][$code])) {
            foreach ($dailyIndex['id'][$mid][$code] as $d => $qty) {
                if ($d >= $startDate && $d <= $endDate) {
                    $sum += floatval($qty);
                }
            }
        }
        if ($mid > 0 && $model !== '') {
            return $sum;
        }
        if ($sum > 0 || ($mid > 0 && !empty($dailyIndex['id'][$mid][$code]))) {
            return $sum;
        }
        if ($name !== '' && !empty($dailyIndex['name'][$name][$spec][$code])) {
            foreach ($dailyIndex['name'][$name][$spec][$code] as $d => $qty) {
                if ($d >= $startDate && $d <= $endDate) {
                    $sum += floatval($qty);
                }
            }
        }
        return $sum;
    }

    /**
     * 圆钢盘点/推算仅统计圆钢仓库入库（与 PC 入库列表「圆钢仓库」Tab：warehouse LIKE %圆钢%）
     */
    private static function applyRoundSteelInboundWarehouseFilter($query)
    {
        return $query->whereLike('wi.warehouse', '%圆钢%');
    }

    private static function fetchInboundDailyIndex(string $minDate, string $maxDate): array
    {
        $idIndex = [];
        $nameIndex = [];
        if (strtotime($minDate) > strtotime($maxDate)) {
            return ['id' => $idIndex, 'name' => $nameIndex];
        }

        $inboundStart = $minDate . ' 12:00:00';
        $inboundEnd = date('Y-m-d', strtotime($maxDate . ' +1 day')) . ' 11:59:59';

        $rows = self::applyRoundSteelInboundWarehouseFilter(
            Db::name('wms_in')
            ->alias('wi')
            ->join('material m', 'wi.material_id = m.id AND m.material_property = \'圆钢\'')
            ->where(function ($query) use ($minDate, $maxDate, $inboundStart, $inboundEnd) {
                $query->where(function ($q) use ($minDate, $maxDate) {
                    $q->whereNotNull('wi.inbound_date')
                        ->where('wi.inbound_date', '>=', $minDate)
                        ->where('wi.inbound_date', '<=', $maxDate);
                });
                $query->whereOr(function ($q) use ($inboundStart, $inboundEnd) {
                    $q->whereNull('wi.inbound_date')
                        ->where('wi.create_time', '>=', $inboundStart)
                        ->where('wi.create_time', '<=', $inboundEnd);
                });
            })
            ->where('wi.is_delete', 0)
            ->where('wi.status', 0)
        )
            ->field('m.id as material_id, m.material_name, m.material_spec, wi.inbound_date, wi.in_num, wi.create_time')
            ->select()
            ->toArray();

        foreach ($rows as $r) {
            $effDate = !empty($r['inbound_date'])
                ? date('Y-m-d', strtotime($r['inbound_date']))
                : self::shiftCalendarDateFromDatetime($r['create_time'] ?? '');
            if (!$effDate || $effDate < $minDate || $effDate > $maxDate) {
                continue;
            }
            $qty = floatval($r['in_num']);
            $mid = intval($r['material_id']);
            if ($mid > 0) {
                $idIndex[$mid][$effDate] = ($idIndex[$mid][$effDate] ?? 0) + $qty;
            }
            $name = $r['material_name'] ?? '';
            $spec = $r['material_spec'] ?? '';
            if ($name !== '' && $spec !== '') {
                $nameIndex[$name][$spec][$effDate] = ($nameIndex[$name][$spec][$effDate] ?? 0) + $qty;
            }
        }
        return ['id' => $idIndex, 'name' => $nameIndex];
    }

    /**
     * @param string $model 圆钢型号；非空且 mid>0 时仅按 material_id 汇总
     */
    private static function sumInboundDaily(array $dailyIndex, int $mid, string $startDate, string $endDate, string $name, string $spec, string $model = ''): float
    {
        if (strtotime($startDate) > strtotime($endDate)) {
            return 0;
        }
        $sum = 0;
        if ($mid > 0 && !empty($dailyIndex['id'][$mid])) {
            foreach ($dailyIndex['id'][$mid] as $d => $qty) {
                if ($d >= $startDate && $d <= $endDate) {
                    $sum += floatval($qty);
                }
            }
        }
        if ($mid > 0 && trim($model) !== '') {
            return $sum;
        }
        if ($sum > 0 || ($mid > 0 && !empty($dailyIndex['id'][$mid]))) {
            return $sum;
        }
        if ($name !== '' && $spec !== '' && !empty($dailyIndex['name'][$name][$spec])) {
            foreach ($dailyIndex['name'][$name][$spec] as $d => $qty) {
                if ($d >= $startDate && $d <= $endDate) {
                    $sum += floatval($qty);
                }
            }
        }
        return $sum;
    }

    private static function fetchShipDailyIndex(string $minDate, string $maxDate): array
    {
        $idIndex = [];
        if (strtotime($minDate) > strtotime($maxDate)) {
            return ['id' => $idIndex];
        }

        $inboundStart = $minDate . ' 12:00:00';
        $inboundEnd = date('Y-m-d', strtotime($maxDate . ' +1 day')) . ' 11:59:59';
        $hasShipDateCol = self::hasShipDateColumn();

        $shipQuery = Db::name('ship')
            ->alias('s')
            ->join('material m', 's.material_id = m.id')
            ->where('s.is_delete', 0)
            ->where('s.status', 0);

        if ($hasShipDateCol) {
            $shipQuery->where('s.ship_date', '>=', $minDate)
                ->where('s.ship_date', '<=', $maxDate);
            $shipRows = $shipQuery
                ->field('m.id as material_id, s.ship_date as eff_date, s.material_num')
                ->select()
                ->toArray();
            foreach ($shipRows as $r) {
                $effDate = date('Y-m-d', strtotime($r['eff_date']));
                $mid = intval($r['material_id']);
                if ($mid > 0) {
                    $idIndex[$mid][$effDate] = ($idIndex[$mid][$effDate] ?? 0) + floatval($r['material_num']);
                }
            }
        } else {
            $shipRows = $shipQuery
                ->where('s.create_time', '>=', $inboundStart)
                ->where('s.create_time', '<=', $inboundEnd)
                ->field('m.id as material_id, s.create_time, s.material_num')
                ->select()
                ->toArray();
            foreach ($shipRows as $r) {
                $effDate = self::shiftCalendarDateFromDatetime($r['create_time'] ?? '');
                if (!$effDate || $effDate < $minDate || $effDate > $maxDate) {
                    continue;
                }
                $mid = intval($r['material_id']);
                if ($mid > 0) {
                    $idIndex[$mid][$effDate] = ($idIndex[$mid][$effDate] ?? 0) + floatval($r['material_num']);
                }
            }
        }
        return ['id' => $idIndex];
    }

    private static function sumShipDaily(array $dailyIndex, int $mid, string $startDate, string $endDate): float
    {
        if (strtotime($startDate) > strtotime($endDate) || $mid <= 0 || empty($dailyIndex['id'][$mid])) {
            return 0;
        }
        $sum = 0;
        foreach ($dailyIndex['id'][$mid] as $d => $qty) {
            if ($d >= $startDate && $d <= $endDate) {
                $sum += floatval($qty);
            }
        }
        return $sum;
    }

    /**
     * 批量加载物料档案（新入库圆钢补全名称规格用）
     *
     * @param int[] $materialIds
     * @return array<int, array>
     */
    private static function loadMaterialBriefMap(array $materialIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if (empty($ids)) {
            return [];
        }
        $rows = Db::name('material')
            ->whereIn('id', $ids)
            ->where('is_delete', 0)
            ->field('id, material_name, material_spec, material_model, material_property')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[intval($r['id'])] = $r;
        }
        return $map;
    }

    /**
     * 自动推算线边仓库存
     *
     * 核心逻辑：
     *   理论库存 = 该品种最后一次手动盘点 + 自期初次日起至目标日前一天的入库/产出 - 消耗
     *
     * 关键设计：
     *   - 自愈算法：每次实时算，不缓存，补报/删报不影响
     *   - 12小时日历：报工report_date=create_time-12h，入库/发货对齐同一日历
     *   - ship_date字段：发货有实际日期优先用，没有则按create_time+偏移
     *   - 匹配维度（v2）：按工序区分匹配键
     *      圆钢：material_id
     *      断钢：(圆钢ID, extractSpecKey(成品规格)) → 长度匹配
     *      穿孔+：(圆钢ID, extractSpecKey(成品规格)) → 壁厚匹配
     *
     * @param string $calcDate 目标日期（默认今天）
     * @return array
     */
    public static function autoCalculateInventory($calcDate = '')
    {
        if (empty($calcDate)) {
            $calcDate = date('Y-m-d');
        }
        $calcDate = date('Y-m-d', strtotime($calcDate));

        // Step 1: 每个品种各自最后一次手动盘点作为期初
        $baseline = self::buildLatestManualBaseline($calcDate);
        if (empty($baseline)) {
            return ['error' => '无盘点数据，请先手动盘点作为期初'];
        }

        $minBaselineDate = $calcDate;
        foreach ($baseline as $b) {
            $bd = $b['inventory_date'] ?? '';
            if ($bd && strtotime($bd) < strtotime($minBaselineDate)) {
                $minBaselineDate = $bd;
            }
        }

        // Step 2: 按日索引报工/入库/发货（各品种在推算时按自己的期初日期截取区间）
        $reportDaily = self::fetchReportDailyIndex($minBaselineDate, $calcDate);
        $inboundDaily = self::fetchInboundDailyIndex($minBaselineDate, $calcDate);
        $shipDaily = self::fetchShipDailyIndex($minBaselineDate, $calcDate);

        $globalEndDate = date('Y-m-d', strtotime($calcDate . ' -1 day'));
        $newRawStartDate = date('Y-m-d', strtotime($minBaselineDate . ' +1 day'));

        // ====== Step 3d: 今日发生数据（calcDate 当天，今日看板用） ======
        $todayReportIdx = [];
        $todayNameReportIdx = [];
        $todaySpecModelReportIdx = [];
        $todayVarietyReportIdx = [];
        $todayReportDetails = [];
        $todayVarietyReportDetails = [];
        $todaySpecModelReportDetails = [];
        $todayNameReportDetails = [];
        $todayInboundIdx = [];
        $todayShipIdx = [];

        if (strtotime($calcDate) <= strtotime(date('Y-m-d'))) {
            // 今日报工
            $todayReportRows = Db::name('product_report')
                ->alias('pr')
                ->join('material m', 'pr.material_id = m.id')
                ->where('pr.report_date', $calcDate)
                ->where('pr.is_delete', 0)
                ->whereNotNull('m.material_name')
                ->where('m.material_name', '<>', '')
                ->field('m.id as material_id, m.material_name, m.material_spec, m.material_model, pr.processes_code, SUM(pr.report_num) as total_qty')
                ->group('m.id, m.material_name, m.material_spec, m.material_model, pr.processes_code')
                ->select()
                ->toArray();

            foreach ($todayReportRows as $r) {
                $mid = intval($r['material_id']);
                $code = $r['processes_code'];
                $qty = floatval($r['total_qty']);
                if ($mid > 0) {
                    $todayReportIdx[$mid][$code] = ($todayReportIdx[$mid][$code] ?? 0) + $qty;
                }
                $name = $r['material_name'] ?? '';
                $spec = trim((string) ($r['material_spec'] ?? ''));
                $model = trim((string) ($r['material_model'] ?? ''));
                $todayNameReportIdx[$name][$spec][$code] = ($todayNameReportIdx[$name][$spec][$code] ?? 0) + $qty;
                if ($spec !== '' && $model !== '') {
                    $todaySpecModelReportIdx[$spec][$model][$code] = ($todaySpecModelReportIdx[$spec][$model][$code] ?? 0) + $qty;
                }
                if ($name !== '' && $spec !== '' && $model !== '') {
                    $todayVarietyReportIdx[$name][$spec][$model][$code] = ($todayVarietyReportIdx[$name][$spec][$model][$code] ?? 0) + $qty;
                }
            }

            // 今日报工明细（逐条，供当日消耗浮窗）
            $todayReportDetailRows = Db::name('product_report')
                ->alias('pr')
                ->join('material m', 'pr.material_id = m.id')
                ->where('pr.report_date', $calcDate)
                ->where('pr.is_delete', 0)
                ->whereNotNull('m.material_name')
                ->where('m.material_name', '<>', '')
                ->field('m.id as material_id, m.material_name, m.material_spec, m.material_model, pr.processes_code, pr.report_date, pr.report_num')
                ->order(['pr.report_date' => 'asc', 'pr.create_time' => 'asc', 'pr.id' => 'asc'])
                ->select()
                ->toArray();

            foreach ($todayReportDetailRows as $r) {
                $mid = intval($r['material_id']);
                $code = $r['processes_code'];
                $qty = floatval($r['report_num']);
                if ($qty <= 0.000001) {
                    continue;
                }
                $reportDate = date('Y-m-d', strtotime($r['report_date']));
                $entry = ['date' => $reportDate, 'qty' => round($qty, 3)];
                if ($mid > 0) {
                    $todayReportDetails[$mid][$code][] = $entry;
                }
                $name = $r['material_name'] ?? '';
                $spec = trim((string) ($r['material_spec'] ?? ''));
                $model = trim((string) ($r['material_model'] ?? ''));
                if ($name !== '' && $spec !== '') {
                    $todayNameReportDetails[$name][$spec][$code][] = $entry;
                }
                if ($spec !== '' && $model !== '') {
                    $todaySpecModelReportDetails[$spec][$model][$code][] = $entry;
                }
                if ($name !== '' && $spec !== '' && $model !== '') {
                    $todayVarietyReportDetails[$name][$spec][$model][$code][] = $entry;
                }
            }

            // 今日圆钢入库（仅圆钢仓库）
            $todayInboundRows = self::applyRoundSteelInboundWarehouseFilter(
                Db::name('wms_in')
                ->alias('wi')
                ->join('material m', 'wi.material_id = m.id AND m.material_property = \'圆钢\'')
                ->where(function ($q) use ($calcDate) {
                    $q->where(function ($q2) use ($calcDate) {
                        $q2->whereNotNull('wi.inbound_date')
                           ->where('wi.inbound_date', $calcDate);
                    });
                    $q->whereOr(function ($q2) use ($calcDate) {
                        $q2->whereNull('wi.inbound_date')
                           ->whereTime('wi.create_time', '>=', $calcDate . ' 12:00:00')
                           ->whereTime('wi.create_time', '<=', $calcDate . ' 23:59:59');
                    });
                })
                ->where('wi.is_delete', 0)
                ->where('wi.status', 0)
            )
                ->field('m.id as material_id, SUM(wi.in_num) as total_qty')
                ->group('m.id')
                ->select()
                ->toArray();

            foreach ($todayInboundRows as $r) {
                $todayInboundIdx[intval($r['material_id'])] = floatval($r['total_qty']);
            }

            // 今日发货
            $todayShipQuery = Db::name('ship')
                ->alias('s')
                ->join('material m', 's.material_id = m.id')
                ->where('s.is_delete', 0)
                ->where('s.status', 0);
            if (self::hasShipDateColumn()) {
                $todayShipQuery->where('s.ship_date', $calcDate);
            } else {
                $todayShipQuery->whereTime('s.create_time', '>=', $calcDate . ' 12:00:00')
                               ->whereTime('s.create_time', '<=', $calcDate . ' 23:59:59');
            }
            $todayShipRows = $todayShipQuery
                ->field('m.id as material_id, SUM(s.material_num) as total_qty')
                ->group('m.id')
                ->select()
                ->toArray();
            foreach ($todayShipRows as $r) {
                $todayShipIdx[intval($r['material_id'])] = floatval($r['total_qty']);
            }
        }

        // Helper: 读取今日报工（含 material_id 与 规格+型号 回退）
        $getTodayReport = function ($code, $mid, $name, $spec, $model = '') use (&$todayReportIdx, &$todayNameReportIdx, &$todaySpecModelReportIdx, &$todayVarietyReportIdx) {
            $spec = trim((string) $spec);
            $model = trim((string) $model);
            $name = trim((string) $name);
            if ($name !== '' && $spec !== '' && $model !== '' && isset($todayVarietyReportIdx[$name][$spec][$model][$code])) {
                return floatval($todayVarietyReportIdx[$name][$spec][$model][$code]);
            }
            if ($spec !== '' && $model !== '' && isset($todaySpecModelReportIdx[$spec][$model][$code])) {
                return floatval($todaySpecModelReportIdx[$spec][$model][$code]);
            }
            if ($mid > 0 && isset($todayReportIdx[$mid][$code])) {
                return $todayReportIdx[$mid][$code];
            }
            return $todayNameReportIdx[$name][$spec][$code] ?? 0;
        };

        // Step 4: 工序上下游配置
        $processFlow = [
            '圆钢' => ['current_code' => null, 'next_code' => 'gx_0010', 'type' => 'raw'],
            '断钢' => ['current_code' => 'gx_0010', 'next_code' => 'gx_0011', 'type' => 'process'],
            '穿孔' => ['current_code' => 'gx_0011', 'next_code' => 'gx_0012', 'type' => 'process'],
            '球化' => ['current_code' => 'gx_0012', 'next_code' => 'gx_0013', 'type' => 'process'],
            '矫直' => ['current_code' => 'gx_0013', 'next_code' => 'gx_0014', 'type' => 'process'],
            '酸洗' => ['current_code' => 'gx_0014', 'next_code' => null, 'type' => 'finish'],
        ];

        // Helper: 按material_name+spec+spec_key从fallback index取值
        $getFromFallback = function ($idx, $name, $spec, $specKey, $code = null) {
            if ($code !== null) {
                return isset($idx[$name][$spec][$specKey][$code]) ? floatval($idx[$name][$spec][$code]) : 0;
            }
            return isset($idx[$name][$spec][$specKey]) ? floatval($idx[$name][$spec][$specKey]) : 0;
        };

        // Step 4.5: 合并圆钢基线中同一物料的重复记录（防止同一物料多条基线重复推算）
        // 以 (material_name + y_material_spec + y_material_model) 为身份标识合并 total_tonnage
        $mergedBaseline = [];
        $seenBaselineKey = [];
        foreach ($baseline as $stock) {
            if ($stock['process_name'] === '圆钢') {
                $idKey = trim($stock['material_name'] ?? '') . "\x00"
                       . trim($stock['y_material_spec'] ?? '') . "\x00"
                       . trim($stock['y_material_model'] ?? '');
                if ($idKey === "\x00\x00") {
                    $mergedBaseline[] = $stock;
                } elseif (isset($seenBaselineKey[$idKey])) {
                    $mergedBaseline[$seenBaselineKey[$idKey]]['total_tonnage']
                        = floatval($mergedBaseline[$seenBaselineKey[$idKey]]['total_tonnage'])
                        + floatval($stock['total_tonnage']);
                } else {
                    $seenBaselineKey[$idKey] = count($mergedBaseline);
                    $mergedBaseline[] = $stock;
                }
            } else {
                $mergedBaseline[] = $stock;
            }
        }
        $baseline = $mergedBaseline;

        // Step 5: 逐条推算（v2：按工序维度匹配）
        $result = [];
        foreach ($baseline as $stock) {
            $processName = $stock['process_name'];
            $materialName = $stock['material_name'];
            $matchSpec = $stock['y_material_spec'];
            $matchModel = trim($stock['y_material_model'] ?? '');
            $stockMaterialId = intval($stock['material_id'] ?? 0);
            $baselineQty = floatval($stock['total_tonnage']);
            // 从该条盘点记录的 c_material_spec 提取匹配键
            $matchKey = self::extractSpecKey($stock['c_material_spec'] ?? '');

            if (!isset($processFlow[$processName])) {
                continue;
            }

            $flow = $processFlow[$processName];
            $predictedQty = $baselineQty;
            $calcDetail = [];

            // 该品种自己的期初日期与统计区间（期初次日 ~ 推算日前一天）
            $itemBaselineDate = !empty($stock['inventory_date']) ? date('Y-m-d', strtotime($stock['inventory_date'])) : '';
            $isTodayManual = ($itemBaselineDate !== '' && strtotime($itemBaselineDate) === strtotime($calcDate));
            $itemStartDate = $itemBaselineDate !== '' ? date('Y-m-d', strtotime($itemBaselineDate . ' +1 day')) : '';
            $itemEndDate = $globalEndDate;

            $hasMidTotal = $stockMaterialId > 0;

            // 同一圆钢下多规格行按期初占比（断钢历史推算；当日列对穿孔+工序亦用此比例）
            $todaySpecRatio = 1.0;
            $ratio = 0;
            if ($processName === '断钢') {
                $ratio = self::calcProcessBaselineRatio(
                    $baseline,
                    $processName,
                    $stockMaterialId,
                    $materialName,
                    $matchSpec,
                    $matchModel,
                    $matchKey
                );
                $todaySpecRatio = $ratio;
            } elseif (isset($processFlow[$processName])) {
                $todaySpecRatio = self::calcProcessBaselineRatio(
                    $baseline,
                    $processName,
                    $stockMaterialId,
                    $materialName,
                    $matchSpec,
                    $matchModel,
                    $matchKey
                );
            }

            if ($isTodayManual) {
                $predictedQty = $baselineQty;
                $calcDetail = [
                    'baseline'   => round($baselineQty, 3),
                    'note'       => '今日已有手动盘点，直接作为预测值，不走历史推算',
                    'match_type' => 'manual_today',
                ];
            } else {
                $reportQtyFn = function ($code) use ($reportDaily, $stockMaterialId, $itemStartDate, $itemEndDate, $materialName, $matchSpec, $matchModel, $processName) {
                    $modelArg = $processName === '圆钢' ? $matchModel : '';
                    return self::sumReportDaily($reportDaily, $stockMaterialId, $code, $itemStartDate, $itemEndDate, $materialName, $matchSpec, $modelArg);
                };

                if ($processName === '圆钢') {
                    $inbound = self::sumInboundDaily($inboundDaily, $stockMaterialId, $itemStartDate, $itemEndDate, $materialName, $matchSpec, $matchModel);
                    $duanGang = $reportQtyFn('gx_0010');
                    $predictedQty = $baselineQty + $inbound - $duanGang;
                    $calcDetail = [
                        'baseline'   => round($baselineQty, 3),
                        'baseline_date' => $itemBaselineDate,
                        'period_start' => $itemStartDate,
                        'period_end'   => $itemEndDate,
                        'inbound'    => round($inbound, 3),
                        'consume'    => round($duanGang, 3),
                        'match_type' => 'material_id+model',
                    ];
                } elseif ($processName === '断钢') {
                    // 产出 = 断钢报工总量
                    $outputQty = $reportQtyFn('gx_0010');
                    // 消耗 = 穿孔报工总量 ÷ 0.97（与月度台账、损耗公式一致）
                    $perfRaw = $reportQtyFn('gx_0011');
                    $totalConsume = $perfRaw > 0.000001
                        ? round($perfRaw / self::PROCESS_YIELD_RATIO, 3)
                        : 0.0;

                    $allocOutput = $outputQty * $ratio;
                    $allocConsume = $totalConsume * $ratio;

                    $predictedQty = $baselineQty + $allocOutput - $allocConsume;
                    $calcDetail = [
                        'baseline'       => round($baselineQty, 3),
                        'current_output' => round($allocOutput, 3),
                        'next_consume'   => round($allocConsume, 3),
                        'total_perf'     => round($perfRaw, 3),
                        'total_perf_adj' => round($totalConsume, 3),
                        'yield_ratio'    => self::PROCESS_YIELD_RATIO,
                        'proportion'     => round($ratio, 4),
                        'match_type'     => 'mid+prop',
                    ];
                } else {
                    $currentCode = $flow['current_code'];
                    $nextCode = $flow['next_code'];

                    $currentQty = $reportQtyFn($currentCode);
                    $nextQty = $nextCode ? $reportQtyFn($nextCode) : 0;

                    $predictedQty = $baselineQty + $currentQty - $nextQty;
                    $calcDetail = [
                        'baseline'       => round($baselineQty, 3),
                        'current_output' => round($currentQty, 3),
                        'next_consume'   => round($nextQty, 3),
                        'match_type'     => 'mid',
                    ];

                    // 酸洗（最后工序）：扣减发货出库
                    if ($flow['type'] === 'finish') {
                        $shipQty = self::sumShipDaily($shipDaily, $stockMaterialId, $itemStartDate, $itemEndDate);
                        $predictedQty -= $shipQty;
                        $calcDetail['ship_out'] = round($shipQty, 3);
                        $calcDetail['baseline_date'] = $itemBaselineDate;
                        $calcDetail['period_start'] = $itemStartDate;
                        $calcDetail['period_end'] = $itemEndDate;
                    }
                }
            }

            // ========== 今日发生（今日看板） ==========
            $todayIn = 0;
            $todayOut = 0;
            $todayConsume = 0;
            $todayConsumeDetails = [];
            if ($processName === '圆钢') {
                $todayIn = $hasMidTotal ? ($todayInboundIdx[$stockMaterialId] ?? 0) : 0;
                $todayConsume = self::getTodayRoundSteelReport(
                    'gx_0010',
                    $stockMaterialId,
                    $materialName,
                    $matchSpec,
                    $matchModel,
                    $todayReportIdx,
                    $todayVarietyReportIdx
                );
                $todayConsumeDetails = self::getTodayRoundSteelReportDetails(
                    'gx_0010',
                    $stockMaterialId,
                    $materialName,
                    $matchSpec,
                    $matchModel,
                    $todayReportDetails,
                    $todayVarietyReportDetails
                );
            } elseif ($processName === '断钢') {
                $todayOutTotal = $getTodayReport('gx_0010', $stockMaterialId, $materialName, $matchSpec);
                $todayPerfRaw = $getTodayReport('gx_0011', $stockMaterialId, $materialName, $matchSpec);
                $todayPerfAdj = $todayPerfRaw > 0.000001
                    ? round($todayPerfRaw / self::PROCESS_YIELD_RATIO, 3)
                    : 0.0;
                $todayOut = $todayOutTotal * $todaySpecRatio;
                $todayConsume = $todayPerfAdj * $todaySpecRatio;
                $todayConsumeDetails = self::scaleTodayReportDetails(
                    self::pickTodayReportDetails(
                        'gx_0011',
                        $stockMaterialId,
                        $materialName,
                        $matchSpec,
                        $matchModel,
                        $todayReportDetails,
                        $todayVarietyReportDetails,
                        $todaySpecModelReportDetails,
                        $todayNameReportDetails
                    ),
                    $todaySpecRatio,
                    self::PROCESS_YIELD_RATIO
                );
            } else {
                $code = $flow['current_code'];
                $nextCode = $flow['next_code'];
                $todayOutRaw = $getTodayReport($code, $stockMaterialId, $materialName, $matchSpec);
                $todayConsumeRaw = $nextCode ? $getTodayReport($nextCode, $stockMaterialId, $materialName, $matchSpec) : 0;
                $rawConsumeDetails = $nextCode
                    ? self::pickTodayReportDetails(
                        $nextCode,
                        $stockMaterialId,
                        $materialName,
                        $matchSpec,
                        $matchModel,
                        $todayReportDetails,
                        $todayVarietyReportDetails,
                        $todaySpecModelReportDetails,
                        $todayNameReportDetails
                    )
                    : [];
                if ($flow['type'] === 'finish') {
                    $todayShip = $stockMaterialId > 0 ? ($todayShipIdx[$stockMaterialId] ?? 0) : 0;
                    $todayConsumeRaw += $todayShip;
                    if ($todayShip > 0.000001) {
                        $rawConsumeDetails[] = ['date' => $calcDate, 'qty' => round($todayShip, 3)];
                    }
                }
                $todayOut = $todayOutRaw * $todaySpecRatio;
                $todayConsume = $todayConsumeRaw * $todaySpecRatio;
                $todayConsumeDetails = self::scaleTodayReportDetails($rawConsumeDetails, $todaySpecRatio);
            }
            $beginToday = round($predictedQty, 3); // predicted_qty = 昨日结存
            $todayPredicted = $beginToday + $todayIn + $todayOut - $todayConsume;

            $resultRow = [
                'process_name'       => $processName,
                'material_id'        => $stockMaterialId,
                'material_name'      => $materialName,
                'y_material_spec'    => $stock['y_material_spec'],
                'y_material_model'   => $stock['y_material_model'],
                'c_material_spec'    => ($stock['c_material_spec'] ?: $stock['c_product_spec'] ?: ''),
                'c_material_model'   => $stock['c_material_model'],
                'baseline_date'      => $itemBaselineDate,
                'baseline_qty'       => round($baselineQty, 3),
                'calc_start_date'    => $itemStartDate,
                'calc_end_date'      => $itemEndDate,
                'current_qty'        => $calcDetail['current_output'] ?? $calcDetail['inbound'] ?? 0,
                'consume_qty'        => $calcDetail['next_consume'] ?? $calcDetail['consume'] ?? 0,
                'predicted_qty'      => round($predictedQty, 3),
                'is_negative'        => $predictedQty < 0 ? 1 : 0,
                'calc_detail'        => json_encode($calcDetail, JSON_UNESCAPED_UNICODE),
                // 今日看板字段
                'begin_today'         => $beginToday,
                'today_inbound'       => round($todayIn, 3),
                'today_output'        => round($todayOut, 3),
                'today_consume'       => round($todayConsume, 3),
                'today_consume_details' => $todayConsumeDetails,
                'today_predicted'     => round($todayPredicted, 3),
                'has_today_activity'  => ($todayIn + $todayOut + $todayConsume) > 0,
            ];
            $result[] = self::normalizeRoundSteelRow($resultRow);
        }

        // ========== Step 6: 补充新入库圆钢（期初没有的品种） ==========
        // 查重：同时按 material_id 和 (material_name + y_material_spec) 去重，
        // 兼容历史数据 material_id=0 的情况
        $baselineMidMap = [];
        $baselineSpecModelMap = [];
        foreach ($baseline as $b) {
            if ($b['process_name'] === '圆钢') {
                $id = intval($b['material_id'] ?? 0);
                if ($id > 0) {
                    $baselineMidMap[$id] = true;
                }
                $spec = trim($b['y_material_spec'] ?? '');
                $model = trim($b['y_material_model'] ?? '');
                if ($spec !== '' && $model !== '') {
                    $baselineSpecModelMap[$spec . "\x00" . $model] = true;
                }
            }
        }

        // 新入库圆钢（期初无该品种）：从全局最早手动期初次日起至推算日前一天
        $newInboundMidSeen = [];
        $newInboundMaterialMap = self::loadMaterialBriefMap(array_keys($inboundDaily['id'] ?? []));
        if (strtotime($newRawStartDate) <= strtotime($globalEndDate) && !empty($inboundDaily['id'])) {
            foreach ($inboundDaily['id'] as $mid => $dateQty) {
                $mid = intval($mid);
                $matRow = $newInboundMaterialMap[$mid] ?? null;
                if (empty($matRow) || trim($matRow['material_name'] ?? '') === '') {
                    continue;
                }
                $rName = trim($matRow['material_name']);
                $rSpec = trim($matRow['material_spec'] ?? '');
                $rModel = trim($matRow['material_model'] ?? '');
                if ($rSpec !== '' && $rModel !== '' && isset($baselineSpecModelMap[$rSpec . "\x00" . $rModel])) {
                    continue;
                }
                if ($mid <= 0 || isset($baselineMidMap[$mid])) {
                    continue;
                }
                $inboundQty = self::sumInboundDaily($inboundDaily, $mid, $newRawStartDate, $globalEndDate, $rName, $rSpec, $rModel);
                if ($inboundQty <= 0) {
                    continue;
                }
                $duanGangConsume = self::sumReportDaily($reportDaily, $mid, 'gx_0010', $newRawStartDate, $globalEndDate, $rName, $rSpec, $rModel);
                $predicted = $inboundQty - $duanGangConsume;
                if ($predicted <= 0) {
                    continue;
                }
                $newInboundMidSeen[$mid] = true;
                $result[] = [
                    'process_name'     => '圆钢',
                    'material_id'      => $mid,
                    'material_name'    => $rName,
                    'y_material_spec'  => $rSpec,
                    'y_material_model' => $rModel,
                    'c_material_spec'  => '',
                    'c_material_model' => '',
                    'baseline_date'    => $minBaselineDate,
                    'baseline_qty'     => 0,
                    'calc_start_date'  => $newRawStartDate,
                    'calc_end_date'    => $globalEndDate,
                    'current_qty'      => round($inboundQty, 3),
                    'consume_qty'      => round($duanGangConsume, 3),
                    'predicted_qty'    => round($predicted, 3),
                    'is_negative'      => 0,
                    'is_new'           => 1,
                    'calc_detail'      => json_encode(['inbound' => round($inboundQty, 3), 'consume' => round($duanGangConsume, 3), 'note' => '新入库自动检出'], JSON_UNESCAPED_UNICODE),
                    'begin_today'         => round($predicted, 3),
                    'today_inbound'       => 0,
                    'today_output'        => 0,
                    'today_consume'       => 0,
                    'today_predicted'     => round($predicted, 3),
                    'has_today_activity'  => false,
                ];
            }
        }

        // 文本维度补充：仅处理 material_id=0 的历史入库（避免与上表重复）
        if (strtotime($newRawStartDate) <= strtotime($globalEndDate) && !empty($inboundDaily['name'])) {
            foreach ($inboundDaily['name'] as $rName => $specMap) {
                foreach ($specMap as $rSpec => $dateQty) {
                    if ($rName === '' || $rSpec === '') {
                        continue;
                    }
                    if ($rSpec !== '' && $rModel !== '' && isset($baselineSpecModelMap[$rSpec . "\x00" . $rModel])) {
                        continue;
                    }
                    $existMid = Db::name('material')
                        ->where('is_delete', 0)
                        ->where('material_name', $rName)
                        ->where('material_spec', $rSpec)
                        ->value('id');
                    if ($existMid && isset($newInboundMidSeen[intval($existMid)])) {
                        continue;
                    }
                    $inboundQty = self::sumInboundDaily($inboundDaily, 0, $newRawStartDate, $globalEndDate, $rName, $rSpec);
                    if ($inboundQty <= 0) {
                        continue;
                    }
                    $duanGangConsume = self::sumReportDaily($reportDaily, 0, 'gx_0010', $newRawStartDate, $globalEndDate, $rName, $rSpec);
                    $predicted = $inboundQty - $duanGangConsume;
                    if ($predicted <= 0) {
                        continue;
                    }
                    $result[] = [
                        'process_name'     => '圆钢',
                        'material_id'      => $existMid ? intval($existMid) : 0,
                        'material_name'    => $rName,
                        'y_material_spec'  => $rSpec,
                        'y_material_model' => '',
                        'c_material_spec'  => '',
                        'c_material_model' => '',
                        'baseline_date'    => $minBaselineDate,
                        'baseline_qty'     => 0,
                        'calc_start_date'  => $newRawStartDate,
                        'calc_end_date'    => $globalEndDate,
                        'current_qty'      => round($inboundQty, 3),
                        'consume_qty'      => round($duanGangConsume, 3),
                        'predicted_qty'    => round($predicted, 3),
                        'is_negative'      => 0,
                        'is_new'           => 1,
                        'calc_detail'      => json_encode(['inbound' => round($inboundQty, 3), 'consume' => round($duanGangConsume, 3), 'note' => '新入库自动检出'], JSON_UNESCAPED_UNICODE),
                        'begin_today'         => round($predicted, 3),
                        'today_inbound'       => 0,
                        'today_output'        => 0,
                        'today_consume'       => 0,
                        'today_predicted'     => round($predicted, 3),
                        'has_today_activity'  => false,
                    ];
                }
            }
        }

        // ========== Step 7: 次成品/半成品——取各品种最后一次手动盘点 ==========
        $historyTypes = ['次成品', '半成品'];
        $resultKeys = [];
        foreach ($result as $ri) {
            $resultKeys[self::baselineItemKey($ri)] = true;
        }
        foreach ($baseline as $hr) {
            if (!in_array($hr['process_name'] ?? '', $historyTypes, true)) {
                continue;
            }
            $key = self::baselineItemKey($hr);
            if (isset($resultKeys[$key])) {
                continue;
            }
            $resultKeys[$key] = true;
            $ht = $hr['process_name'];
                $qty = floatval($hr['total_tonnage']);
                $result[] = [
                    'process_name'     => $ht,
                    'material_name'    => $hr['material_name'] ?? '',
                    'category'         => $hr['material_name'] ?? '',
                    'y_material_spec'  => $hr['y_material_spec'] ?? '',
                    'y_material_model' => $hr['y_material_model'] ?? '',
                    'c_material_spec'  => $hr['c_material_spec'] ?? '',
                    'c_material_model' => $hr['c_material_model'] ?? '',
                    'baseline_date'    => $hr['inventory_date'] ?? '',
                    'baseline_qty'     => $qty,
                    'calc_start_date'  => '',
                    'calc_end_date'    => '',
                    'current_qty'      => 0,
                    'consume_qty'      => 0,
                    'predicted_qty'    => $qty,
                    'is_negative'      => $qty < 0 ? 1 : 0,
                    'is_new'           => 0,
                    'is_history'       => 1,
                    'calc_detail'      => json_encode(['note' => '数据延续-上次填报值'], JSON_UNESCAPED_UNICODE),
                    'begin_today'         => $qty,
                    'today_inbound'       => 0,
                    'today_output'        => 0,
                    'today_consume'       => 0,
                    'today_predicted'     => $qty,
                    'has_today_activity'  => false,
                ];
        }

        // ========== Step 8: 推算日当天手动盘点覆盖（同日再次提交后立即生效） ==========
        $manualOverlay = Db::name('material_inventory')
            ->where('is_delete', 0)
            ->where('inventory_date', $calcDate)
            ->where(function ($q) {
                $q->where('source', 'manual')
                    ->whereOr('source', '')
                    ->whereOr('source', null);
            })
            ->select()
            ->toArray();

        if (!empty($manualOverlay)) {
            $manualIdx = [];
            foreach ($manualOverlay as $mr) {
                if (!self::isManualInventorySource($mr['source'] ?? '')) {
                    continue;
                }
                $mr = self::normalizeRoundSteelRow($mr);
                $manualIdx[self::calcResultDedupeKey($mr)] = $mr;
            }

            // 遍历推算结果，匹配手动数据覆盖
            $covered = [];
            foreach ($result as &$item) {
                $item = self::normalizeRoundSteelRow($item);
                $key = self::calcResultDedupeKey($item);
                if (isset($manualIdx[$key])) {
                    $qty = round(floatval($manualIdx[$key]['total_tonnage']), 3);
                    $item['predicted_qty'] = $qty;
                    $item['today_predicted'] = $qty;
                    $item['begin_today'] = $qty;
                    $item['today_inbound'] = 0;
                    $item['today_output'] = 0;
                    $item['today_consume'] = 0;
                    $item['has_today_activity'] = false;
                    $item['is_manual'] = 1;
                    $covered[$key] = true;
                }
            }
            unset($item);

            // 补充手动数据中有但推算结果中没有的条目
            foreach ($manualIdx as $key => $mr) {
                if (isset($covered[$key])) {
                    continue;
                }
                $qty = round(floatval($mr['total_tonnage']), 3);
                if ($qty == 0) {
                    continue;
                }
                $result[] = [
                    'process_name'     => $mr['process_name'] ?? '',
                    'material_name'    => $mr['material_name'] ?? '',
                    'y_material_spec'  => $mr['y_material_spec'] ?? '',
                    'y_material_model' => $mr['y_material_model'] ?? '',
                    'c_material_spec'  => $mr['c_material_spec'] ?? '',
                    'c_material_model' => $mr['c_material_model'] ?? '',
                    'baseline_date'    => $calcDate,
                    'baseline_qty'     => $qty,
                    'calc_start_date'  => '',
                    'calc_end_date'    => '',
                    'current_qty'      => 0,
                    'consume_qty'      => 0,
                    'predicted_qty'    => $qty,
                    'is_negative'      => $qty < 0 ? 1 : 0,
                    'is_manual'        => 1,
                    'calc_detail'      => json_encode(['note' => '手动盘点数据'], JSON_UNESCAPED_UNICODE),
                    'begin_today'       => $qty,
                    'today_inbound'     => 0,
                    'today_output'      => 0,
                    'today_consume'     => 0,
                    'today_predicted'   => $qty,
                    'has_today_activity' => false,
                ];
            }
        }

        // ========== Step 9: 去重 + 过滤零值 ==========
        $result = self::dedupeCalcResults($result);
        $result = array_values(array_filter($result, function ($item) {
            // 负数（异常）保留，为零的隐藏
            return $item['predicted_qty'] != 0;
        }));

        return $result;
    }

    /**
     * 月度库存台账
     *
     * 以上月结存为期初，按月统计每道工序各品种的逐日出入变化和当前结存。
     * 断钢→穿孔使用生产计划级联匹配，按断钢实际产出比例分摊穿孔消耗（含3%损耗系数）。
     *
     * @param string $yearMonth 年月 "2026-05"
     * @return array
     */
    public static function getMonthlyLedger($yearMonth = '')
    {
        // ====== Step 1: 确定年月范围 ======
        if (empty($yearMonth)) {
            $yearMonth = date('Y-m');
        }
        $monthStart = $yearMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        // ====== Step 2-3: 按工序独立取期初（各工序可不同日期） ======
        $processNames = ['圆钢', '断钢', '穿孔', '球化', '矫直', '酸洗', '次成品', '半成品'];
        $baseline = [];

        foreach ($processNames as $pn) {
            $dateRow = Db::query(
                "SELECT MAX(inventory_date) AS d FROM ya_material_inventory
                 WHERE is_delete=0 AND confirmed=1 AND process_name = ? AND inventory_date < ?",
                [$pn, $monthStart]
            );
            $date = $dateRow[0]['d'] ?? null;
            if (!$date) {
                $dateRow = Db::query(
                    "SELECT MAX(inventory_date) AS d FROM ya_material_inventory
                     WHERE is_delete=0 AND process_name = ? AND inventory_date < ?",
                    [$pn, $monthStart]
                );
                $date = $dateRow[0]['d'] ?? null;
            }
            if (!$date) {
                continue;
            }

            $date = date('Y-m-d', strtotime($date));
            $procBaseline = Db::name('material_inventory')
                ->where('is_delete', 0)
                ->where('inventory_date', $date)
                ->where('process_name', $pn)
                ->select()
                ->toArray();
            $baseline = array_merge($baseline, $procBaseline);
        }

        if (empty($baseline)) {
            return ['error' => '无期初盘点数据，请先录入上月底盘点'];
        }
        // 取基线中最晚日期作为台账标题显示的期初日期
        $beginDate = '';
        foreach ($baseline as $b) {
            $bd = $b['inventory_date'] ?? '';
            if ($bd && (!$beginDate || strtotime($bd) > strtotime($beginDate))) {
                $beginDate = $bd;
            }
        }
        if (empty($baseline)) {
            return ['error' => '期初盘点数据为空（日期:' . $beginDate . '）'];
        }

        // ====== Step 4: 查询本月报工数据 ======
        $reportRows = Db::name('product_report')
            ->alias('pr')
            ->join('material m', 'pr.material_id = m.id')
            ->where('pr.report_date', '>=', $monthStart)
            ->where('pr.report_date', '<=', $monthEnd)
            ->where('pr.is_delete', 0)
            ->whereNotNull('m.material_name')
            ->where('m.material_name', '<>', '')
            ->field('m.id as material_id, m.material_name, m.material_spec as y_spec,
                     m.material_model as y_model,
                     pr.processes_code, pr.report_num, pr.report_date,
                     pr.relation_code')
            ->select()
            ->toArray();

        // ====== Step 5: 构建报工索引 ======
        // daySpecIdx[date][material_id][process_code][spec_key] = qty
        $daySpecIdx = [];
        // dayTotalIdx[date][material_id][process_code] = qty（不区分spec）
        $dayTotalIdx = [];
        // dayNameSpecIdx[name][y_spec][date][process_code][spec_key] = qty（文本回退）
        $dayNameSpecIdx = [];
        // dayNameTotalIdx[name][y_spec][date][process_code] = qty
        $dayNameTotalIdx = [];

        // 生产计划级索引
        $planDgIdx = [];    // [plan_code][date][material_id][spec_key]
        $planCkIdx = [];    // [plan_code][date][material_id] = total_qty

        $relationCodes = [];
        $midSet = [];

        foreach ($reportRows as $r) {
            $mid = intval($r['material_id']);
            $date = $r['report_date'];
            $code = $r['processes_code'];
            $qty = floatval($r['report_num']);
            $name = $r['material_name'] ?? '';
            $ySpec = $r['y_spec'] ?? '';
            // ya_product_report 无 product_spec/english_material 列，用空字符串代替
            $specKey = '';

            if ($mid > 0) {
                $midSet[$mid] = true;
                if (!isset($daySpecIdx[$date][$mid][$code][$specKey])) {
                    $daySpecIdx[$date][$mid][$code][$specKey] = 0;
                }
                $daySpecIdx[$date][$mid][$code][$specKey] += $qty;
                if (!isset($dayTotalIdx[$date][$mid][$code])) {
                    $dayTotalIdx[$date][$mid][$code] = 0;
                }
                $dayTotalIdx[$date][$mid][$code] += $qty;
            }
            if (!isset($dayNameSpecIdx[$name][$ySpec][$date][$code][$specKey])) {
                $dayNameSpecIdx[$name][$ySpec][$date][$code][$specKey] = 0;
            }
            $dayNameSpecIdx[$name][$ySpec][$date][$code][$specKey] += $qty;
            if (!isset($dayNameTotalIdx[$name][$ySpec][$date][$code])) {
                $dayNameTotalIdx[$name][$ySpec][$date][$code] = 0;
            }
            $dayNameTotalIdx[$name][$ySpec][$date][$code] += $qty;

            // 收集relation_code
            if (!empty($r['relation_code'])) {
                $relationCodes[$r['relation_code']] = true;
            }
        }

        // ====== Step 6: 生产计划级联索引 ======
        $taskToPlan = [];
        if (!empty($relationCodes)) {
            $taskRows = Db::name('product_task')
                ->whereIn('task_code', array_keys($relationCodes))
                ->where('is_delete', 0)
                ->field('task_code, relation_code')
                ->select()
                ->toArray();
            foreach ($taskRows as $tr) {
                $taskToPlan[$tr['task_code']] = $tr['relation_code'];
            }
        }

        foreach ($reportRows as $r) {
            $mid = intval($r['material_id']);
            if ($mid <= 0) {
                continue;
            }
            $taskCode = $r['relation_code'] ?? '';
            $planCode = $taskToPlan[$taskCode] ?? '';
            if (empty($planCode)) {
                continue;
            }

            $date = $r['report_date'];
            $code = $r['processes_code'];
            $qty = floatval($r['report_num']);

            if ($code === 'gx_0010') {
                $specKey = ''; // ya_product_report 无 english_material，无法获取成品规格
                if (!isset($planDgIdx[$planCode][$date][$mid][$specKey])) {
                    $planDgIdx[$planCode][$date][$mid][$specKey] = 0;
                }
                $planDgIdx[$planCode][$date][$mid][$specKey] += $qty;
            } elseif ($code === 'gx_0011') {
                if (!isset($planCkIdx[$planCode][$date][$mid])) {
                    $planCkIdx[$planCode][$date][$mid] = 0;
                }
                $planCkIdx[$planCode][$date][$mid] += $qty;
            }
        }

        // ====== Step 7: 入库数据（圆钢仅统计圆钢仓库） ======
        $inboundRows = self::applyRoundSteelInboundWarehouseFilter(
            Db::name('wms_in')
            ->alias('wi')
            ->join('material m', 'wi.material_id = m.id AND m.material_property = \'圆钢\'')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->where(function ($q2) use ($monthStart, $monthEnd) {
                    $q2->whereNotNull('wi.inbound_date')
                       ->where('wi.inbound_date', '>=', $monthStart)
                       ->where('wi.inbound_date', '<=', $monthEnd);
                });
                $q->whereOr(function ($q2) use ($monthStart, $monthEnd) {
                    $q2->whereNull('wi.inbound_date')
                       ->whereTime('wi.create_time', '>=', $monthStart)
                       ->whereTime('wi.create_time', '<=', $monthEnd);
                });
            })
            ->where('wi.is_delete', 0)
            ->where('wi.status', 0)
        )
            ->field('m.id as material_id, m.material_name, m.material_spec,
                     m.material_model, wi.in_num, wi.inbound_date, wi.create_time')
            ->select()
            ->toArray();

        // inboundDayIdx[date][material_id] = qty
        $inboundDayIdx = [];
        $inboundNameDayIdx = [];
        // inboundMaterialTotals[material_id] = total_qty（月度总计，补充期初没有的新圆钢用）
        $inboundMaterialTotals = [];
        $inboundRowsByName = []; // [material_name][material_spec] = {total, date_qty}
        foreach ($inboundRows as $r) {
            $mid = intval($r['material_id']);
            $date = $r['inbound_date'] ?: date('Y-m-d', strtotime($r['create_time']));
            $qty = floatval($r['in_num']);
            if ($mid > 0) {
                if (!isset($inboundDayIdx[$date][$mid])) {
                    $inboundDayIdx[$date][$mid] = 0;
                }
                $inboundDayIdx[$date][$mid] += $qty;
                if (!isset($inboundMaterialTotals[$mid])) {
                    $inboundMaterialTotals[$mid] = 0;
                }
                $inboundMaterialTotals[$mid] += $qty;
            }
            // 按名称
            $nm = $r['material_name'] ?? '';
            $sp = $r['material_spec'] ?? '';
            if (!isset($inboundNameDayIdx[$nm][$sp][$date])) {
                $inboundNameDayIdx[$nm][$sp][$date] = 0;
            }
            $inboundNameDayIdx[$nm][$sp][$date] += $qty;
            if (!isset($inboundRowsByName[$nm][$sp])) {
                $inboundRowsByName[$nm][$sp] = ['total' => 0, 'mid' => $mid, 'model' => $r['material_model'] ?? ''];
            }
            $inboundRowsByName[$nm][$sp]['total'] += $qty;
        }

        // ====== Step 8: 发货数据 ======
        $shipRows = Db::name('ship')
            ->alias('s')
            ->join('material m', 's.material_id = m.id')
            ->where('s.is_delete', 0)
            ->where('s.status', 0)
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->where(function ($q2) use ($monthStart, $monthEnd) {
                    $q2->whereNotNull('s.ship_date')
                       ->where('s.ship_date', '>=', $monthStart)
                       ->where('s.ship_date', '<=', $monthEnd);
                });
                $q->whereOr(function ($q2) use ($monthStart, $monthEnd) {
                    $q2->whereNull('s.ship_date')
                       ->whereTime('s.create_time', '>=', $monthStart)
                       ->whereTime('s.create_time', '<=', $monthEnd);
                });
            })
            ->field('m.id as material_id, m.material_name, SUM(s.material_num) as total_qty')
            ->group('m.id, m.material_name')
            ->select()
            ->toArray();

        $shipIdx = [];
        $shipNameIdx = [];
        foreach ($shipRows as $r) {
            $mid = intval($r['material_id']);
            $qty = floatval($r['total_qty']);
            if ($mid > 0) {
                $shipIdx[$mid] = $qty;
            }
            $shipNameIdx[$r['material_name']] = $qty;
        }

        // ====== Step 9: 工序配置 ======
        $processFlow = [
            '圆钢' => ['current_code' => null, 'next_code' => 'gx_0010', 'type' => 'raw'],
            '断钢' => ['current_code' => 'gx_0010', 'next_code' => 'gx_0011', 'type' => 'process'],
            '穿孔' => ['current_code' => 'gx_0011', 'next_code' => 'gx_0012', 'type' => 'process'],
            '球化' => ['current_code' => 'gx_0012', 'next_code' => 'gx_0013', 'type' => 'process'],
            '矫直' => ['current_code' => 'gx_0013', 'next_code' => 'gx_0014', 'type' => 'process'],
            '酸洗' => ['current_code' => 'gx_0014', 'next_code' => null, 'type' => 'finish'],
        ];

        // ====== Helper 函数 ======
        $getDayTotal = function ($date, $materialId, $code, $name, $ySpec) use (&$dayTotalIdx, &$dayNameTotalIdx) {
            $mid = intval($materialId);
            if ($mid > 0 && isset($dayTotalIdx[$date][$mid][$code])) {
                return $dayTotalIdx[$date][$mid][$code];
            }
            return $dayNameTotalIdx[$name][$ySpec][$date][$code] ?? 0;
        };
        $getDaySpec = function ($date, $materialId, $code, $specKey, $name, $ySpec) use (&$daySpecIdx, &$dayTotalIdx, &$dayNameSpecIdx) {
            $mid = intval($materialId);
            if ($mid > 0 && isset($daySpecIdx[$date][$mid][$code][$specKey])) {
                return $daySpecIdx[$date][$mid][$code][$specKey];
            }
            // spec未匹配时降级到按material_id汇总（无product_spec数据的场景）
            if ($mid > 0 && isset($dayTotalIdx[$date][$mid][$code])) {
                return $dayTotalIdx[$date][$mid][$code];
            }
            return $dayNameSpecIdx[$name][$ySpec][$date][$code][$specKey] ?? 0;
        };

        $baseline = self::appendMonthlyMissingRoundSteelBaseline(
            $baseline,
            $monthStart,
            $monthEnd,
            $inboundRows,
            $reportRows
        );
        $baseline = self::appendMonthlyMissingProcessBaseline(
            $baseline,
            $monthStart,
            $monthEnd
        );
        $baseline = self::dedupeMonthlyBaseline($baseline);

        // ====== Step 9.5: 预计算断钢穿孔计划级联分摊消耗，消灭 O(N^3) 嵌套循环 ======
        $precomputedDgConsume = [];     // [date][material_id][spec_key] = 消耗吨数
        $precomputedPlanFound = [];     // [date][material_id] = bool (是否匹配到任意有报工的计划)

        foreach ($planCkIdx as $pCode => $pCkDates) {
            foreach ($pCkDates as $ckDate => $ckMids) {
                foreach ($ckMids as $stockMid => $planCkQty) {
                    if ($planCkQty <= 0) {
                        continue;
                    }

                    // 找同一个计划下当天的断钢产出
                    $dgSpecs = $planDgIdx[$pCode][$ckDate][$stockMid] ?? [];
                    if (empty($dgSpecs)) {
                        continue;
                    }

                    $totalDgQty = array_sum($dgSpecs);
                    if ($totalDgQty <= 0) {
                        continue;
                    }

                    $precomputedPlanFound[$ckDate][$stockMid] = true;

                    // 按断钢产出比例分摊该计划的穿孔量
                    foreach ($dgSpecs as $sKey => $specDgQty) {
                        $ratio = $specDgQty / $totalDgQty;
                        $share = $planCkQty * $ratio;
                        if (!isset($precomputedDgConsume[$ckDate][$stockMid][$sKey])) {
                            $precomputedDgConsume[$ckDate][$stockMid][$sKey] = 0;
                        }
                        $precomputedDgConsume[$ckDate][$stockMid][$sKey] += $share;
                    }
                }
            }
        }

        // ====== Step 10: 期初推算月度台账 ======
        $result = [];
        // 记录基线中已有的material_id（用于步骤11判断新入库）
        $baselineMids = [];

        // 提前查物料类别映射：material_id → category（含material_id=0的按名回退）
        $baselineMidList = [];
        $baselineNameLookup = []; // 记录material_id=0的记录，按名称回查
        foreach ($baseline as $b) {
            $mid = intval($b['material_id'] ?? 0);
            if ($mid > 0) {
                $baselineMidList[$mid] = true;
            } else {
                $name = trim($b['material_name'] ?? '');
                if ($name) {
                    $baselineNameLookup[$name] = true;
                }
            }
        }
        $midCategoryMap = [];
        if (!empty($baselineMidList)) {
            $midRows = Db::name('material')
                ->whereIn('id', array_keys($baselineMidList))
                ->field('id, material_name')
                ->select()
                ->toArray();
            foreach ($midRows as $mr) {
                $midCategoryMap[$mr['id']] = $mr['material_name'];
            }
        }
        // 对material_id=0的记录，用名称回查物料表
        if (!empty($baselineNameLookup)) {
            $nameRows = Db::name('material')
                ->whereIn('material_name', array_keys($baselineNameLookup))
                ->where('material_property', '圆钢')
                ->field('material_name')
                ->group('material_name')
                ->select()
                ->toArray();
            foreach ($nameRows as $nr) {
                $midCategoryMap['name:' . $nr['material_name']] = $nr['material_name'];
            }
        }

        foreach ($baseline as $stock) {
            $processName = $stock['process_name'];
            $materialName = $stock['material_name'];
            $stockMid = intval($stock['material_id'] ?? 0);
            $baselineQty = floatval($stock['total_tonnage']);
            $matchKey = self::extractSpecKey($stock['c_material_spec'] ?? '');
            $ySpec = $stock['y_material_spec'] ?? '';
            $yModel = $stock['y_material_model'] ?? '';
            $cSpec = $stock['c_material_spec'] ?? '';

            if (!isset($processFlow[$processName])) {
                continue;
            }

            if ($processName === '圆钢' && $stockMid > 0) {
                $baselineMids[$stockMid] = true;
            }

            $flow = $processFlow[$processName];
            $currentCode = $flow['current_code'];
            $nextCode = $flow['next_code'];

            // 逐日遍历
            $totalInbound = 0;
            $totalOutput = 0;
            $totalConsume = 0;
            $dailyChanges = [];

            $iterDate = $monthStart;
            $runningQty = $baselineQty;

            while ($iterDate <= $monthEnd) {
                $inbound = 0;
                $output = 0;
                $consume = 0;

                if ($processName === '圆钢') {
                    // 入库：有 material_id 时只按 id 匹配，避免同名规格串数据
                    if ($stockMid > 0) {
                        $inbound = $inboundDayIdx[$iterDate][$stockMid] ?? 0;
                    } elseif ($ySpec !== '' && $yModel !== '') {
                        $inbound = $inboundNameDayIdx[$materialName][$ySpec][$iterDate] ?? 0;
                    }
                    // 消耗 = 断钢报工（按 material_id 精确匹配）
                    if ($stockMid > 0) {
                        $consume = $dayTotalIdx[$iterDate][$stockMid]['gx_0010'] ?? 0;
                    } else {
                        $consume = $getDayTotal($iterDate, $stockMid, 'gx_0010', $materialName, $ySpec);
                    }
                } elseif ($processName === '断钢') {
                    // 产出 = 断钢报工按(spec_key)匹配
                    $output = $getDaySpec($iterDate, $stockMid, 'gx_0010', $matchKey, $materialName, $ySpec);

                    // 消耗 = 穿孔消耗 / 0.97（生产计划级联匹配）
                    $totalCkDay = $getDayTotal($iterDate, $stockMid, 'gx_0011', $materialName, $ySpec);
                    if ($totalCkDay > 0) {
                        if ($precomputedPlanFound[$iterDate][$stockMid] ?? false) {
                            $ckConsumed = $precomputedDgConsume[$iterDate][$stockMid][$matchKey] ?? 0;
                            $consume = $ckConsumed / self::PROCESS_YIELD_RATIO;
                        } else {
                            // 回退：按当日断钢产出比例分摊
                            $specOutToday = $output;
                            $totalDgToday = $getDayTotal($iterDate, $stockMid, 'gx_0010', $materialName, $ySpec);
                            if ($totalDgToday > 0) {
                                $consume = $totalCkDay * ($specOutToday / $totalDgToday) / self::PROCESS_YIELD_RATIO;
                            } else {
                                // 无断钢产出——全部计入该spec消耗
                                $consume = $totalCkDay / self::PROCESS_YIELD_RATIO;
                            }
                        }
                    }
                } else {
                    // 穿孔+：按(圆钢ID, spec_key)匹配
                    $output = $getDaySpec($iterDate, $stockMid, $currentCode, $matchKey, $materialName, $ySpec);
                    if ($nextCode) {
                        $consume = $getDaySpec($iterDate, $stockMid, $nextCode, $matchKey, $materialName, $ySpec);
                    }

                    // 酸洗额外减发货（月末一次性扣减，不在循环里扣）
                }

                $runningQty += ($inbound + $output - $consume);

                if ($inbound != 0 || $output != 0 || $consume != 0) {
                    $dailyChanges[] = [
                        'date'    => $iterDate,
                        'inbound' => round($inbound, 3),
                        'output'  => round($output, 3),
                        'consume' => round($consume, 3),
                    ];
                }

                $totalInbound += $inbound;
                $totalOutput += $output;
                $totalConsume += $consume;

                $iterDate = date('Y-m-d', strtotime($iterDate . ' +1 day'));
            }

            // 酸洗额外减本月发货
            if ($processName === '酸洗') {
                $shipQty = 0;
                if ($stockMid > 0) {
                    $shipQty = $shipIdx[$stockMid] ?? 0;
                }
                if ($shipQty == 0) {
                    $shipQty = $shipNameIdx[$materialName] ?? 0;
                }
                if ($shipQty > 0) {
                    $totalConsume += $shipQty;
                    $runningQty -= $shipQty;
                    $dailyChanges[] = [
                        'date'    => 'ship_total',
                        'inbound' => 0,
                        'output'  => 0,
                        'consume' => round($shipQty, 3),
                    ];
                }
            }

            $hasActivity = ($baselineQty > 0) || ($totalInbound > 0) || ($totalOutput > 0) || ($totalConsume > 0);

            $result[] = [
                'process_name'     => $processName,
                'material_name'    => $materialName,
                'category'         => self::resolveMonthlyLedgerCategory($stock, $midCategoryMap),
                'y_material_spec'  => $ySpec,
                'y_material_model' => $yModel,
                'c_material_spec'  => $cSpec,
                'begin_qty'        => round($baselineQty, 3),
                'total_inbound'    => round($totalInbound, 3),
                'total_output'     => round($totalOutput, 3),
                'total_consume'    => round($totalConsume, 3),
                'current_qty'      => round($runningQty, 3),
                'is_new'           => 0,
                'has_activity'     => $hasActivity,
                'daily'            => $dailyChanges,
            ];
        }

        // ====== Step 11: 补充新入库圆钢（期初没有的品种，仅限同类别） ======
        // 收集基线中已有的物料类别名（如"溯源"），供Step 11过滤非同类物料
        $baselineCategoryNames = [];
        foreach ($baseline as $b) {
            $mid = intval($b['material_id'] ?? 0);
            if ($mid > 0) {
                $catName = $midCategoryMap[$mid] ?? '';
                if ($catName !== '') {
                    $baselineCategoryNames[$catName] = true;
                }
            }
        }

        foreach ($inboundRows as $r) {
            $mid = intval($r['material_id']);
            if ($mid > 0 && isset($baselineMids[$mid])) {
                continue;
            }

            $nm = $r['material_name'] ?? '';   // material表里的名称（如"溯源"）
            $sp = $r['material_spec'] ?? '';   // 圆钢规格（如"宣钢"）
            $md = $r['material_model'] ?? '';  // 圆钢型号（如"55"）

            // 品种按"规格｜型号"展示
            $varietyName = ($sp && $md) ? ($sp . '｜' . $md) : $nm;

            // 仅加入与期初同类别的新入库圆钢（排除不同类别的物料，如凯亚）
            if (!empty($baselineCategoryNames) && !isset($baselineCategoryNames[$nm])) {
                continue;
            }

            $alreadyIn = false;
            $candidateKey = self::calcResultDedupeKey(self::normalizeRoundSteelRow([
                'process_name'     => '圆钢',
                'material_name'    => $nm,
                'y_material_spec'  => $sp,
                'y_material_model' => $md,
                'c_material_spec'  => '',
            ]));
            foreach ($result as $rr) {
                if (($rr['process_name'] ?? '') !== '圆钢') {
                    continue;
                }
                $rrKey = self::calcResultDedupeKey(self::normalizeRoundSteelRow([
                    'process_name'     => '圆钢',
                    'material_name'    => $rr['category'] ?? $rr['material_name'] ?? '',
                    'y_material_spec'  => $rr['y_material_spec'] ?? '',
                    'y_material_model' => $rr['y_material_model'] ?? '',
                    'c_material_spec'  => $rr['c_material_spec'] ?? '',
                ]));
                if ($rrKey === $candidateKey) {
                    $alreadyIn = true;
                    break;
                }
            }
            if ($alreadyIn) {
                continue;
            }

            $inboundTotal = floatval($inboundMaterialTotals[$mid] ?? 0);
            // 断钢消耗
            $dgConsumeTotal = 0;
            foreach ($dayTotalIdx as $d => $dMids) {
                $dgConsumeTotal += $dMids[$mid]['gx_0010'] ?? 0;
            }
            if ($inboundTotal <= 0 && $dgConsumeTotal <= 0) {
                continue;
            }

            // 构建逐日明细
            $dailyChanges = [];
            $iterDate = $monthStart;
            $runningQty = 0;
            $totalInbound = 0;
            $totalConsume = 0;
            while ($iterDate <= $monthEnd) {
                $dayIn = $inboundDayIdx[$iterDate][$mid] ?? 0;
                $dayConsume = $dayTotalIdx[$iterDate][$mid]['gx_0010'] ?? 0;
                $runningQty += ($dayIn - $dayConsume);
                $totalInbound += $dayIn;
                $totalConsume += $dayConsume;
                if ($dayIn != 0 || $dayConsume != 0) {
                    $dailyChanges[] = [
                        'date'    => $iterDate,
                        'inbound' => round($dayIn, 3),
                        'output'  => 0,
                        'consume' => round($dayConsume, 3),
                    ];
                }
                $iterDate = date('Y-m-d', strtotime($iterDate . ' +1 day'));
            }

            $result[] = [
                'process_name'     => '圆钢',
                'material_name'    => ($sp && $md) ? ($sp . '｜' . $md) : $varietyName,
                'category'         => $nm,
                'y_material_spec'  => $sp,
                'y_material_model' => $md,
                'c_material_spec'  => '',
                'begin_qty'        => 0,
                'total_inbound'    => round($totalInbound, 3),
                'total_output'     => 0,
                'total_consume'    => round($totalConsume, 3),
                'current_qty'      => round($runningQty, 3),
                'is_new'           => 1,
                'has_activity'     => true,
                'daily'            => $dailyChanges,
            ];
        }

        // ====== Step 12: 次成品/半成品——数据延续 ======
        $historyTypes = ['次成品', '半成品'];
        foreach ($historyTypes as $ht) {
            $historyRows = Db::name('material_inventory')
                ->where('is_delete', 0)
                ->where('process_name', $ht)
                ->order('inventory_date', 'desc')
                ->order('create_time', 'desc')
                ->select()
                ->toArray();

            $seen = [];
            foreach ($historyRows as $hr) {
                $key = ($hr['material_name'] ?? '') . "\x00" . ($hr['c_material_spec'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = $hr;
                }
            }

            foreach ($seen as $hr) {
                $qty = floatval($hr['total_tonnage']);
                $result[] = [
                    'process_name'     => $ht,
                    'material_name'    => $hr['material_name'] ?? '',
                    'category'         => $hr['material_name'] ?? '',
                    'y_material_spec'  => $hr['y_material_spec'] ?? '',
                    'y_material_model' => $hr['y_material_model'] ?? '',
                    'c_material_spec'  => $hr['c_material_spec'] ?? '',
                    'c_material_model' => $hr['c_material_model'] ?? '',
                    'begin_qty'        => $qty,
                    'total_inbound'    => 0,
                    'total_output'     => 0,
                    'total_consume'    => 0,
                    'current_qty'      => $qty,
                    'is_new'           => 0,
                    'is_history'       => 1,
                    'has_activity'     => $qty > 0,
                    'daily'            => [],
                ];
            }
        }

        // ====== Step 13: 返回结构整理 ======
        // 按工序排序
        $processOrder = array_keys($processFlow);
        $processOrder[] = '次成品';
        $processOrder[] = '半成品';
        usort($result, function ($a, $b) use ($processOrder) {
            $pa = array_search($a['process_name'], $processOrder) ?: 99;
            $pb = array_search($b['process_name'], $processOrder) ?: 99;
            if ($pa !== $pb) {
                return $pa - $pb;
            }
            return strcmp($a['material_name'], $b['material_name']);
        });

        return [
            'period'     => $yearMonth,
            'begin_date' => $beginDate,
            'month_start' => $monthStart,
            'month_end'  => $monthEnd,
            'rows'       => $result,
        ];
    }

    /**
     * 检查 ya_ship 表是否有 ship_date 字段
     * ship_date 字段已通过 DDL 添加，直接返回 true
     */
    private static function hasShipDateColumn(): bool
    {
        return true;
    }

    /**
     * 删除盘点记录
     */
    public static function deleInventory($ids, $real = true)
    {
        $model = new MaterialInventoryModel();
        $pk = $model->getPk();

        if ($real) {
            $res = $model->where($pk, 'in', $ids)->delete();
        } else {
            $update = ['is_delete' => 1];
            $res = $model->where($pk, 'in', $ids)->update($update);
        }
        if (empty($res)) {
            exception('删除失败');
        }
        $update['ids'] = $ids;
        return $update;
    }
}
