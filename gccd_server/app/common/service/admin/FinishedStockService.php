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
     *
     * @param array[] $baseRows productWarehouseList SQL 结果
     * @return array[]
     */
    public static function expandOwnerRows(array $baseRows): array
    {
        $out = [];
        foreach ($baseRows as $row) {
            $planCode = trim((string) ($row['plan_code'] ?? $row['relation_code'] ?? ''));
            if ($planCode === '') {
                continue;
            }
            $planName = trim((string) ($row['production_plan_name'] ?? ''));
            $summary = self::planSummary($planCode, [
                'production_plan_name' => $planName,
                'production_plan_code' => $planCode,
            ], $row);
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
            'in_num' => $inNum,
            'owners' => $owners,
        ];
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
}
