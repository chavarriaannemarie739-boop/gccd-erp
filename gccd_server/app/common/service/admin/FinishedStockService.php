<?php

namespace app\common\service\admin;

use app\common\model\admin\ProductionModel;
use app\common\model\admin\ShipModel;
use app\common\model\admin\StockAdjustModel;
use app\common\model\admin\StockMaterialModel;
use app\common\model\admin\StockOwnerTransferModel;
use app\common\model\system\RoleModel;
use app\common\model\system\UserAttributesModel;
use app\common\model\system\UserModel;
use app\common\model\admin\WmsMaterialModel;
use think\facade\Db;

/**
 * 成品库归属、调整、发货容差
 */
class FinishedStockService
{
    public const STOCK_OWNER_INVENTORY = '库存';

    public const ADJUST_WRITE_OFF = 'write_off';

    public const ADJUST_OVER_SHIP = 'over_ship';

    /** 酸洗盘点把成品库存覆盖成实盘数 */
    public const ADJUST_STOCKTAKE = 'stocktake';

    /** 发货超发容差比例 ±2% */
    public const SHIP_OVER_TOLERANCE_RATIO = 0.02;

    /**
     * 当前用户成品库操作权限
     *
     * @return array{canTransfer:bool,canWriteOff:bool,isSuper:bool}
     */
    public static function actionPermissions(): array
    {
        if (self::isSuperUser()) {
            return [
                'canTransfer' => true,
                'canWriteOff' => true,
                'isSuper' => true,
            ];
        }
        $roleNames = self::currentUserRoleNames();

        return [
            'canTransfer' => self::roleMatchAny($roleNames, ['主管', '计划', '管理员', '计划员']),
            'canWriteOff' => self::roleMatchAny($roleNames, ['主管', '管理员']),
            'isSuper' => false,
        ];
    }

    /**
     * 部分转库存
     *
     * @param array $param plan_code, transfer_qty, reason
     * @return array
     */
    public static function transferToStock(array $param): array
    {
        $perms = self::actionPermissions();
        if (!$perms['canTransfer']) {
            exception('无权限：转库存需主管或计划员操作');
        }

        $planCode = trim((string) ($param['plan_code'] ?? ''));
        $qty = round((float) ($param['transfer_qty'] ?? 0), 2);
        $reason = trim((string) ($param['reason'] ?? ''));

        if ($planCode === '') {
            exception('计划单号不能为空');
        }
        if ($qty <= 0) {
            exception('转库存数量须大于 0');
        }

        $plan = (new ProductionModel())
            ->where('production_plan_code', $planCode)
            ->where('is_delete', 0)
            ->find();
        if (empty($plan)) {
            exception('生产计划不存在');
        }
        $planArr = $plan->toArray();
        $customerOwner = trim((string) ($planArr['production_plan_name'] ?? ''));
        if ($customerOwner === '' || $customerOwner === self::STOCK_OWNER_INVENTORY) {
            exception('该计划无法从客户归属转出');
        }

        $summary = self::planSummary($planCode, $planArr);
        $available = (float) ($summary['owners'][$customerOwner]['stock_num'] ?? 0);
        if ($qty > $available + 0.001) {
            exception('转库存数量不能超过客户归属可用库存（当前：' . $available . '）');
        }

        $materialId = (int) ($summary['material_id'] ?? 0);
        Db::startTrans();
        try {
            $code = PublicService::getCode(14)['code'] ?? ('zy-' . date('YmdHis') . mt_rand(100, 999));
            (new StockOwnerTransferModel())->insert([
                'transfer_code' => $code,
                'plan_code' => $planCode,
                'material_id' => $materialId,
                'material_batch' => $planCode,
                'from_owner' => $customerOwner,
                'to_owner' => self::STOCK_OWNER_INVENTORY,
                'transfer_qty' => $qty,
                'reason' => $reason !== '' ? $reason : '客户余量转库存',
                'create_uid' => user_id(),
                'create_time' => datetime(),
                'is_delete' => 0,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('转库存失败:' . $e->getMessage());
        }

        return [
            'transfer_code' => $code,
            'plan_code' => $planCode,
            'transfer_qty' => $qty,
        ];
    }

    /**
     * 主管清零
     *
     * @param array $param plan_code, stock_owner, write_off_qty, reason
     * @return array
     */
    public static function writeOff(array $param): array
    {
        $perms = self::actionPermissions();
        if (!$perms['canWriteOff']) {
            exception('无权限：库存清零需主管操作');
        }

        $planCode = trim((string) ($param['plan_code'] ?? ''));
        $stockOwner = trim((string) ($param['stock_owner'] ?? ''));
        $qty = round((float) ($param['write_off_qty'] ?? 0), 2);
        $reason = trim((string) ($param['reason'] ?? ''));

        if ($planCode === '') {
            exception('计划单号不能为空');
        }
        if ($stockOwner === '') {
            exception('归属不能为空');
        }
        if ($qty <= 0) {
            exception('清零数量须大于 0');
        }
        if ($reason === '') {
            exception('请填写清零原因');
        }

        $plan = (new ProductionModel())
            ->where('production_plan_code', $planCode)
            ->where('is_delete', 0)
            ->find();
        if (empty($plan)) {
            exception('生产计划不存在');
        }
        $planArr = $plan->toArray();
        $summary = self::planSummary($planCode, $planArr);
        $available = (float) ($summary['owners'][$stockOwner]['stock_num'] ?? 0);
        if ($qty > $available + 0.001) {
            exception('清零数量不能超过当前归属可用库存（当前：' . $available . '）');
        }

        $materialId = (int) ($summary['material_id'] ?? 0);
        $afterQty = round($available - $qty, 2);
        $adjustCode = PublicService::getCode(15)['code'] ?? ('tz-' . date('YmdHis') . mt_rand(100, 999));

        Db::startTrans();
        try {
            (new StockAdjustModel())->insert([
                'adjust_code' => $adjustCode,
                'plan_code' => $planCode,
                'material_id' => $materialId,
                'material_batch' => $planCode,
                'stock_owner' => $stockOwner,
                'adjust_type' => self::ADJUST_WRITE_OFF,
                'adjust_qty' => -$qty,
                'before_qty' => $available,
                'after_qty' => $afterQty,
                'reason' => $reason,
                'ship_code' => '',
                'create_uid' => user_id(),
                'create_time' => datetime(),
                'is_delete' => 0,
            ]);
            self::deductWmsMaterial($materialId, $planCode, $qty);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('库存清零失败:' . $e->getMessage());
        }

        return [
            'adjust_code' => $adjustCode,
            'plan_code' => $planCode,
            'stock_owner' => $stockOwner,
            'write_off_qty' => $qty,
        ];
    }

    /**
     * 发货前校验（含 ±2% 容差）
     *
     * @param string $planCode
     * @param string $stockOwner
     * @param float  $shipQty
     * @return array{allowed:bool,available:float,max_allowed:float,over_qty:float}
     */
    public static function validateShipQty(string $planCode, string $stockOwner, float $shipQty): array
    {
        $plan = (new ProductionModel())
            ->where('production_plan_code', $planCode)
            ->where('is_delete', 0)
            ->find();
        if (empty($plan)) {
            exception('生产计划不存在：' . $planCode);
        }
        $summary = self::planSummary($planCode, $plan->toArray());
        if ($stockOwner === '') {
            $stockOwner = trim((string) ($plan['production_plan_name'] ?? ''));
        }
        $available = (float) ($summary['owners'][$stockOwner]['stock_num'] ?? 0);
        $maxAllowed = round($available * (1 + self::SHIP_OVER_TOLERANCE_RATIO), 2);
        $shipQty = round($shipQty, 2);
        $overQty = $shipQty > $available ? round($shipQty - $available, 2) : 0.0;

        return [
            'allowed' => $shipQty <= $maxAllowed + 0.001,
            'available' => $available,
            'max_allowed' => $maxAllowed,
            'over_qty' => $overQty,
        ];
    }

    /**
     * 发货扣减 wms（不允许物理库存为负）
     *
     * @param int    $materialId
     * @param string $materialBatch
     * @param float  $qty
     * @return void
     */
    public static function deductWmsMaterial(int $materialId, string $materialBatch, float $qty): void
    {
        if ($materialId <= 0 || $materialBatch === '' || $qty <= 0) {
            return;
        }
        $model = new StockMaterialModel();
        $row = $model
            ->where('material_id', $materialId)
            ->where('material_batch', $materialBatch)
            ->where('is_delete', 0)
            ->find();
        if (empty($row)) {
            return;
        }
        $avail = (float) ($row['material_num'] ?? 0);
        $deduct = min($qty, $avail);
        if ($deduct <= 0) {
            return;
        }
        $model->where('id', $row['id'])->update([
            'material_num' => round($avail - $deduct, 2),
        ]);
    }

    /**
     * 记录超发调整并扣减 wms
     *
     * @param array  $line       发货行
     * @param string $shipCode
     * @param float  $overQty
     * @param float  $beforeQty
     * @return void
     */
    public static function recordOverShipAdjust(array $line, string $shipCode, float $overQty, float $beforeQty): void
    {
        if ($overQty <= 0) {
            return;
        }
        $planCode = trim((string) ($line['relation_code'] ?? $line['material_batch'] ?? ''));
        $stockOwner = trim((string) ($line['stock_owner'] ?? ''));
        $materialId = (int) ($line['material_id'] ?? 0);
        $adjustCode = PublicService::getCode(15)['code'] ?? ('tz-' . date('YmdHis') . mt_rand(100, 999));
        (new StockAdjustModel())->insert([
            'adjust_code' => $adjustCode,
            'plan_code' => $planCode,
            'material_id' => $materialId,
            'material_batch' => $planCode,
            'stock_owner' => $stockOwner,
            'adjust_type' => self::ADJUST_OVER_SHIP,
            'adjust_qty' => -$overQty,
            'before_qty' => $beforeQty,
            'after_qty' => round($beforeQty - $overQty, 2),
            'reason' => '发货超发容差内放行',
            'ship_code' => $shipCode,
            'create_uid' => user_id(),
            'create_time' => datetime(),
            'is_delete' => 0,
        ]);
    }

    /**
     * 将计划维度基础行展开为按归属的多行列表
     * [AI-2026-08] 批量预取转出/发货/调整/物料ID，避免每个计划 3～4 次查询导致选料弹窗缓冲很慢
     *
     * @param array[] $baseRows productWarehouseList SQL 结果
     * @return array[]
     */
    public static function expandOwnerRows(array $baseRows): array
    {
        $planCodes = [];
        foreach ($baseRows as $row) {
            $planCode = trim((string) ($row['plan_code'] ?? $row['relation_code'] ?? ''));
            if ($planCode !== '') {
                $planCodes[$planCode] = true;
            }
        }
        $planCodes = array_keys($planCodes);
        if ($planCodes === []) {
            return [];
        }

        $batch = self::batchPlanStockMaps($planCodes);

        $needMaterialCodes = [];
        foreach ($baseRows as $row) {
            $planCode = trim((string) ($row['plan_code'] ?? $row['relation_code'] ?? ''));
            if ($planCode === '') {
                continue;
            }
            if ((int) ($row['material_id'] ?? 0) <= 0 && !isset($batch['materialIdByPlan'][$planCode])) {
                $needMaterialCodes[$planCode] = true;
            }
        }
        if ($needMaterialCodes !== []) {
            $batch['materialIdByPlan'] = array_merge(
                $batch['materialIdByPlan'],
                self::batchResolveMaterialIds(array_keys($needMaterialCodes))
            );
        }

        $out = [];
        foreach ($baseRows as $row) {
            $planCode = trim((string) ($row['plan_code'] ?? $row['relation_code'] ?? ''));
            if ($planCode === '') {
                continue;
            }
            $planName = trim((string) ($row['production_plan_name'] ?? ''));
            $inNum = round((float) ($row['in_num'] ?? 0), 2);
            $materialId = (int) ($row['material_id'] ?? 0);
            if ($materialId <= 0) {
                $materialId = (int) ($batch['materialIdByPlan'][$planCode] ?? 0);
            }
            $shippedByOwner = $batch['shippedByPlanOwner'][$planCode] ?? [];
            // 发货未写归属时并入客户归属（与 planSummary 一致）
            if (isset($shippedByOwner['']) && $planName !== '') {
                $shippedByOwner[$planName] = ($shippedByOwner[$planName] ?? 0) + (float) $shippedByOwner[''];
                unset($shippedByOwner['']);
            }
            $summary = self::buildOwnerSummary(
                $planCode,
                $planName,
                $inNum,
                $materialId,
                (float) ($batch['transferByPlan'][$planCode] ?? 0),
                $shippedByOwner,
                $batch['adjustByPlanOwner'][$planCode] ?? []
            );
            foreach ($summary['owners'] as $owner => $ownerRow) {
                $stockNum = round((float) ($ownerRow['stock_num'] ?? 0), 2);
                if ($stockNum <= 0) {
                    continue;
                }
                $item = $row;
                $item['stock_owner'] = $owner;
                $item['production_plan_name'] = $planName;
                $item['owner_label'] = $owner;
                $item['in_num'] = round((float) ($summary['in_num'] ?? 0), 2);
                $item['shipped_num'] = round((float) ($ownerRow['shipped_num'] ?? 0), 2);
                $item['transferred_num'] = round((float) ($ownerRow['transferred_num'] ?? 0), 2);
                $item['stock_num'] = $stockNum;
                $item['ship_max_qty'] = round($stockNum * (1 + self::SHIP_OVER_TOLERANCE_RATIO), 2);
                $item['row_key'] = $planCode . '::' . $owner;
                $item['material_id'] = (int) ($summary['material_id'] ?? ($item['material_id'] ?? 0));
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * 批量预取计划维度库存计算所需数据
     *
     * @param string[] $planCodes
     * @return array{
     *   transferByPlan: array<string,float>,
     *   shippedByPlanOwner: array<string,array<string,float>>,
     *   adjustByPlanOwner: array<string,array<string,float>>,
     *   materialIdByPlan: array<string,int>
     * }
     */
    private static function batchPlanStockMaps(array $planCodes): array
    {
        $transferByPlan = [];
        $shippedByPlanOwner = [];
        $adjustByPlanOwner = [];
        $materialIdByPlan = [];
        if ($planCodes === []) {
            return compact('transferByPlan', 'shippedByPlanOwner', 'adjustByPlanOwner', 'materialIdByPlan');
        }

        $placeholders = implode(',', array_fill(0, count($planCodes), '?'));

        $transferRows = Db::query(
            "SELECT plan_code, COALESCE(SUM(transfer_qty), 0) AS transfer_qty
             FROM ya_stock_owner_transfer
             WHERE is_delete = 0 AND plan_code IN ({$placeholders})
             GROUP BY plan_code",
            $planCodes
        );
        foreach ($transferRows as $tr) {
            $code = trim((string) ($tr['plan_code'] ?? ''));
            if ($code !== '') {
                $transferByPlan[$code] = (float) ($tr['transfer_qty'] ?? 0);
            }
        }

        $shipRows = Db::query(
            "SELECT relation_code, stock_owner, material_num
             FROM ya_ship
             WHERE is_delete = 0 AND relation_code IN ({$placeholders})",
            $planCodes
        );
        foreach ($shipRows as $sr) {
            $code = trim((string) ($sr['relation_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $owner = trim((string) ($sr['stock_owner'] ?? ''));
            $shippedByPlanOwner[$code][$owner] = ($shippedByPlanOwner[$code][$owner] ?? 0)
                + (float) ($sr['material_num'] ?? 0);
        }

        $adjustRows = Db::query(
            "SELECT plan_code, stock_owner, adjust_qty
             FROM ya_stock_adjust
             WHERE is_delete = 0 AND plan_code IN ({$placeholders})",
            $planCodes
        );
        foreach ($adjustRows as $ar) {
            $code = trim((string) ($ar['plan_code'] ?? ''));
            $owner = trim((string) ($ar['stock_owner'] ?? ''));
            if ($code === '' || $owner === '') {
                continue;
            }
            $adjustByPlanOwner[$code][$owner] = ($adjustByPlanOwner[$code][$owner] ?? 0)
                + (float) ($ar['adjust_qty'] ?? 0);
        }

        return compact('transferByPlan', 'shippedByPlanOwner', 'adjustByPlanOwner', 'materialIdByPlan');
    }

    /**
     * 批量解析计划单对应 WMS 物料 ID（取各批次最新一条）
     *
     * @param string[] $planCodes
     * @return array<string,int>
     */
    private static function batchResolveMaterialIds(array $planCodes): array
    {
        $out = [];
        if ($planCodes === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($planCodes), '?'));
        $rows = Db::query(
            "SELECT material_batch, material_id
             FROM ya_wms_material
             WHERE is_delete = 0 AND material_batch IN ({$placeholders})
             ORDER BY id DESC",
            $planCodes
        );
        foreach ($rows as $row) {
            $batch = trim((string) ($row['material_batch'] ?? ''));
            if ($batch === '' || isset($out[$batch])) {
                continue;
            }
            $out[$batch] = (int) ($row['material_id'] ?? 0);
        }

        return $out;
    }

    /**
     * 按预取数据计算归属库存（与 planSummary 规则一致）
     *
     * @param string               $planCode
     * @param string               $customerOwner
     * @param float                $inNum
     * @param int                  $materialId
     * @param float                $transferred
     * @param array<string,float>  $shippedByOwner
     * @param array<string,float>  $adjustByOwner
     * @return array{plan_code:string,material_id:int,in_num:float,owners:array}
     */
    private static function buildOwnerSummary(
        string $planCode,
        string $customerOwner,
        float $inNum,
        int $materialId,
        float $transferred,
        array $shippedByOwner,
        array $adjustByOwner
    ): array {
        $owners = [];
        if ($customerOwner !== '' && $customerOwner !== self::STOCK_OWNER_INVENTORY) {
            $custShipped = round((float) ($shippedByOwner[$customerOwner] ?? 0), 2);
            $custAdjust = round((float) ($adjustByOwner[$customerOwner] ?? 0), 2);
            $custStock = round($inNum - $transferred - $custShipped + $custAdjust, 2);
            $owners[$customerOwner] = [
                'stock_owner' => $customerOwner,
                'stock_num' => $custStock,
                'shipped_num' => $custShipped,
                'transferred_num' => round($transferred, 2),
            ];
        }

        $stockShipped = round((float) ($shippedByOwner[self::STOCK_OWNER_INVENTORY] ?? 0), 2);
        $stockAdjust = round((float) ($adjustByOwner[self::STOCK_OWNER_INVENTORY] ?? 0), 2);
        $stockStock = round($transferred - $stockShipped + $stockAdjust, 2);
        if ($transferred > 0 || $stockShipped > 0 || $stockAdjust != 0) {
            $owners[self::STOCK_OWNER_INVENTORY] = [
                'stock_owner' => self::STOCK_OWNER_INVENTORY,
                'stock_num' => $stockStock,
                'shipped_num' => $stockShipped,
                'transferred_num' => round($transferred, 2),
            ];
        }

        return [
            'plan_code' => $planCode,
            'material_id' => $materialId,
            'in_num' => round($inNum, 2),
            'owners' => $owners,
        ];
    }

    /**
     * 计划成品库存汇总（按归属）
     *
     * @param string     $planCode
     * @param array      $planRow
     * @param array|null $baseRow SQL 行（含 in_num/material_id 时可传入减少查询）
     * @return array
     */
    public static function planSummary(string $planCode, array $planRow, ?array $baseRow = null): array
    {
        $customerOwner = trim((string) ($planRow['production_plan_name'] ?? ''));
        $inNum = 0.0;
        $materialId = 0;
        if ($baseRow !== null) {
            $inNum = round((float) ($baseRow['in_num'] ?? 0), 2);
            $materialId = (int) ($baseRow['material_id'] ?? 0);
        }
        if ($inNum <= 0) {
            $inNum = self::queryPlanInNum($planCode);
        }
        if ($materialId <= 0) {
            $materialId = self::resolveMaterialId($planCode);
        }

        $transferred = (float) (new StockOwnerTransferModel())
            ->where('plan_code', $planCode)
            ->where('is_delete', 0)
            ->sum('transfer_qty');

        $shipRows = (new ShipModel())
            ->where('relation_code', $planCode)
            ->where('is_delete', 0)
            ->field('stock_owner,material_num')
            ->select()
            ->toArray();

        $shippedByOwner = [];
        foreach ($shipRows as $sr) {
            $owner = trim((string) ($sr['stock_owner'] ?? ''));
            if ($owner === '') {
                $owner = $customerOwner;
            }
            $shippedByOwner[$owner] = ($shippedByOwner[$owner] ?? 0) + (float) ($sr['material_num'] ?? 0);
        }

        $adjustRows = (new StockAdjustModel())
            ->where('plan_code', $planCode)
            ->where('is_delete', 0)
            ->field('stock_owner,adjust_qty')
            ->select()
            ->toArray();
        $adjustByOwner = [];
        foreach ($adjustRows as $ar) {
            $owner = trim((string) ($ar['stock_owner'] ?? ''));
            if ($owner === '') {
                continue;
            }
            $adjustByOwner[$owner] = ($adjustByOwner[$owner] ?? 0) + (float) ($ar['adjust_qty'] ?? 0);
        }

        return self::buildOwnerSummary(
            $planCode,
            $customerOwner,
            $inNum,
            $materialId,
            $transferred,
            $shippedByOwner,
            $adjustByOwner
        );
    }

    /**
     * @param string $planCode
     * @return float
     */
    private static function queryPlanInNum(string $planCode): float
    {
        $sql = "
            SELECT COALESCE(SUM(pr.report_num), 0) as in_num
            FROM ya_product_task pt
            INNER JOIN ya_product_report pr ON pr.relation_code = pt.task_code
                AND pr.processes_code = 'gx_0014' AND pr.is_delete = 0
            WHERE pt.relation_code = ? AND pt.is_delete = 0
        ";
        $rows = Db::query($sql, [$planCode]);

        return round((float) ($rows[0]['in_num'] ?? 0), 2);
    }

    /**
     * @param string $planCode
     * @return int
     */
    private static function resolveMaterialId(string $planCode): int
    {
        $wm = (new WmsMaterialModel())
            ->where('material_batch', $planCode)
            ->where('is_delete', 0)
            ->order('id', 'desc')
            ->find();
        if (!empty($wm)) {
            return (int) ($wm['material_id'] ?? 0);
        }

        return 0;
    }

    /**
     * @return bool
     */
    private static function isSuperUser(): bool
    {
        $uid = user_id();
        if ($uid === 1) {
            return true;
        }
        $user = (new UserModel())->find($uid);

        return !empty($user) && (int) ($user['is_super'] ?? 0) === 1;
    }

    /**
     * @return string[]
     */
    private static function currentUserRoleNames(): array
    {
        $uid = user_id();
        if ($uid <= 0) {
            return [];
        }
        $roleIds = (new UserAttributesModel())->where('user_id', $uid)->column('role_id');
        if ($roleIds === []) {
            return [];
        }
        $roleModel = new RoleModel();
        $names = [];
        foreach ($roleIds as $rid) {
            $roleRow = $roleModel->find((int) $rid);
            if (!empty($roleRow)) {
                $names[] = (string) ($roleRow['role_name'] ?? '');
            }
        }

        return $names;
    }

    /**
     * @param string[] $roleNames
     * @param string[] $keywords
     * @return bool
     */
    private static function roleMatchAny(array $roleNames, array $keywords): bool
    {
        foreach ($roleNames as $name) {
            foreach ($keywords as $kw) {
                if ($kw !== '' && mb_strpos($name, $kw) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 同一盘点身份多行时保留最后一行（与盘点期初“取最后一次”一致，避免同一次提交把吨数加两次）。
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{item: array<string, mixed>, qty: float, spec: string, model: string, material_id: int}>
     */
    public static function collapseAcidWashCoverLines(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = round(
                (float) ($item['day_shift_qty'] ?? $item['day_shift_tonnage'] ?? 0)
                + (float) ($item['night_shift_qty'] ?? $item['night_shift_tonnage'] ?? 0),
                2
            );
            $spec = MaterialInventoryService::normalizeSpec(
                (string) ($item['c_material_spec'] ?? $item['c_product_spec'] ?? '')
            );
            $model = MaterialInventoryService::normalizeSpec(
                (string) ($item['c_material_model'] ?? $item['c_product_model'] ?? '')
            );
            $materialId = (int) ($item['material_id'] ?? 0);
            $name = trim((string) ($item['y_material_name'] ?? $item['material_name'] ?? ''));
            $manufacturer = trim((string) ($item['y_material_manufacturer'] ?? ''));
            $roundSpec = trim((string) ($item['y_material_spec'] ?? ''));
            $roundModel = trim((string) ($item['y_material_model'] ?? ''));
            $identity = $materialId > 0
                ? 'id:' . $materialId
                : implode("\x00", [$name, $manufacturer, $roundSpec, $roundModel]);
            $identity .= "\x00" . $spec . "\x00" . $model;
            $grouped[$identity] = [
                'item' => $item,
                'qty' => $qty,
                'spec' => $spec,
                'model' => $model,
                'material_id' => $materialId,
            ];
        }

        return array_values($grouped);
    }

    /**
     * 盘点行在提示里怎么称呼。
     *
     * @param array<string, mixed> $item
     * @return string
     */
    public static function acidWashCoverLabel(array $item): string
    {
        $parts = [];
        foreach (
            [
                'y_material_manufacturer',
                'y_material_spec',
                'y_material_model',
                'c_material_spec',
                'c_material_model',
            ] as $field
        ) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        if ($parts === []) {
            $fallback = trim((string) ($item['material_name'] ?? $item['y_material_name'] ?? ''));

            return $fallback !== '' ? $fallback : '未命名型号';
        }

        return implode(' ', $parts);
    }

    /**
     * 把多张计划的现有库存调成实盘合计。
     *
     * 行序（盘亏）：计划按 sort_time、plan_id 从早到晚；同一计划先扣「库存」行到 0，再扣客户行到 0。
     * 不把客户吨数改记到「库存」，也不把「库存」吨数改记回客户。
     * 盘盈：差额全部加在最新一张计划的客户行上；该计划没有单独客户归属时，加在「库存」行。
     * 负数行先拉回 0，再做盈亏，这样发货页能看到的正数合计等于确认吨数。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{plan_code: string, stock_owner: string, before_qty: float, after_qty: float, adjust_qty: float}>
     */
    public static function allocateStocktakeCover(array $rows, float $targetQty): array
    {
        $targetQty = round($targetQty, 2);
        if ($targetQty < -0.001) {
            return [];
        }

        $ordered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $planCode = trim((string) ($row['plan_code'] ?? ''));
            $owner = trim((string) ($row['stock_owner'] ?? ''));
            if ($planCode === '' || $owner === '') {
                continue;
            }
            $isInventoryOwner = !empty($row['is_inventory_owner'])
                || $owner === self::STOCK_OWNER_INVENTORY;
            $ordered[] = [
                'plan_code' => $planCode,
                'stock_owner' => $owner,
                'stock_num' => round((float) ($row['stock_num'] ?? 0), 2),
                'sort_time' => (string) ($row['sort_time'] ?? ''),
                'plan_id' => (int) ($row['plan_id'] ?? 0),
                'is_inventory_owner' => $isInventoryOwner,
                'seq' => count($ordered),
            ];
        }
        if ($ordered === []) {
            return [];
        }

        usort($ordered, static function (array $left, array $right): int {
            return self::compareStocktakeRows($left, $right);
        });

        $original = [];
        foreach ($ordered as $index => $row) {
            $original[$index] = $row['stock_num'];
            if ($row['stock_num'] < -0.001) {
                $ordered[$index]['stock_num'] = 0.0;
            }
        }

        $sum = 0.0;
        foreach ($ordered as $row) {
            $sum += $row['stock_num'];
        }
        $gap = round($targetQty - round($sum, 2), 2);

        if ($gap < -0.001) {
            $remain = round(-$gap, 2);
            foreach ($ordered as $index => $row) {
                if ($remain <= 0.001) {
                    break;
                }
                $available = (float) $row['stock_num'];
                if ($available <= 0.001) {
                    continue;
                }
                $deduct = min($available, $remain);
                $ordered[$index]['stock_num'] = round($available - $deduct, 2);
                $remain = round($remain - $deduct, 2);
            }
        } elseif ($gap > 0.001) {
            $newestKey = null;
            foreach ($ordered as $row) {
                $key = self::stocktakePlanKey($row);
                if ($newestKey === null || strcmp($key, $newestKey) > 0) {
                    $newestKey = $key;
                }
            }
            $customerIndex = null;
            $inventoryIndex = null;
            foreach ($ordered as $index => $row) {
                if (self::stocktakePlanKey($row) !== $newestKey) {
                    continue;
                }
                if ($row['is_inventory_owner']) {
                    $inventoryIndex = $index;
                } elseif ($customerIndex === null) {
                    $customerIndex = $index;
                }
            }
            $gainIndex = $customerIndex !== null ? $customerIndex : $inventoryIndex;
            if ($gainIndex !== null) {
                $ordered[$gainIndex]['stock_num'] = round(
                    (float) $ordered[$gainIndex]['stock_num'] + $gap,
                    2
                );
            }
        }

        $adjusts = [];
        foreach ($ordered as $index => $row) {
            $beforeQty = round((float) $original[$index], 2);
            $afterQty = round((float) $row['stock_num'], 2);
            $adjustQty = round($afterQty - $beforeQty, 2);
            if (abs($adjustQty) < 0.001) {
                continue;
            }
            $adjusts[] = [
                'plan_code' => $row['plan_code'],
                'stock_owner' => $row['stock_owner'],
                'before_qty' => $beforeQty,
                'after_qty' => $afterQty,
                'adjust_qty' => $adjustQty,
            ];
        }

        return $adjusts;
    }

    /**
     * 计划先后：创建时间，时间相同按计划编号，同一计划先「库存」后客户。
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return int
     */
    private static function compareStocktakeRows(array $left, array $right): int
    {
        $timeCompare = strcmp((string) ($left['sort_time'] ?? ''), (string) ($right['sort_time'] ?? ''));
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        $codeCompare = strcmp((string) ($left['plan_code'] ?? ''), (string) ($right['plan_code'] ?? ''));
        if ($codeCompare !== 0) {
            return $codeCompare;
        }
        $ownerCompare = ((int) !empty($left['is_inventory_owner'])) <=> ((int) !empty($right['is_inventory_owner']));
        if ($ownerCompare !== 0) {
            // 盘亏先扣公共「库存」（0 在前），再扣客户
            return ((int) !empty($left['is_inventory_owner'])) ? -1 : 1;
        }

        return ((int) ($left['seq'] ?? 0)) <=> ((int) ($right['seq'] ?? 0));
    }

    /**
     * 同一张计划的排序键。盘盈落在键最大的那张计划上。
     *
     * @param array<string, mixed> $row
     * @return string
     */
    private static function stocktakePlanKey(array $row): string
    {
        return (string) ($row['sort_time'] ?? '') . '#' . (string) ($row['plan_code'] ?? '');
    }

    /**
     * 多种圆钢挤在同一计划时，整行都不改库存。
     *
     * @param array<int, mixed> $plans
     * @param string[] $mixedPlanCodes
     * @return string ok|none|mixed
     */
    public static function acidWashCoverMatchStatus(array $plans, array $mixedPlanCodes): string
    {
        if ($mixedPlanCodes !== []) {
            return 'mixed';
        }
        if ($plans === []) {
            return 'none';
        }

        return 'ok';
    }

    /**
     * 结存行是不是成品。圆钢、配件以及对应仓库名一律不动。
     *
     * @param string $materialProperty
     * @param string $warehouseName
     * @return bool
     */
    public static function wmsRowAllowedForFinishedCover(string $materialProperty, string $warehouseName): bool
    {
        $property = trim($materialProperty);
        if ($property === '圆钢' || $property === '配件') {
            return false;
        }
        $warehouseName = trim($warehouseName);
        foreach (['圆钢', '配件', '原料'] as $word) {
            if ($warehouseName !== '' && mb_strpos($warehouseName, $word) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 把已有结存行调成覆盖后的合计。多行时差额只落在最新一条，落下去会变成负数则整批不动。
     *
     * @param array<int, array{id?: int, material_num?: float}> $rows 从旧到新
     * @param float $targetQty
     * @return array{id: int, material_num: float}|null
     */
    public static function planWmsBalanceUpdate(array $rows, float $targetQty): ?array
    {
        if ($rows === [] || $targetQty < -0.001) {
            return null;
        }
        $targetQty = round($targetQty, 2);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId <= 0) {
                continue;
            }
            $normalized[] = [
                'id' => $rowId,
                'material_num' => round((float) ($row['material_num'] ?? 0), 2),
            ];
        }
        if ($normalized === []) {
            return null;
        }
        if (count($normalized) === 1) {
            return [
                'id' => $normalized[0]['id'],
                'material_num' => $targetQty,
            ];
        }
        $others = 0.0;
        $lastIndex = count($normalized) - 1;
        foreach ($normalized as $index => $row) {
            if ($index === $lastIndex) {
                continue;
            }
            $others += $row['material_num'];
        }
        $latestQty = round($targetQty - round($others, 2), 2);
        if ($latestQty < -0.001) {
            return null;
        }

        return [
            'id' => $normalized[$lastIndex]['id'],
            'material_num' => $latestQty,
        ];
    }

    /**
     * 酸洗盘点提交后，把对上的成品库存合计改成确认吨数。
     *
     * @param array<int, array<string, mixed>> $items
     * @param string $stocktakeDate
     * @param int $userId
     * @return array{unmatched: string[], skipped_negative: string[], adjust_count: int, matched: int, blocked: string}
     */
    public static function coverByAcidWashStocktake(array $items, string $stocktakeDate, int $userId): array
    {
        $unmatched = [];
        $skippedNegative = [];
        $adjustCount = 0;
        $matched = 0;
        $blocked = '';
        $stocktakeDate = date('Y-m-d', strtotime($stocktakeDate) ?: time());
        // [AI-2026-10] 留痕必须是当前登录人，不采用前端传入的 user_id
        if ($userId <= 0) {
            $blocked = '未登录，发货库存未改';

            return [
                'unmatched' => $unmatched,
                'skipped_negative' => $skippedNegative,
                'adjust_count' => 0,
                'matched' => 0,
                'blocked' => $blocked,
            ];
        }

        $pending = [];
        foreach (self::collapseAcidWashCoverLines($items) as $line) {
            $label = self::acidWashCoverLabel($line['item']);
            if ($line['qty'] < -0.001) {
                $skippedNegative[] = $label;
                continue;
            }
            if ($line['spec'] === '' && $line['model'] === '') {
                $unmatched[] = $label . '（未对上：缺少成品规格型号）';
                continue;
            }

            $found = self::findAcidWashCoverPlans($line);
            $status = self::acidWashCoverMatchStatus($found['plans'], $found['mixed']);
            if ($status === 'mixed') {
                $unmatched[] = $label . '（未对上：同一计划有多种圆钢）';
                continue;
            }
            if ($status !== 'ok') {
                $unmatched[] = $label . '（未对上）';
                continue;
            }
            $pending[] = [
                'line' => $line,
                'label' => $label,
                'plans' => $found['plans'],
            ];
        }

        $planCodes = [];
        foreach ($pending as $item) {
            foreach ($item['plans'] as $plan) {
                $planCode = trim((string) ($plan['plan_code'] ?? ''));
                if ($planCode !== '') {
                    $planCodes[$planCode] = true;
                }
            }
        }
        // [AI-2026-10] 先锁住要对的计划，再读当前库存，避免两份提交按同一差额各写一笔
        self::lockCoverPlanRows(array_keys($planCodes));

        foreach ($pending as $item) {
            $line = $item['line'];
            $label = $item['label'];
            $plans = $item['plans'];

            $rows = [];
            $materialByPlan = [];
            foreach ($plans as $plan) {
                $planCode = (string) $plan['plan_code'];
                $summary = self::planSummary($planCode, [
                    'production_plan_name' => (string) ($plan['production_plan_name'] ?? ''),
                ]);
                // [AI-2026-10] 只认计划成品档案。结存表里按批次取到的可能是圆钢，不能拿来改数量
                $materialByPlan[$planCode] = (int) ($plan['finished_material_id'] ?? 0);
                $owners = $summary['owners'] ?? [];
                if ($owners === []) {
                    continue;
                }
                foreach ($owners as $owner => $ownerRow) {
                    $rows[] = [
                        'plan_code' => $planCode,
                        'stock_owner' => (string) $owner,
                        'stock_num' => (float) ($ownerRow['stock_num'] ?? 0),
                        'sort_time' => (string) ($plan['sort_time'] ?? ''),
                        'plan_id' => (int) ($plan['plan_id'] ?? 0),
                        'is_inventory_owner' => (string) $owner === self::STOCK_OWNER_INVENTORY,
                    ];
                }
            }
            if ($rows === []) {
                $unmatched[] = $label . '（未对上）';
                continue;
            }

            $adjusts = self::allocateStocktakeCover($rows, (float) $line['qty']);
            $matched++;
            $touchedPlans = [];
            foreach ($adjusts as $adjust) {
                self::insertStocktakeAdjust(
                    $adjust,
                    (int) ($materialByPlan[$adjust['plan_code']] ?? 0),
                    $stocktakeDate,
                    $userId,
                    $label
                );
                $adjustCount++;
                $touchedPlans[$adjust['plan_code']] = true;
            }

            $finalByPlan = [];
            foreach ($rows as $row) {
                $planCode = $row['plan_code'];
                $finalByPlan[$planCode] = ($finalByPlan[$planCode] ?? 0) + (float) $row['stock_num'];
            }
            foreach ($adjusts as $adjust) {
                $planCode = $adjust['plan_code'];
                $finalByPlan[$planCode] = round(
                    ($finalByPlan[$planCode] ?? 0) + (float) $adjust['adjust_qty'],
                    2
                );
            }
            foreach (array_keys($touchedPlans) as $planCode) {
                self::syncPlanWmsBalance(
                    (int) ($materialByPlan[$planCode] ?? 0),
                    (string) $planCode,
                    (float) ($finalByPlan[$planCode] ?? 0)
                );
            }
        }

        return [
            'unmatched' => $unmatched,
            'skipped_negative' => $skippedNegative,
            'adjust_count' => $adjustCount,
            'matched' => $matched,
            'blocked' => $blocked,
        ];
    }

    /**
     * 找出能对上这一盘点行、并且已经有酸洗报工的计划。多种圆钢挤在一张计划里的跳过。
     *
     * @param array{item: array<string, mixed>, qty: float, spec: string, model: string, material_id: int} $line
     * @return array{plans: array<int, array<string, mixed>>, mixed: string[]}
     */
    private static function findAcidWashCoverPlans(array $line): array
    {
        $query = Db::name('production')->alias('p')
            ->join('product_task pt', 'pt.relation_code = p.production_plan_code AND pt.is_delete = 0')
            ->join(
                'product_report pr',
                "pr.relation_code = pt.task_code AND pr.processes_code = 'gx_0014' AND pr.is_delete = 0"
            )
            ->join('material fm', 'p.english_material = fm.id AND fm.is_delete = 0')
            ->join('material rm', 'pt.material_id = rm.id AND rm.is_delete = 0')
            ->where('p.is_delete', 0);

        $materialId = (int) $line['material_id'];
        if ($materialId > 0) {
            $query->where('pt.material_id', $materialId);
        } else {
            $item = $line['item'];
            $name = trim((string) ($item['y_material_name'] ?? $item['material_name'] ?? ''));
            $roundSpec = trim((string) ($item['y_material_spec'] ?? ''));
            $roundModel = trim((string) ($item['y_material_model'] ?? ''));
            $manufacturer = trim((string) ($item['y_material_manufacturer'] ?? ''));
            if ($name === '' || $roundSpec === '' || $roundModel === '') {
                return ['plans' => [], 'mixed' => []];
            }
            $query->where('rm.material_name', $name)
                ->where('rm.material_spec', $roundSpec)
                ->where('rm.material_model', $roundModel);
            if ($manufacturer !== '') {
                $query->where('rm.manufacturer', $manufacturer);
            }
        }

        $rawRows = $query
            ->field(
                'p.id as plan_id, p.production_plan_code as plan_code, p.production_plan_name, p.create_time, '
                . 'fm.id as finished_material_id, fm.material_spec as finished_spec, fm.material_model as finished_model, '
                . 'SUM(pr.report_num) as in_num'
            )
            ->group(
                'p.id, p.production_plan_code, p.production_plan_name, p.create_time, '
                . 'fm.id, fm.material_spec, fm.material_model'
            )
            ->select()
            ->toArray();

        $wantSpec = (string) $line['spec'];
        $wantModel = (string) $line['model'];
        $candidates = [];
        foreach ($rawRows as $row) {
            $inNum = (float) ($row['in_num'] ?? 0);
            if ($inNum <= 0.000001) {
                continue;
            }
            $finishedSpec = MaterialInventoryService::normalizeSpec((string) ($row['finished_spec'] ?? ''));
            $finishedModel = MaterialInventoryService::normalizeSpec((string) ($row['finished_model'] ?? ''));
            if ($finishedSpec !== $wantSpec || $finishedModel !== $wantModel) {
                continue;
            }
            $planCode = trim((string) ($row['plan_code'] ?? ''));
            if ($planCode === '') {
                continue;
            }
            $candidates[$planCode] = [
                'plan_id' => (int) ($row['plan_id'] ?? 0),
                'plan_code' => $planCode,
                'production_plan_name' => (string) ($row['production_plan_name'] ?? ''),
                'sort_time' => (string) ($row['create_time'] ?? ''),
                'finished_material_id' => (int) ($row['finished_material_id'] ?? 0),
            ];
        }
        if ($candidates === []) {
            return ['plans' => [], 'mixed' => []];
        }

        $mixedCodes = self::acidWashPlansWithMixedRoundSteel(array_keys($candidates));
        // [AI-2026-10] 有一张计划对不上（多种圆钢），这一行整行不改，避免只改一部分后合计对不上实盘
        if ($mixedCodes !== []) {
            return ['plans' => [], 'mixed' => $mixedCodes];
        }

        $plans = array_values($candidates);
        usort($plans, static function (array $left, array $right): int {
            $timeCompare = strcmp((string) $left['sort_time'], (string) $right['sort_time']);
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return strcmp((string) $left['plan_code'], (string) $right['plan_code']);
        });

        return ['plans' => $plans, 'mixed' => []];
    }

    /**
     * 一张计划的酸洗报工若来自多种圆钢，整张计划不参与覆盖。
     *
     * @param string[] $planCodes
     * @return string[]
     */
    private static function acidWashPlansWithMixedRoundSteel(array $planCodes): array
    {
        if ($planCodes === []) {
            return [];
        }
        $rows = Db::name('product_task')->alias('pt')
            ->join(
                'product_report pr',
                "pr.relation_code = pt.task_code AND pr.processes_code = 'gx_0014' AND pr.is_delete = 0"
            )
            ->whereIn('pt.relation_code', $planCodes)
            ->where('pt.is_delete', 0)
            ->field('pt.relation_code, pt.material_id')
            ->group('pt.relation_code, pt.material_id')
            ->select()
            ->toArray();

        $counts = [];
        foreach ($rows as $row) {
            $planCode = trim((string) ($row['relation_code'] ?? ''));
            if ($planCode === '') {
                continue;
            }
            $counts[$planCode] = ($counts[$planCode] ?? 0) + 1;
        }
        $mixed = [];
        foreach ($counts as $planCode => $count) {
            if ($count > 1) {
                $mixed[] = (string) $planCode;
            }
        }

        return $mixed;
    }

    /**
     * @param array{plan_code: string, stock_owner: string, before_qty: float, after_qty: float, adjust_qty: float} $adjust
     * @param int $materialId
     * @param string $stocktakeDate
     * @param int $userId
     * @param string $label
     * @return void
     */
    private static function insertStocktakeAdjust(
        array $adjust,
        int $materialId,
        string $stocktakeDate,
        int $userId,
        string $label
    ): void {
        $planCode = (string) $adjust['plan_code'];
        $reason = '酸洗盘点覆盖 ' . $stocktakeDate . ' ' . $label;
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }
        $adjustCode = PublicService::getCode(15)['code'] ?? ('tz-' . date('YmdHis') . mt_rand(100, 999));
        $row = [
            'adjust_code' => $adjustCode,
            'plan_code' => $planCode,
            'material_id' => $materialId,
            'material_batch' => $planCode,
            'stock_owner' => (string) $adjust['stock_owner'],
            'adjust_type' => self::ADJUST_STOCKTAKE,
            'adjust_qty' => (float) $adjust['adjust_qty'],
            'before_qty' => (float) $adjust['before_qty'],
            'after_qty' => (float) $adjust['after_qty'],
            'reason' => $reason,
            'ship_code' => '',
            'create_uid' => $userId,
            'create_time' => datetime(),
            'is_delete' => 0,
        ];
        if (self::stockAdjustHasStocktakeDate()) {
            $row['stocktake_date'] = $stocktakeDate;
        }
        (new StockAdjustModel())->insert($row);
    }

    /**
     * 列还没加时不写，避免没跑迁移就提交失败。跑过迁移后会带上盘点日。
     *
     * @return bool
     */
    private static function stockAdjustHasStocktakeDate(): bool
    {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }
        try {
            $table = (new StockAdjustModel())->getTable();
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                $hasColumn = false;

                return false;
            }
            $columns = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'stocktake_date'");
            $hasColumn = !empty($columns);
        } catch (\Throwable $e) {
            $hasColumn = false;
        }

        return $hasColumn;
    }

    /**
     * 把该计划成品结存改成覆盖后的合计。没有结存行不新建。圆钢、配件不改。
     * 同一批次多行时，差额只改最新一条，保证各行加起来等于覆盖后的合计；改了会变成负数则整批不动。
     *
     * @param int $materialId 计划成品档案 id
     * @param string $planCode
     * @param float $qty
     * @return void
     */
    private static function syncPlanWmsBalance(int $materialId, string $planCode, float $qty): void
    {
        if ($materialId <= 0 || $planCode === '' || $qty < -0.001) {
            return;
        }
        $rows = Db::name('wms_material')->alias('w')
            ->leftJoin('material m', 'm.id = w.material_id')
            ->where('w.material_batch', $planCode)
            ->where('w.material_id', $materialId)
            ->where('w.is_delete', 0)
            ->field('w.id, w.material_num, w.stock, m.material_property')
            ->order('w.id', 'asc')
            ->select()
            ->toArray();
        $allowed = [];
        foreach ($rows as $row) {
            if (!self::wmsRowAllowedForFinishedCover(
                (string) ($row['material_property'] ?? ''),
                (string) ($row['stock'] ?? '')
            )) {
                continue;
            }
            $allowed[] = [
                'id' => (int) ($row['id'] ?? 0),
                'material_num' => (float) ($row['material_num'] ?? 0),
            ];
        }
        $update = self::planWmsBalanceUpdate($allowed, $qty);
        if ($update === null) {
            return;
        }
        (new StockMaterialModel())->where('id', $update['id'])->update([
            'material_num' => $update['material_num'],
        ]);
    }

    /**
     * 串行化酸洗盘点覆盖。锁要持有到外层事务提交之后再放。
     *
     * @return void
     */
    public static function acquireAcidWashCoverLock(): void
    {
        $name = self::acidWashCoverLockName();
        try {
            $rows = Db::query('SELECT GET_LOCK(?, 15) AS got_lock', [$name]);
        } catch (\Throwable $e) {
            exception('酸洗盘点提交加锁失败:' . $e->getMessage());
        }
        $got = $rows[0]['got_lock'] ?? null;
        if ((int) $got !== 1) {
            exception('有一份酸洗盘点正在提交，请稍后再试');
        }
    }

    /**
     * @return void
     */
    public static function releaseAcidWashCoverLock(): void
    {
        try {
            Db::query('SELECT RELEASE_LOCK(?)', [self::acidWashCoverLockName()]);
        } catch (\Throwable $e) {
            trace('释放酸洗盘点锁失败:' . $e->getMessage(), 'error');
        }
    }

    /**
     * @return string
     */
    private static function acidWashCoverLockName(): string
    {
        $db = '';
        try {
            $nameRow = Db::query('SELECT DATABASE() AS dbname');
            $db = (string) ($nameRow[0]['dbname'] ?? '');
        } catch (\Throwable $e) {
            $db = '';
        }
        $db = preg_replace('/[^A-Za-z0-9_]/', '', $db);
        if (!is_string($db) || $db === '') {
            $db = 'default';
        }

        return 'acid_wash_cover_' . $db;
    }

    /**
     * 按计划编号顺序锁计划单和已有调整，再读库存。
     *
     * @param string[] $planCodes
     * @return void
     */
    private static function lockCoverPlanRows(array $planCodes): void
    {
        $planCodes = array_values(array_unique(array_filter(array_map(static function ($code) {
            return is_string($code) ? trim($code) : '';
        }, $planCodes))));
        sort($planCodes, SORT_STRING);
        if ($planCodes === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($planCodes), '?'));
        Db::query(
            "SELECT id FROM ya_production WHERE is_delete = 0 AND production_plan_code IN ({$placeholders}) ORDER BY production_plan_code FOR UPDATE",
            $planCodes
        );
        Db::query(
            "SELECT id FROM ya_stock_adjust WHERE is_delete = 0 AND plan_code IN ({$placeholders}) ORDER BY plan_code, id FOR UPDATE",
            $planCodes
        );
    }
}
