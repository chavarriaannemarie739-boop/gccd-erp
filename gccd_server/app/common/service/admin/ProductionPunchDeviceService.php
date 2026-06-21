<?php

namespace app\common\service\admin;

use app\common\config\ProductionConfig;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProductTaskModel;
use app\common\service\utils\DuangangMergeKeyHelper;
use think\facade\Db;

/**
 * 生产计划穿孔设备（1~2台）
 */
class ProductionPunchDeviceService
{
    /**
     * @param array<int,array{device_code?:string,device_name?:string}> $devices
     */
    public static function saveForPlan(string $planCode, array $devices): void
    {
        $planCode = trim($planCode);
        if ($planCode === '') {
            return;
        }
        Db::name('production_punch_device')->where('production_plan_code', $planCode)->delete();
        $sort = 1;
        $seen = [];
        foreach ($devices as $row) {
            $code = trim((string) ($row['device_code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            if (count($seen) > ProductionConfig::PUNCH_DEVICE_MAX) {
                exception('穿孔设备最多绑定' . ProductionConfig::PUNCH_DEVICE_MAX . '台');
            }
            Db::name('production_punch_device')->insert([
                'production_plan_code' => $planCode,
                'device_code' => $code,
                'device_name' => (string) ($row['device_name'] ?? ''),
                'sort' => $sort++,
                'create_time' => datetime(),
            ]);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listByPlanCode(string $planCode): array
    {
        return Db::name('production_punch_device')
            ->where('production_plan_code', $planCode)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 批量查询多个生产计划的穿孔设备
     *
     * @param array<int,string> $planCodes
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function listByPlanCodes(array $planCodes): array
    {
        $planCodes = array_values(array_filter(array_unique(array_map('strval', $planCodes))));
        if ($planCodes === []) {
            return [];
        }
        $rows = Db::name('production_punch_device')
            ->whereIn('production_plan_code', $planCodes)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            $code = (string) ($row['production_plan_code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (!isset($grouped[$code])) {
                $grouped[$code] = [];
            }
            $grouped[$code][] = $row;
        }

        return $grouped;
    }

    /**
     * 校验并规范化穿孔设备（工艺含穿孔时必填 1~2 台）
     *
     * @param array<int,array{device_code?:string,device_name?:string}> $devices
     * @return array<int,array{device_code:string,device_name:string}>
     */
    public static function validateDevices(string $routingCode, array $devices): array
    {
        if (!RoutingProcessHelper::routingHasProcessName($routingCode, ProductionConfig::PROCESS_NAME_PUNCH)) {
            return [];
        }
        $normalized = [];
        $seen = [];
        foreach ($devices as $row) {
            $code = trim((string) ($row['device_code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $device = Db::name('device')->where('device_code', $code)->where('is_delete', 0)->find();
            if (empty($device)) {
                exception('设备不存在：' . $code);
            }
            $procCode = (string) ($device['processes_code'] ?? '');
            $proc = Db::name('processes')->where('processes_code', $procCode)->where('is_delete', 0)->find();
            $procName = (string) ($proc['processes_name'] ?? '');
            if ($procName !== ProductionConfig::PROCESS_NAME_PUNCH) {
                exception('设备「' . ($device['device_name'] ?? $code) . '」不属于穿孔工序');
            }
            $seen[$code] = true;
            $normalized[] = [
                'device_code' => $code,
                'device_name' => (string) ($device['device_name'] ?? ''),
            ];
        }
        if ($normalized === []) {
            exception('该工艺含穿孔工序，请指定1~2台穿孔设备');
        }
        if (count($normalized) > ProductionConfig::PUNCH_DEVICE_MAX) {
            exception('穿孔设备最多' . ProductionConfig::PUNCH_DEVICE_MAX . '台');
        }

        return $normalized;
    }

    public static function syncToTasks(string $planCode): void
    {
        $devices = self::listByPlanCode($planCode);
        $tasks = Db::name('product_task')
            ->where('relation_code', $planCode)
            ->where('is_delete', 0)
            ->select()
            ->toArray();
        foreach ($tasks as $task) {
            $taskId = (int) ($task['id'] ?? 0);
            $taskCode = (string) ($task['task_code'] ?? '');
            Db::name('product_task_punch_device')->where('task_code', $taskCode)->delete();
            $sort = 1;
            foreach ($devices as $dev) {
                Db::name('product_task_punch_device')->insert([
                    'task_code' => $taskCode,
                    'product_task_id' => $taskId,
                    'device_code' => $dev['device_code'],
                    'device_name' => $dev['device_name'],
                    'sort' => $sort++,
                    'create_time' => datetime(),
                ]);
            }
        }
    }

    /**
     * 将已有计划的穿孔设备同步到全部关联工单（历史数据补全）
     */
    public static function syncAllPlansToTasks(): int
    {
        $planCodes = Db::name('production_punch_device')
            ->distinct(true)
            ->column('production_plan_code');
        $count = 0;
        foreach ($planCodes as $planCode) {
            $code = trim((string) $planCode);
            if ($code === '') {
                continue;
            }
            self::syncToTasks($code);
            $count++;
        }

        return $count;
    }

    public static function copyToTask(string $planCode, string $taskCode, int $productTaskId): void
    {
        $devices = self::listByPlanCode($planCode);
        $sort = 1;
        foreach ($devices as $dev) {
            Db::name('product_task_punch_device')->insert([
                'task_code' => $taskCode,
                'product_task_id' => $productTaskId,
                'device_code' => $dev['device_code'],
                'device_name' => $dev['device_name'],
                'sort' => $sort++,
                'create_time' => datetime(),
            ]);
        }
    }
}
