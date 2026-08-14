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
use app\common\service\admin\PublicService;
use app\common\service\utils\MaterialSearchHelper;
use think\facade\Db;

class MaterialInventoryService
{
    /** 穿孔→断钢消耗折算系数（报工吨数 / 0.97 = 圆钢消耗） */
    private const PROCESS_YIELD_RATIO = 0.97;

    /**
     * [AI-2026-08] 全厂盘点重置日：此日及之后的推算只认该日及更新的手动期初，
     * 不再沿用重置前旧品种（Excel 未盘到的不再显示）。
     */
    private const MANUAL_BASELINE_RESET_DATE = '2026-08-10';

    public static $search_field = [
        'check_name' => '',
        'check_code' => '',
    ];

    /**
     * 盘点搜索字段
     */
    public static $inventory_search_field = [
        'material_name' => '',      // 物料名称
        'y_material_name' => '',       // 圆钢名称
        'y_material_manufacturer' => '', // 圆钢厂商（当前档案虚拟字段）
        'y_mateial_name' => '',
        'y_mateial_manufacturer' => '',
        'y_mateial_spec' => '',
        'y_mateial_model' => '',
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
        'y_material_name' => '',           // 圆钢名称
        'y_material_manufacturer' => '',   // 圆钢厂商快照
        'y_material_spec' => '',           // 圆钢材质快照
        'y_material_model' => '',          // 圆钢型号快照
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
     * 同一工序、同一圆钢、同一成品型号下多长度行按期初吨数占比
     */
    private static function calcProcessBaselineRatio(
        array $baseline,
        string $processName,
        int $stockMaterialId,
        string $materialName,
        string $matchSpec,
        string $matchModel,
        string $matchKey,
        string $matchCModel = ''
    ): float {
        $specQtyMap = [];
        $totalBaseline = 0.0;
        $matchCModel = self::normalizeSpec($matchCModel);
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
            if ($matchCModel !== '' && self::normalizeSpec($bs['c_material_model'] ?? '') !== $matchCModel) {
                continue;
            }
            $bsKey = self::extractSpecKey($bs['c_material_spec'] ?? '');
            $bsQty = floatval($bs['total_tonnage']);
            $specQtyMap[$bsKey] = ($specQtyMap[$bsKey] ?? 0.0) + $bsQty;
            $totalBaseline += $bsQty;
        }
        if ($totalBaseline <= 0.000001) {
            $specCount = count($specQtyMap);
            return $specCount > 0 ? (1.0 / $specCount) : 0.0;
        }
        return ($specQtyMap[$matchKey] ?? 0.0) / $totalBaseline;
    }

    /**
     * 圆钢当日报工：有 material_id 只按档案 ID，禁止「溯源+轴承钢+型号」跨钢厂串扣。
     *
     * @param array<int, array<string, float>> $todayReportIdx
     * @param array<string, mixed> $todayVarietyReportIdx 保留参数兼容调用方，不再用于有 ID 的圆钢
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
        // [AI-2026-08] 钢厂串扣修复：有档案 ID 不走无钢厂三字段合计
        if ($mid > 0) {
            return floatval($todayReportIdx[$mid][$code] ?? 0);
        }
        return 0.0;
    }

    /**
     * 圆钢当日报工明细（与 getTodayRoundSteelReport 同一匹配规则）
     *
     * @param array<int, array<string, array<int, array{date: string, qty: float}>>> $todayReportDetails
     * @param array<string, mixed> $todayVarietyReportDetails 保留参数兼容调用方
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
        // [AI-2026-08] 钢厂串扣修复：明细与数量同一套 ID 匹配
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
        // [AI-2026-08] 钢厂串扣修复：有档案 ID 不按规格+型号串其他钢厂明细
        if ($mid > 0 && !empty($todayReportDetails[$mid][$code])) {
            return $todayReportDetails[$mid][$code];
        }
        if ($mid > 0) {
            return [];
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
    public static function inventoryList(
        $where = [],
        $page = 1,
        $limit = 10,
        $order = [],
        $field = [],
        array $roundFilters = []
    )
    {
        if (empty($order)) {
            $order = ['inventory_date' => 'desc', 'create_time' => 'desc'];
        }
        $buildQuery = static function () use ($where, $roundFilters) {
            $query = (new MaterialInventoryModel())
                ->alias('mi')
                ->field('mi.*')
                ->where($where);
            return MaterialSearchHelper::applyInventoryRoundSteelFilters(
                $query,
                $roundFilters,
                'mi',
                'ym'
            );
        };
        $queryOrder = [];
        foreach ($order as $fieldName => $direction) {
            $queryOrder[str_contains((string) $fieldName, '.')
                ? $fieldName
                : 'mi.' . $fieldName] = $direction;
        }
        if ($page == 0 || $limit == 0) {
            $list = $buildQuery()
                ->with(['getMaterial', 'getCreator'])
                ->order($queryOrder)
                ->select()
                ->toArray();
            return self::enrichInventoryHistoryRows($list);
        }
        $count = $buildQuery()->count();
        $pages = ceil($count / $limit);
        $list = $buildQuery()
            ->with(['getMaterial', 'getCreator'])
            ->page($page)
            ->limit($limit)
            ->order($queryOrder)
            ->select()
            ->toArray();
        $list = self::enrichInventoryHistoryRows($list);
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
        $rows = self::enrichInventoryHistoryRows([$info->toArray()]);
        return $rows[0] ?? [];
    }

    /**
     * 历史盘点展示：有效 material_id 使用当前档案，无效时保留历史快照。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function enrichInventoryHistoryRows(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['material_id'] ?? 0),
            $rows
        ))));
        $archiveMap = [];
        if ($ids !== []) {
            $archiveRows = Db::name('material')
                ->whereIn('id', $ids)
                ->where('is_delete', 0)
                ->select()
                ->toArray();
            foreach ($archiveRows as $archiveRow) {
                $archiveMap[(int) ($archiveRow['id'] ?? 0)] = $archiveRow;
            }
        }
        foreach ($rows as &$row) {
            $materialId = (int) ($row['material_id'] ?? 0);
            $row = self::resolveRoundSteelHistoryFields($row, $archiveMap[$materialId] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * 解析历史圆钢四字段，不改写历史快照。
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed>|null $archive
     * @return array<string,mixed>
     */
    public static function resolveRoundSteelHistoryFields(array $snapshot, ?array $archive): array
    {
        if (
            (int) ($snapshot['material_id'] ?? 0) > 0
            && self::isRoundSteelArchive($archive)
        ) {
            $snapshot['y_material_name'] = (string) ($archive['material_name'] ?? '');
            $snapshot['y_material_manufacturer'] = (string) ($archive['manufacturer'] ?? '');
            $snapshot['y_material_spec'] = (string) ($archive['material_spec'] ?? '');
            $snapshot['y_material_model'] = (string) ($archive['material_model'] ?? '');
            $snapshot['material_name'] = $snapshot['y_material_name'];
            return $snapshot;
        }

        $snapshot['y_material_name'] = (string) (
            $snapshot['y_material_name'] ?? $snapshot['material_name'] ?? ''
        );
        $snapshot['y_material_manufacturer'] = (string) (
            $snapshot['y_material_manufacturer'] ?? ''
        );

        return $snapshot;
    }

    /**
     * 档案是否为有效圆钢。
     *
     * @param array<string,mixed>|null $archive
     * @return bool
     */
    private static function isRoundSteelArchive(?array $archive): bool
    {
        return !empty($archive)
            && (int) ($archive['is_delete'] ?? 0) === 0
            && trim((string) ($archive['material_property'] ?? '')) === '圆钢';
    }

    /**
     * 从候选档案中选择唯一圆钢；旧快照厂商为空时禁止猜测。
     *
     * @param array<string,mixed> $snapshot
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    public static function selectRoundSteelArchiveCandidate(array $snapshot, array $candidates): ?array
    {
        $materialId = (int) ($snapshot['material_id'] ?? 0);
        if ($materialId > 0) {
            foreach ($candidates as $candidate) {
                if (
                    (int) ($candidate['id'] ?? 0) === $materialId
                    && self::isRoundSteelArchive($candidate)
                ) {
                    return $candidate;
                }
            }
        }

        $identity = [
            'material_name' => trim((string) (
                $snapshot['y_material_name'] ?? $snapshot['material_name'] ?? ''
            )),
            'manufacturer' => trim((string) ($snapshot['y_material_manufacturer'] ?? '')),
            'material_spec' => trim((string) ($snapshot['y_material_spec'] ?? '')),
            'material_model' => trim((string) ($snapshot['y_material_model'] ?? '')),
        ];
        if (in_array('', $identity, true)) {
            return null;
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            if (!self::isRoundSteelArchive($candidate)) {
                continue;
            }
            $matched = true;
            foreach ($identity as $field => $value) {
                if (trim((string) ($candidate[$field] ?? '')) !== $value) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                $matches[] = $candidate;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * 圆钢身份键：material_id 优先；无 ID 时使用四字段，legacy 空厂商按记录独立。
     *
     * @param array<string,mixed> $row
     * @return string
     */
    public static function roundSteelIdentityKey(array $row): string
    {
        $materialId = (int) ($row['material_id'] ?? 0);
        $materialIdValid = ($row['_round_material_id_valid'] ?? true) !== false;
        if ($materialId > 0 && $materialIdValid) {
            return 'mid:' . $materialId;
        }

        $name = trim((string) ($row['y_material_name'] ?? $row['material_name'] ?? ''));
        $manufacturer = trim((string) ($row['y_material_manufacturer'] ?? ''));
        $spec = trim((string) ($row['y_material_spec'] ?? ''));
        $model = trim((string) ($row['y_material_model'] ?? ''));
        if ($manufacturer !== '') {
            return implode("\x00", ['fields', $name, $manufacturer, $spec, $model]);
        }

        $legacyToken = trim((string) (
            $row['id'] ?? $row['inventory_code'] ?? $row['create_time'] ?? ''
        ));
        if ($legacyToken === '') {
            $legacyToken = hash('sha256', serialize($row));
        }

        return implode("\x00", ['legacy', $legacyToken, $name, $spec, $model]);
    }

    /**
     * 审计/推算圆钢身份：有效 material_id 优先，否则稳定使用含厂商四字段。
     *
     * @param array<string,mixed> $row
     * @return string
     */
    public static function roundSteelCalculationIdentityKey(array $row): string
    {
        $materialId = (int) ($row['material_id'] ?? 0);
        $materialIdValid = ($row['_round_material_id_valid'] ?? true) !== false;
        if ($materialId > 0 && $materialIdValid) {
            return 'mid:' . $materialId;
        }
        $fields = [
            trim((string) ($row['y_material_name'] ?? $row['material_name'] ?? '')),
            trim((string) ($row['y_material_manufacturer'] ?? '')),
            trim((string) ($row['y_material_spec'] ?? '')),
            trim((string) ($row['y_material_model'] ?? '')),
        ];
        if (implode('', $fields) === '') {
            return 'unknown:' . (string) ($row['id'] ?? $row['inventory_code'] ?? '');
        }
        if ($fields[1] === '') {
            return implode('|', [$fields[0], $fields[2], $fields[3]]);
        }

        return 'snapshot:' . implode("\x00", $fields);
    }

    /**
     * 返回计算身份的当前键及 legacy name|spec|model 兼容键。
     *
     * @param array<string,mixed> $row
     * @return string[]
     */
    public static function roundSteelCalculationIdentityKeys(array $row): array
    {
        $keys = [self::roundSteelCalculationIdentityKey($row)];
        $materialId = (int) ($row['material_id'] ?? 0);
        $materialIdValid = ($row['_round_material_id_valid'] ?? true) !== false;
        $manufacturer = trim((string) ($row['y_material_manufacturer'] ?? ''));
        if (($materialId <= 0 || !$materialIdValid) && $manufacturer === '') {
            $keys[] = implode('|', [
                trim((string) ($row['y_material_name'] ?? $row['material_name'] ?? '')),
                trim((string) ($row['y_material_spec'] ?? '')),
                trim((string) ($row['y_material_model'] ?? '')),
            ]);
        }

        return array_values(array_unique($keys));
    }

    /**
     * 检查当前/legacy 任一计算身份是否已出现。
     *
     * @param array<string,mixed> $seenKeys
     * @param array<string,mixed> $row
     * @return bool
     */
    public static function roundSteelCalculationIdentityWasSeen(
        array $seenKeys,
        array $row
    ): bool {
        foreach (self::roundSteelCalculationIdentityKeys($row) as $key) {
            if (array_key_exists($key, $seenKeys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 合并自动推算中的重复圆钢基线。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function mergeRoundSteelBaselineRows(array $rows): array
    {
        $merged = [];
        $seen = [];
        foreach ($rows as $row) {
            if (($row['process_name'] ?? '') !== '圆钢') {
                $merged[] = $row;
                continue;
            }
            $identityKeys = self::roundSteelCalculationIdentityKeys($row);
            $index = null;
            foreach ($identityKeys as $identityKey) {
                if (isset($seen[$identityKey])) {
                    $index = $seen[$identityKey];
                    break;
                }
            }
            if ($index !== null) {
                $merged[$index]['total_tonnage']
                    = (float) ($merged[$index]['total_tonnage'] ?? 0)
                    + (float) ($row['total_tonnage'] ?? 0);
                continue;
            }
            $newIndex = count($merged);
            foreach ($identityKeys as $identityKey) {
                $seen[$identityKey] = $newIndex;
            }
            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * 判断基线是否已包含同一圆钢身份。
     *
     * @param array<int,array<string,mixed>> $baseline
     * @param array<string,mixed> $candidate
     * @return bool
     */
    public static function hasRoundSteelIdentity(array $baseline, array $candidate): bool
    {
        $candidateKeys = array_flip(self::roundSteelCalculationIdentityKeys($candidate));
        foreach ($baseline as $row) {
            if (($row['process_name'] ?? '') !== '圆钢') {
                continue;
            }
            foreach (self::roundSteelCalculationIdentityKeys($row) as $rowKey) {
                if (isset($candidateKeys[$rowKey])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 构建盘点落库的圆钢快照字段，厂商空值写 NULL。
     *
     * @param array<string,mixed> $item
     * @param int $resolvedMaterialId
     * @return array<string,mixed>
     */
    public static function buildStocktakeRoundSteelFields(
        array $item,
        int $resolvedMaterialId
    ): array {
        $materialName = trim((string) ($item['y_material_name'] ?? ''));
        if ($materialName === '') {
            $materialName = (string) ($item['material_name'] ?? '');
        }
        $manufacturer = trim((string) ($item['y_material_manufacturer'] ?? ''));

        return [
            'material_id' => $resolvedMaterialId,
            'material_name' => $materialName,
            'y_material_manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'y_material_spec' => (string) ($item['y_material_spec'] ?? ''),
            'y_material_model' => (string) ($item['y_material_model'] ?? ''),
        ];
    }

    /**
     * 批量盘点圆钢快照：仅信任与 material_id 对应的有效圆钢档案。
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $archive
     * @return array<string,mixed>
     */
    public static function resolveStocktakeRoundSteelSnapshot(
        array $item,
        ?array $archive
    ): array {
        $materialId = (int) ($item['material_id'] ?? 0);
        if (
            $materialId > 0
            && self::isRoundSteelArchive($archive)
            && (int) ($archive['id'] ?? 0) === $materialId
        ) {
            $item = self::resolveRoundSteelHistoryFields($item, $archive);
        }

        return self::buildStocktakeRoundSteelFields($item, $materialId);
    }

    /**
     * material_id 缺失时的四字段身份匹配，厂商属于身份字段。
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return bool
     */
    public static function roundSteelIdentityMatches(array $left, array $right): bool
    {
        $leftId = ($left['_round_material_id_valid'] ?? true) !== false
            ? (int) ($left['material_id'] ?? 0)
            : 0;
        $rightId = ($right['_round_material_id_valid'] ?? true) !== false
            ? (int) ($right['material_id'] ?? 0)
            : 0;
        if ($leftId > 0 || $rightId > 0) {
            return $leftId > 0 && $leftId === $rightId;
        }

        foreach (
            ['y_material_name', 'y_material_manufacturer', 'y_material_spec', 'y_material_model']
            as $field
        ) {
            $leftValue = trim((string) (
                $left[$field] ?? ($field === 'y_material_name' ? ($left['material_name'] ?? '') : '')
            ));
            $rightValue = trim((string) (
                $right[$field] ?? ($field === 'y_material_name' ? ($right['material_name'] ?? '') : '')
            ));
            if ($leftValue !== $rightValue) {
                return false;
            }
        }

        return true;
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
                if (!empty($param['y_material_name'])) {
                    $param['material_name'] = (string) $param['y_material_name'];
                }
                if (!empty($param['material_id'])) {
                    $materialModel = new MaterialModel();
                    $material = $materialModel->find($param['material_id']);
                    if (!empty($material)) {
                        $param['material_name'] = $material['material_name'];
                    }
                }
            }
            /* [AI-2026-08] 新建盘点保存圆钢四字段快照，非圆钢档案不覆盖 */
            $materialId = (int) ($param['material_id'] ?? 0);
            if ($materialId > 0) {
                $archive = (new MaterialModel())->find($materialId);
                if (!empty($archive)) {
                    $archiveRow = is_array($archive) ? $archive : $archive->toArray();
                    $param = self::resolveRoundSteelHistoryFields($param, $archiveRow);
                }
            }
            if (($param['process_name'] ?? '') === '圆钢') {
                $param = array_merge(
                    $param,
                    self::buildStocktakeRoundSteelFields($param, (int) ($param['material_id'] ?? 0))
                );
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
            $roundArchiveMap = [];
            if ($processName === '圆钢') {
                $roundArchiveMap = self::loadMaterialBriefMap(array_map(
                    static fn (array $item): int => (int) ($item['material_id'] ?? 0),
                    $items
                ));
            }

            // [AI-2026-08] 一次批量取号，避免逐条 getCode 行锁导致「全部提交」长时间转圈
            $inventoryCodes = PublicService::allocateCodes(10, count($items));
            $nowTime = datetime();

            foreach ($items as $index => $item) {
                $dayTonnage = floatval($item['day_shift_qty'] ?? 0);
                $nightTonnage = floatval($item['night_shift_qty'] ?? 0);

                // 归一化规格：确保格式一致以支持匹配
                $rawSpec = $item['c_material_spec'] ?? '';
                $normalizedSpec = $rawSpec ? self::normalizeSpec($rawSpec) : '';
                $rawModel = $item['c_material_model'] ?? '';
                $normalizedModel = $rawModel ? self::normalizeSpec((string) $rawModel) : '';

                $materialId = intval($item['material_id'] ?? 0);
                if ($processName === '圆钢') {
                    $roundSteelFields = self::resolveStocktakeRoundSteelSnapshot(
                        $item,
                        $roundArchiveMap[$materialId] ?? null
                    );
                } else {
                    $roundSteelFields = self::buildStocktakeRoundSteelFields($item, $materialId);
                }

                $insertData[] = [
                    'inventory_code' => $inventoryCodes[$index] ?? PublicService::getCode(10)['code'],
                    'inventory_date' => $stocktakeDate,
                    'process_name' => $processName,
                    'material_id' => $roundSteelFields['material_id'],
                    'material_name' => $roundSteelFields['material_name'],
                    'y_material_manufacturer' => $roundSteelFields['y_material_manufacturer'],
                    'y_material_spec' => $roundSteelFields['y_material_spec'],
                    'y_material_model' => $roundSteelFields['y_material_model'],
                    'c_material_spec' => $normalizedSpec,
                    'c_material_model' => $normalizedModel,
                    'c_product_spec' => $item['c_product_spec'] ?? '',
                    'finished_product_spec' => $item['c_product_model'] ?? $item['c_material_model'] ?? '',
                    'day_shift_tonnage' => $dayTonnage,
                    'night_shift_tonnage' => $nightTonnage,
                    'total_tonnage' => round($dayTonnage + $nightTonnage, 3),
                    'remark' => $remark,
                    'source' => 'manual',
                    'confirmed' => 1,
                    'create_uid' => $userId,
                    'create_time' => $nowTime,
                    'update_uid' => $userId,
                    'update_time' => $nowTime,
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
            $recordRow = is_array($record) ? $record : $record->toArray();
            $mergedRow = array_merge($recordRow, $param);
            if (($mergedRow['process_name'] ?? '') === '圆钢') {
                $materialId = (int) ($mergedRow['material_id'] ?? 0);
                if ($materialId > 0) {
                    $archive = (new MaterialModel())->find($materialId);
                    if (!empty($archive)) {
                        $archiveRow = is_array($archive) ? $archive : $archive->toArray();
                        $mergedRow = self::resolveRoundSteelHistoryFields($mergedRow, $archiveRow);
                    }
                }
                $param = array_merge(
                    $param,
                    self::buildStocktakeRoundSteelFields($mergedRow, $materialId)
                );
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
     * 盘点品种唯一键（工序 + 圆钢 + 成品规格 + 成品型号）
     */
    private static function baselineItemKey(array $row): string
    {
        if (($row['process_name'] ?? '') === '圆钢') {
            return self::roundSteelCalculationIdentityKey($row)
                . "\x00" . self::normalizeSpec($row['c_material_spec'] ?? '')
                . "\x00" . self::normalizeSpec($row['c_material_model'] ?? '');
        }

        return implode("\x00", [
            $row['process_name'] ?? '',
            trim($row['material_name'] ?? ''),
            trim($row['y_material_manufacturer'] ?? ''),
            trim($row['y_material_spec'] ?? ''),
            trim($row['y_material_model'] ?? ''),
            self::normalizeSpec($row['c_material_spec'] ?? ''),
            self::normalizeSpec($row['c_material_model'] ?? ''),
        ]);
    }

    /**
     * 圆钢品种身份：类别(溯源) + 规格(邯钢) + 型号(55/70)
     * 兼容历史 material_name = "邯钢｜70" 写法
     */
    private static function parseRoundSteelIdentity(array $row): array
    {
        $category = trim($row['material_name'] ?? '');
        $manufacturer = trim($row['y_material_manufacturer'] ?? '');
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

        return [
            'category' => $category,
            'manufacturer' => $manufacturer,
            'spec' => $spec,
            'model' => $model,
        ];
    }

    /**
     * 按规格+型号解析圆钢档案 material_id（避免多条盘点共用错误 id）
     */
    private static function resolveRoundSteelMaterialId(
        string $category,
        string $spec,
        string $model,
        string $manufacturer = ''
    ): int
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
        if ($manufacturer !== '') {
            $query->where('manufacturer', $manufacturer);
        }
        $snapshot = [
            'material_id' => 0,
            'y_material_name' => $category,
            'y_material_manufacturer' => $manufacturer,
            'y_material_spec' => $spec,
            'y_material_model' => $model,
        ];
        $candidate = self::selectRoundSteelArchiveCandidate(
            $snapshot,
            $query->select()->toArray()
        );

        return (int) ($candidate['id'] ?? 0);
    }

    private static function normalizeRoundSteelRow(array $row): array
    {
        if (($row['process_name'] ?? '') !== '圆钢') {
            return $row;
        }
        /* [AI-2026-08] 有效 material_id 优先当前档案；仅缺失/失效才按快照回退解析 */
        $materialId = (int) ($row['material_id'] ?? 0);
        if ($materialId > 0) {
            $archive = Db::name('material')
                ->where('id', $materialId)
                ->where('is_delete', 0)
                ->find();
            if (self::isRoundSteelArchive($archive)) {
                return self::resolveRoundSteelHistoryFields($row, $archive);
            }
            $row['material_id'] = 0;
        }
        $identity = self::parseRoundSteelIdentity($row);
        $row['material_name'] = $identity['category'];
        $row['y_material_manufacturer'] = $identity['manufacturer'];
        $row['y_material_spec'] = $identity['spec'];
        $row['y_material_model'] = $identity['model'];
        $resolvedMid = self::resolveRoundSteelMaterialId(
            $identity['category'],
            $identity['spec'],
            $identity['model'],
            $identity['manufacturer']
        );
        if ($resolvedMid > 0) {
            $row['material_id'] = $resolvedMid;
        }
        return $row;
    }

    private static function calcResultDedupeKey(array $row): string
    {
        if (($row['process_name'] ?? '') === '圆钢') {
            return '圆钢' . "\x00" . self::roundSteelCalculationIdentityKey($row);
        }
        return self::baselineItemKey($row);
    }

    private static function dedupeCalcResults(array $result): array
    {
        $seen = [];
        $out = [];
        foreach ($result as $item) {
            $item = self::normalizeRoundSteelRow($item);
            $keys = ($item['process_name'] ?? '') === '圆钢'
                ? self::roundSteelCalculationIdentityKeys($item)
                : [self::calcResultDedupeKey($item)];
            $alreadySeen = ($item['process_name'] ?? '') === '圆钢'
                ? self::roundSteelCalculationIdentityWasSeen($seen, $item)
                : isset($seen[$keys[0]]);
            if ($alreadySeen) {
                continue;
            }
            foreach ($keys as $key) {
                $seen[$key] = true;
            }
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
        $query = Db::name('material_inventory')
            ->where('is_delete', 0)
            ->where('confirmed', 1)
            ->whereIn('process_name', $processNames)
            ->where('inventory_date', '<=', $calcDate)
            ->where(function ($q) {
                $q->where('source', 'manual')
                    ->whereOr('source', '')
                    ->whereOr('source', null);
            });

        // [AI-2026-08] 重置日后切断更早期初，避免旧品种继续出现在推算列表
        if (strtotime($calcDate) >= strtotime(self::MANUAL_BASELINE_RESET_DATE)) {
            $query->where('inventory_date', '>=', self::MANUAL_BASELINE_RESET_DATE);
        }

        $rows = $query
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
            $keys = ($row['process_name'] ?? '') === '圆钢'
                ? self::roundSteelCalculationIdentityKeys($row)
                : [self::calcResultDedupeKey($row)];
            $alreadySeen = ($row['process_name'] ?? '') === '圆钢'
                ? self::roundSteelCalculationIdentityWasSeen($seen, $row)
                : isset($seen[$keys[0]]);
            if ($alreadySeen) {
                continue;
            }
            foreach ($keys as $key) {
                $seen[$key] = true;
            }
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
     * 区间报工合计。有 material_id 只按档案 ID；禁止「溯源+轴承钢+型号」跨钢厂串扣。
     *
     * @param array<string, mixed> $dailyIndex
     */
    private static function sumReportDaily(array $dailyIndex, int $mid, string $code, string $startDate, string $endDate, string $name, string $spec, string $model = ''): float
    {
        if (strtotime($startDate) > strtotime($endDate)) {
            return 0;
        }
        $spec = trim($spec);
        $model = trim($model);
        $name = trim($name);
        $sum = 0.0;
        // [AI-2026-08] 钢厂串扣修复：有档案 ID 不走无钢厂 variety / spec_model
        if ($mid > 0) {
            if (!empty($dailyIndex['id'][$mid][$code])) {
                foreach ($dailyIndex['id'][$mid][$code] as $d => $qty) {
                    if ($d >= $startDate && $d <= $endDate) {
                        $sum += floatval($qty);
                    }
                }
            }
            return $sum;
        }
        if ($model !== '') {
            return 0.0;
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
     * 报工按圆钢 + 工序码 + 计划成品规格/型号分日汇总。
     *
     * @return array<int, array<string, array<string, array<string, array<string, float>>>>>
     */
    public static function buildFinishedReportQtyIndex(string $minDate, string $maxDate): array
    {
        $index = [];
        if (strtotime($minDate) > strtotime($maxDate)) {
            return $index;
        }

        $reportRows = Db::name('product_report')
            ->alias('pr')
            ->join('material m', 'pr.material_id = m.id')
            ->where('pr.report_date', '>=', $minDate)
            ->where('pr.report_date', '<=', $maxDate)
            ->where('pr.is_delete', 0)
            ->field('pr.material_id, pr.processes_code, pr.relation_code, pr.report_date, pr.report_num')
            ->select()
            ->toArray();
        if ($reportRows === []) {
            return $index;
        }

        $relationCodes = [];
        foreach ($reportRows as $r) {
            $rel = trim((string) ($r['relation_code'] ?? ''));
            if ($rel !== '') {
                $relationCodes[$rel] = true;
            }
        }

        $taskToPlan = [];
        $planCodesDirect = [];
        if ($relationCodes !== []) {
            $taskRows = Db::name('product_task')
                ->whereIn('task_code', array_keys($relationCodes))
                ->where('is_delete', 0)
                ->field('task_code, relation_code')
                ->select()
                ->toArray();
            foreach ($taskRows as $tr) {
                $taskToPlan[trim((string) $tr['task_code'])] = trim((string) $tr['relation_code']);
            }
            foreach (array_keys($relationCodes) as $rel) {
                $planCodesDirect[$taskToPlan[$rel] ?? $rel] = true;
            }
        }

        $planFinished = [];
        $finishedMap = [];
        if ($planCodesDirect !== []) {
            $planRows = Db::name('production')
                ->whereIn('production_plan_code', array_keys($planCodesDirect))
                ->where('is_delete', 0)
                ->field('production_plan_code, english_material')
                ->select()
                ->toArray();
            $finishedIds = [];
            foreach ($planRows as $pr) {
                $fid = intval($pr['english_material'] ?? 0);
                if ($fid > 0) {
                    $planFinished[trim((string) $pr['production_plan_code'])] = $fid;
                    $finishedIds[$fid] = true;
                }
            }
            $finishedMap = $finishedIds === [] ? [] : self::loadFinishedSpecMap(array_keys($finishedIds));
        }

        foreach ($reportRows as $r) {
            $mid = intval($r['material_id'] ?? 0);
            $code = (string) ($r['processes_code'] ?? '');
            $qty = floatval($r['report_num'] ?? 0);
            if ($mid <= 0 || $code === '' || $qty == 0.0) {
                continue;
            }
            $rel = trim((string) ($r['relation_code'] ?? ''));
            $planCode = $taskToPlan[$rel] ?? $rel;
            $finishedId = intval($planFinished[$planCode] ?? 0);
            $finished = $finishedMap[$finishedId] ?? [];
            $cSpec = self::normalizeSpec((string) ($finished['material_spec'] ?? ''));
            $cModel = self::normalizeSpec((string) ($finished['material_model'] ?? ''));
            if ($cSpec === '' && $cModel === '') {
                continue;
            }
            $date = date('Y-m-d', strtotime((string) $r['report_date']));
            $index[$mid][$code][$cSpec][$cModel][$date] =
                ($index[$mid][$code][$cSpec][$cModel][$date] ?? 0.0) + $qty;
        }

        return $index;
    }

    /**
     * @param array<int, array<string, array<string, array<string, array<string, float>>>>> $finishedIndex
     */
    public static function sumFinishedReportDaily(
        array $finishedIndex,
        int $mid,
        string $code,
        string $cSpec,
        string $cModel,
        string $startDate,
        string $endDate
    ): ?float {
        $cSpec = self::normalizeSpec($cSpec);
        $cModel = self::normalizeSpec($cModel);
        if ($mid <= 0 || $code === '' || ($cSpec === '' && $cModel === '')) {
            return null;
        }
        if (empty($finishedIndex[$mid][$code][$cSpec][$cModel])) {
            return null;
        }
        if (strtotime($startDate) > strtotime($endDate)) {
            return 0.0;
        }
        $sum = 0.0;
        $found = false;
        foreach ($finishedIndex[$mid][$code][$cSpec][$cModel] as $d => $qty) {
            if ($d >= $startDate && $d <= $endDate) {
                $sum += floatval($qty);
                $found = true;
            }
        }
        return $found ? $sum : 0.0;
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
            ->where('material_property', '圆钢')
            ->field('id, material_name, manufacturer, material_spec, material_model, material_property, is_delete')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[intval($r['id'])] = $r;
        }
        return $map;
    }

    /**
     * [AI-2026-08] 断钢用计划成品规格；穿孔及以后同时取规格（长度）和型号。
     *
     * @return array{c_material_spec: string, c_material_model: string}
     */
    public static function pickProcessIdentityFromFinishedArchive(
        string $processName,
        string $planSpec,
        string $planModel
    ): array {
        $planSpec = self::normalizeSpec($planSpec);
        $planModel = self::normalizeSpec($planModel);
        if ($processName === '断钢') {
            return [
                'c_material_spec' => $planSpec !== '' ? $planSpec : $planModel,
                'c_material_model' => '',
            ];
        }
        return [
            'c_material_spec' => $planSpec,
            'c_material_model' => $planModel !== '' ? $planModel : $planSpec,
        ];
    }

    /**
     * [AI-2026-08] 断钢用计划成品规格，穿孔及以后优先成品型号（兼容旧调用）。
     *
     * @param string $processName 断钢|穿孔|球化|矫直|酸洗
     * @param string $planSpec 计划成品 material_spec
     * @param string $planModel 计划成品 material_model
     * @return string
     */
    public static function pickProcessSpecFromFinishedArchive(
        string $processName,
        string $planSpec,
        string $planModel
    ): string {
        $identity = self::pickProcessIdentityFromFinishedArchive($processName, $planSpec, $planModel);
        if ($processName === '断钢') {
            return $identity['c_material_spec'];
        }
        return $identity['c_material_model'] !== ''
            ? $identity['c_material_model']
            : $identity['c_material_spec'];
    }

    /**
     * [AI-2026-08] 该工序基线是否已有此圆钢型号（不区分工序规格）。
     *
     * @param array<int, array<string, mixed>> $baseline
     * @return bool
     */
    public static function processBaselineHasMaterial(
        array $baseline,
        string $processName,
        int $materialId,
        string $name = '',
        string $manufacturer = '',
        string $spec = '',
        string $model = '',
        string $cMaterialSpec = '',
        string $cMaterialModel = ''
    ): bool {
        $cMaterialSpec = self::normalizeSpec($cMaterialSpec);
        $cMaterialModel = self::normalizeSpec($cMaterialModel);
        foreach ($baseline as $row) {
            if (($row['process_name'] ?? '') !== $processName) {
                continue;
            }
            $rowMid = intval($row['material_id'] ?? 0);
            $roundMatched = false;
            if ($materialId > 0 && $rowMid === $materialId) {
                $roundMatched = true;
            } elseif (
                $materialId <= 0
                && trim((string) ($row['material_name'] ?? '')) === trim($name)
                && trim((string) ($row['y_material_manufacturer'] ?? '')) === trim($manufacturer)
                && trim((string) ($row['y_material_spec'] ?? '')) === trim($spec)
                && trim((string) ($row['y_material_model'] ?? '')) === trim($model)
            ) {
                $roundMatched = true;
            }
            if (!$roundMatched) {
                continue;
            }
            if ($cMaterialSpec === '' && $cMaterialModel === '') {
                return true;
            }
            $rowSpec = self::normalizeSpec($row['c_material_spec'] ?? '');
            $rowModel = self::normalizeSpec($row['c_material_model'] ?? '');
            if ($cMaterialSpec !== '' && $rowSpec !== $cMaterialSpec) {
                continue;
            }
            if ($cMaterialModel !== '' && $rowModel !== $cMaterialModel) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * [AI-2026-08] 盘点没有、但重置日后有报工的工序行：期初 0 补进日推算。
     *
     * @param array<int, array<string, mixed>> $baseline
     * @return array<int, array<string, mixed>>
     */
    private static function appendDailyMissingProcessFromReport(
        array $baseline,
        string $resetDate,
        string $calcDate
    ): array {
        $codeToProcess = [
            'gx_0010' => '断钢',
            'gx_0011' => '穿孔',
            'gx_0012' => '球化',
            'gx_0013' => '矫直',
            'gx_0014' => '酸洗',
        ];
        if (strtotime($resetDate) > strtotime($calcDate)) {
            return $baseline;
        }

        $reportRows = Db::name('product_report')
            ->alias('pr')
            ->join('material m', 'pr.material_id = m.id AND m.is_delete = 0 AND m.material_property = \'圆钢\'')
            ->where('pr.is_delete', 0)
            ->where('pr.report_date', '>=', $resetDate)
            ->where('pr.report_date', '<=', $calcDate)
            ->whereIn('pr.processes_code', array_keys($codeToProcess))
            ->field(
                'pr.processes_code, pr.relation_code, pr.material_id,
                 m.material_name, m.manufacturer, m.material_spec, m.material_model'
            )
            ->select()
            ->toArray();
        if ($reportRows === []) {
            return $baseline;
        }

        $taskCodes = [];
        $planCodesDirect = [];
        foreach ($reportRows as $r) {
            $rel = trim((string) ($r['relation_code'] ?? ''));
            if ($rel === '') {
                continue;
            }
            $taskCodes[$rel] = true;
            $planCodesDirect[$rel] = true;
        }

        $taskToPlan = [];
        if ($taskCodes !== []) {
            $taskRows = Db::name('product_task')
                ->whereIn('task_code', array_keys($taskCodes))
                ->where('is_delete', 0)
                ->field('task_code, relation_code')
                ->select()
                ->toArray();
            foreach ($taskRows as $tr) {
                $plan = trim((string) ($tr['relation_code'] ?? ''));
                if ($plan !== '') {
                    $taskToPlan[trim((string) $tr['task_code'])] = $plan;
                    $planCodesDirect[$plan] = true;
                }
            }
        }

        $planFinished = [];
        if ($planCodesDirect !== []) {
            $planRows = Db::name('production')
                ->whereIn('production_plan_code', array_keys($planCodesDirect))
                ->where('is_delete', 0)
                ->field('production_plan_code, english_material')
                ->select()
                ->toArray();
            $finishedIds = [];
            foreach ($planRows as $pr) {
                $fid = intval($pr['english_material'] ?? 0);
                if ($fid > 0) {
                    $planFinished[trim((string) $pr['production_plan_code'])] = $fid;
                    $finishedIds[$fid] = true;
                }
            }
            $finishedMap = $finishedIds === [] ? [] : self::loadFinishedSpecMap(array_keys($finishedIds));
        } else {
            $finishedMap = [];
        }

        $seenAppend = [];
        foreach ($reportRows as $r) {
            $processName = $codeToProcess[$r['processes_code'] ?? ''] ?? '';
            $mid = intval($r['material_id'] ?? 0);
            if ($processName === '' || $mid <= 0) {
                continue;
            }

            $rel = trim((string) ($r['relation_code'] ?? ''));
            $planCode = $taskToPlan[$rel] ?? $rel;
            $finishedId = intval($planFinished[$planCode] ?? 0);
            $finished = $finishedMap[$finishedId] ?? [];
            $identity = self::pickProcessIdentityFromFinishedArchive(
                $processName,
                (string) ($finished['material_spec'] ?? ''),
                (string) ($finished['material_model'] ?? '')
            );
            $cSpec = $identity['c_material_spec'];
            $cModel = $identity['c_material_model'];
            if (self::processBaselineHasMaterial(
                $baseline,
                $processName,
                $mid,
                '',
                '',
                '',
                '',
                $cSpec,
                $cModel
            )) {
                continue;
            }

            $dedupeKey = $processName . "\x00" . $mid . "\x00" . self::normalizeSpec($cSpec)
                . "\x00" . self::normalizeSpec($cModel);
            if (isset($seenAppend[$dedupeKey])) {
                continue;
            }
            $seenAppend[$dedupeKey] = true;

            $baseline[] = [
                'process_name' => $processName,
                'material_id' => $mid,
                'material_name' => (string) ($r['material_name'] ?? ''),
                'y_material_name' => (string) ($r['material_name'] ?? ''),
                'y_material_manufacturer' => (string) ($r['manufacturer'] ?? ''),
                'y_material_spec' => (string) ($r['material_spec'] ?? ''),
                'y_material_model' => (string) ($r['material_model'] ?? ''),
                'c_material_spec' => $cSpec,
                'c_material_model' => $cModel,
                'total_tonnage' => 0,
                'inventory_date' => $resetDate,
                'source' => 'manual',
                'is_report_fill' => 1,
            ];
        }

        return $baseline;
    }

    /**
     * @param int[] $materialIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadFinishedSpecMap(array $materialIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
        if ($ids === []) {
            return [];
        }
        $rows = Db::name('material')
            ->whereIn('id', $ids)
            ->where('is_delete', 0)
            ->field('id, material_spec, material_model')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[intval($row['id'] ?? 0)] = $row;
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
        $finishedDaily = self::buildFinishedReportQtyIndex($minBaselineDate, $calcDate);
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

        // Helper: 读取今日报工。有档案 ID 只按 ID，禁止规格+型号跨钢厂串扣。
        $getTodayReport = function ($code, $mid, $name, $spec, $model = '') use (&$todayReportIdx, &$todayNameReportIdx) {
            $spec = trim((string) $spec);
            $name = trim((string) $name);
            $mid = intval($mid);
            // [AI-2026-08] 钢厂串扣修复
            if ($mid > 0) {
                return floatval($todayReportIdx[$mid][$code] ?? 0);
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

        // [AI-2026-08] 圆钢基线优先 material_id，否则按含厂商四字段合并。
        $baseline = self::mergeRoundSteelBaselineRows($baseline);
        // [AI-2026-08] 盘点未覆盖但已报工的工序（如矫直从 0 干出来）补进推算
        $baseline = self::appendDailyMissingProcessFromReport(
            $baseline,
            self::MANUAL_BASELINE_RESET_DATE,
            $calcDate
        );

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
            $rowCSpec = (string) ($stock['c_material_spec'] ?? '');
            $rowCModel = (string) ($stock['c_material_model'] ?? '');

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
                    $matchKey,
                    $rowCModel
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
                    $matchKey,
                    $rowCModel
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
                    $matchedCurrent = self::sumFinishedReportDaily(
                        $finishedDaily,
                        $stockMaterialId,
                        $currentCode,
                        $rowCSpec,
                        $rowCModel,
                        $itemStartDate,
                        $itemEndDate
                    );
                    $matchedNext = $nextCode
                        ? self::sumFinishedReportDaily(
                            $finishedDaily,
                            $stockMaterialId,
                            $nextCode,
                            $rowCSpec,
                            $rowCModel,
                            $itemStartDate,
                            $itemEndDate
                        )
                        : 0.0;
                    $currentQty = $matchedCurrent !== null ? $matchedCurrent : ($currentQty * $todaySpecRatio);
                    $nextQty = $nextCode
                        ? ($matchedNext !== null ? $matchedNext : ($nextQty * $todaySpecRatio))
                        : 0.0;

                    $predictedQty = $baselineQty + $currentQty - $nextQty;
                    $calcDetail = [
                        'baseline'       => round($baselineQty, 3),
                        'current_output' => round($currentQty, 3),
                        'next_consume'   => round($nextQty, 3),
                        'match_type'     => $matchedCurrent !== null ? 'finished+len' : 'mid+prop',
                        'proportion'     => round($todaySpecRatio, 4),
                    ];

                    // 酸洗（最后工序）：扣减发货出库
                    if ($flow['type'] === 'finish') {
                        $shipQty = self::sumShipDaily($shipDaily, $stockMaterialId, $itemStartDate, $itemEndDate);
                        $shipQty = $shipQty * $todaySpecRatio;
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
                $todayMatchedOut = self::sumFinishedReportDaily(
                    $finishedDaily,
                    $stockMaterialId,
                    $code,
                    $rowCSpec,
                    $rowCModel,
                    $calcDate,
                    $calcDate
                );
                $todayMatchedNext = $nextCode
                    ? self::sumFinishedReportDaily(
                        $finishedDaily,
                        $stockMaterialId,
                        $nextCode,
                        $rowCSpec,
                        $rowCModel,
                        $calcDate,
                        $calcDate
                    )
                    : 0.0;
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
                $todayOut = $todayMatchedOut !== null ? $todayMatchedOut : ($todayOutRaw * $todaySpecRatio);
                $todayNext = $nextCode
                    ? ($todayMatchedNext !== null ? $todayMatchedNext : 0.0)
                    : 0.0;
                if ($todayMatchedNext === null && $nextCode) {
                    $todayConsume = $todayConsumeRaw * $todaySpecRatio;
                } elseif ($flow['type'] === 'finish') {
                    $todayShip = $stockMaterialId > 0 ? (($todayShipIdx[$stockMaterialId] ?? 0) * $todaySpecRatio) : 0;
                    $todayConsume = $todayNext + $todayShip;
                } else {
                    $todayConsume = $todayNext;
                }
                $todayConsumeDetails = self::scaleTodayReportDetails(
                    $rawConsumeDetails,
                    $todayMatchedNext !== null ? 1.0 : $todaySpecRatio
                );
            }
            $beginToday = round($predictedQty, 3); // predicted_qty = 昨日结存
            $todayPredicted = $beginToday + $todayIn + $todayOut - $todayConsume;

            $resultRow = [
                'process_name'       => $processName,
                'material_id'        => $stockMaterialId,
                'material_name'      => $materialName,
                'y_material_manufacturer' => $stock['y_material_manufacturer'] ?? '',
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
        // [AI-2026-08] 查重优先 material_id，否则使用含厂商的圆钢四字段身份。
        // [AI-2026-08] 全厂盘点重置后：只显示重置日手动盘点品种，关闭「新入库自动检出」混入旧/未盘品种。
        $skipNewInboundDetect = strtotime($calcDate) >= strtotime(self::MANUAL_BASELINE_RESET_DATE);

        // 新入库圆钢（期初无该品种）：从全局最早手动期初次日起至推算日前一天
        $newInboundMidSeen = [];
        $newInboundMaterialMap = self::loadMaterialBriefMap(array_keys($inboundDaily['id'] ?? []));
        if (
            !$skipNewInboundDetect
            && strtotime($newRawStartDate) <= strtotime($globalEndDate)
            && !empty($inboundDaily['id'])
        ) {
            foreach ($inboundDaily['id'] as $mid => $dateQty) {
                $mid = intval($mid);
                $matRow = $newInboundMaterialMap[$mid] ?? null;
                if (empty($matRow) || trim($matRow['material_name'] ?? '') === '') {
                    continue;
                }
                $rName = trim($matRow['material_name']);
                $rManufacturer = trim($matRow['manufacturer'] ?? '');
                $rSpec = trim($matRow['material_spec'] ?? '');
                $rModel = trim($matRow['material_model'] ?? '');
                $candidate = [
                    'process_name' => '圆钢',
                    'material_id' => $mid,
                    'material_name' => $rName,
                    'y_material_manufacturer' => $rManufacturer,
                    'y_material_spec' => $rSpec,
                    'y_material_model' => $rModel,
                ];
                if ($mid <= 0 || self::hasRoundSteelIdentity($baseline, $candidate)) {
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
                    'y_material_manufacturer' => $rManufacturer,
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
        if (
            !$skipNewInboundDetect
            && strtotime($newRawStartDate) <= strtotime($globalEndDate)
            && !empty($inboundDaily['name'])
        ) {
            foreach ($inboundDaily['name'] as $rName => $specMap) {
                foreach ($specMap as $rSpec => $dateQty) {
                    if ($rName === '' || $rSpec === '') {
                        continue;
                    }
                    $archiveCandidates = Db::name('material')
                        ->where('is_delete', 0)
                        ->where('material_property', '圆钢')
                        ->where('material_name', $rName)
                        ->where('material_spec', $rSpec)
                        ->field('id,material_name,manufacturer,material_spec,material_model')
                        ->limit(2)
                        ->select()
                        ->toArray();
                    $archive = count($archiveCandidates) === 1 ? $archiveCandidates[0] : null;
                    $existMid = (int) ($archive['id'] ?? 0);
                    if ($existMid && isset($newInboundMidSeen[intval($existMid)])) {
                        continue;
                    }
                    $candidate = [
                        'process_name' => '圆钢',
                        'material_id' => $existMid,
                        'material_name' => $rName,
                        'y_material_manufacturer' => (string) ($archive['manufacturer'] ?? ''),
                        'y_material_spec' => $rSpec,
                        'y_material_model' => (string) ($archive['material_model'] ?? ''),
                    ];
                    if (self::hasRoundSteelIdentity($baseline, $candidate)) {
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
                        'y_material_manufacturer' => $candidate['y_material_manufacturer'],
                        'y_material_spec'  => $rSpec,
                        'y_material_model' => $candidate['y_material_model'],
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
            // 负数（异常）保留；有期初盘点日期的行（含 0 吨锁品种）保留；其余零值隐藏
            if (($item['predicted_qty'] ?? 0) != 0) {
                return true;
            }
            if (!empty($item['baseline_date'])) {
                return true;
            }
            return !empty($item['is_manual']) || !empty($item['is_history']);
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
        $resetDate = self::MANUAL_BASELINE_RESET_DATE;
        $resetInMonth = strtotime($resetDate) >= strtotime($monthStart)
            && strtotime($resetDate) <= strtotime($monthEnd);

        foreach ($processNames as $pn) {
            $date = null;

            // [AI-2026-08] 重置日落在本月内：以全厂盘点日为期初，不再用更早 import/旧手动
            if ($resetInMonth) {
                $hasReset = Db::name('material_inventory')
                    ->where('is_delete', 0)
                    ->where('confirmed', 1)
                    ->where('process_name', $pn)
                    ->where('inventory_date', $resetDate)
                    ->where(function ($q) {
                        $q->where('source', 'manual')
                            ->whereOr('source', '')
                            ->whereOr('source', null);
                    })
                    ->count();
                if ($hasReset > 0) {
                    $date = $resetDate;
                }
            }

            if (!$date) {
                // 仅取手动期初；重置月之后不得回落到重置日前
                $sql = "SELECT MAX(inventory_date) AS d FROM ya_material_inventory
                        WHERE is_delete=0 AND confirmed=1 AND process_name = ?
                          AND inventory_date < ?
                          AND (source='manual' OR source='' OR source IS NULL)";
                $params = [$pn, $monthStart];
                if (strtotime($monthStart) > strtotime($resetDate)) {
                    $sql .= ' AND inventory_date >= ?';
                    $params[] = $resetDate;
                }
                $dateRow = Db::query($sql, $params);
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
                ->where(function ($q) {
                    $q->where('source', 'manual')
                        ->whereOr('source', '')
                        ->whereOr('source', null);
                })
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
            ->field('m.id as material_id, m.material_name, m.manufacturer, m.material_spec,
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

        // [AI-2026-08] 全厂盘点重置后：月台账只展示重置日手动盘点品种，禁止按流水自动补品种
        $skipMonthlyMissingAppend = $resetInMonth
            || strtotime($monthStart) >= strtotime($resetDate);
        if (!$skipMonthlyMissingAppend) {
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
        }
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
        $finishedMonthly = self::buildFinishedReportQtyIndex($monthStart, $monthEnd);
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
            // [AI-2026-08] 圆钢行规范化后吐出四字段，供月台账「品种」列展示
            if (($stock['process_name'] ?? '') === '圆钢') {
                $stock = self::normalizeRoundSteelRow($stock);
            }
            $processName = $stock['process_name'];
            $materialName = $stock['material_name'];
            $stockMid = intval($stock['material_id'] ?? 0);
            $baselineQty = floatval($stock['total_tonnage']);
            $matchKey = self::extractSpecKey($stock['c_material_spec'] ?? '');
            $yName = trim((string) ($stock['y_material_name'] ?? $stock['material_name'] ?? ''));
            $yManufacturer = trim((string) ($stock['y_material_manufacturer'] ?? ''));
            $ySpec = $stock['y_material_spec'] ?? '';
            $yModel = $stock['y_material_model'] ?? '';
            $cSpec = $stock['c_material_spec'] ?? '';
            $cModel = $stock['c_material_model'] ?? '';
            $rowRatio = self::calcProcessBaselineRatio(
                $baseline,
                $processName,
                $stockMid,
                $materialName,
                $ySpec,
                $yModel,
                $matchKey,
                $cModel
            );

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
                    $matchedOut = self::sumFinishedReportDaily(
                        $finishedMonthly,
                        $stockMid,
                        $currentCode,
                        $cSpec,
                        $cModel,
                        $iterDate,
                        $iterDate
                    );
                    $output = $matchedOut !== null
                        ? $matchedOut
                        : ($getDayTotal($iterDate, $stockMid, $currentCode, $materialName, $ySpec) * $rowRatio);
                    if ($nextCode) {
                        $matchedNext = self::sumFinishedReportDaily(
                            $finishedMonthly,
                            $stockMid,
                            $nextCode,
                            $cSpec,
                            $cModel,
                            $iterDate,
                            $iterDate
                        );
                        $consume = $matchedNext !== null
                            ? $matchedNext
                            : ($getDayTotal($iterDate, $stockMid, $nextCode, $materialName, $ySpec) * $rowRatio);
                    }
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
                    $shipQty = $shipQty * $rowRatio;
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
                // [AI-2026-08] 品种列用圆钢四字段；物料型号列用 c_material_spec
                'y_material_name'  => $yName,
                'y_material_manufacturer' => $yManufacturer,
                'y_material_spec'  => $ySpec,
                'y_material_model' => $yModel,
                'c_material_spec'  => $cSpec,
                'c_material_model' => $cModel,
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
        // [AI-2026-08] 重置日后不再自动补「Excel未盘」品种
        if ($skipMonthlyMissingAppend) {
            $inboundRows = [];
        }
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

            $nm = $r['material_name'] ?? '';   // 圆钢名称（如"溯源"）
            $mf = trim((string) ($r['manufacturer'] ?? '')); // 厂商
            $sp = $r['material_spec'] ?? '';   // 材质
            $md = $r['material_model'] ?? '';  // 型号

            // 仅加入与期初同类别的新入库圆钢（排除不同类别的物料，如凯亚）
            if (!empty($baselineCategoryNames) && !isset($baselineCategoryNames[$nm])) {
                continue;
            }

            $alreadyIn = false;
            $candidateKey = self::calcResultDedupeKey(self::normalizeRoundSteelRow([
                'process_name'     => '圆钢',
                'material_name'    => $nm,
                'y_material_name'  => $nm,
                'y_material_manufacturer' => $mf,
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
                    'y_material_name'  => $rr['y_material_name'] ?? ($rr['category'] ?? $rr['material_name'] ?? ''),
                    'y_material_manufacturer' => $rr['y_material_manufacturer'] ?? '',
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
                'material_name'    => $nm,
                'category'         => $nm,
                'y_material_name'  => $nm,
                'y_material_manufacturer' => $mf,
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
                    'y_material_name'  => $hr['y_material_name'] ?? ($hr['material_name'] ?? ''),
                    'y_material_manufacturer' => $hr['y_material_manufacturer'] ?? '',
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
