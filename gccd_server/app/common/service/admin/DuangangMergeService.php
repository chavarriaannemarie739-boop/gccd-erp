<?php

namespace app\common\service\admin;

use app\common\config\ProductionConfig;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProductTaskModel;
use app\common\service\utils\DuangangMergeKeyHelper;
use think\facade\Db;
use think\facade\Log;

/**
 * 断钢合并池：列表、报工、重建
 */
class DuangangMergeService
{
    /** @var string|null */
    private static $punchProcessCodeCache = null;

    /** @var bool 同一请求内合并多次重建调度 */
    private static $rebuildScheduled = false;

    private static function punchProcessCode(): string
    {
        if (self::$punchProcessCodeCache === null) {
            self::$punchProcessCodeCache = RoutingProcessHelper::getProcessCodeByName(
                ProductionConfig::PROCESS_NAME_PUNCH
            );
        }

        return self::$punchProcessCodeCache;
    }

    /**
     * 单工单：穿孔累计折算断钢已完成
     */
    public static function taskDuangangDoneByPunch(string $taskCode, int $materialId): float
    {
        $punchCode = self::punchProcessCode();
        if ($punchCode === '') {
            return 0.0;
        }
        $sum = (float) Db::name('product_report')
            ->where('relation_code', $taskCode)
            ->where('material_id', $materialId)
            ->where('processes_code', $punchCode)
            ->where('is_delete', 0)
            ->sum('report_num');

        return round($sum / ProductionConfig::PUNCH_TO_DUANGANG_RATIO, 3);
    }

    /**
     * 批量预载穿孔折算断钢完成量，避免 rebuildAll 逐工单 SUM
     *
     * @return array<string,float> key: taskCode:materialId
     */
    private static function loadPunchDoneMap(): array
    {
        $punchCode = self::punchProcessCode();
        if ($punchCode === '') {
            return [];
        }
        $rows = Db::name('product_report')
            ->where('processes_code', $punchCode)
            ->where('is_delete', 0)
            ->field('relation_code, material_id, SUM(report_num) AS total')
            ->group('relation_code, material_id')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $taskCode = (string) ($row['relation_code'] ?? '');
            $materialId = (int) ($row['material_id'] ?? 0);
            if ($taskCode === '' || $materialId <= 0) {
                continue;
            }
            $map[$taskCode . ':' . $materialId] = round(
                (float) ($row['total'] ?? 0) / ProductionConfig::PUNCH_TO_DUANGANG_RATIO,
                3
            );
        }

        return $map;
    }

    public static function taskRemainCapacity(array $taskRow, ?array $punchDoneMap = null): float
    {
        $taskNum = (float) ($taskRow['task_num'] ?? 0);
        $taskCode = (string) ($taskRow['task_code'] ?? '');
        $materialId = (int) ($taskRow['material_id'] ?? 0);
        if ($punchDoneMap !== null) {
            $done = (float) ($punchDoneMap[$taskCode . ':' . $materialId] ?? 0.0);
        } else {
            $done = self::taskDuangangDoneByPunch($taskCode, $materialId);
        }

        return round(max(0.0, $taskNum - $done), 3);
    }

    /**
     * 接口返回后再重建合并池，避免建计划/下推阻塞 10~20 秒
     */
    public static function scheduleRebuildAll(): void
    {
        if (self::$rebuildScheduled) {
            return;
        }
        self::$rebuildScheduled = true;
        register_shutdown_function(static function () {
            try {
                self::rebuildAll();
            } catch (\Throwable $e) {
                Log::error('断钢合并池后台重建失败: ' . $e->getMessage());
            }
        });
    }

    /**
     * 加载带物料信息的进行中工单行
     *
     * @return array<int,array<string,mixed>>
     */
    public static function loadActiveTaskRows(): array
    {
        $model = new ProductTaskModel();

        return $model->with(['getPlanName', 'getMaterial', 'getYMaterial'])
            ->where('is_delete', 0)
            ->select()
            ->toArray();
    }

    /**
     * 全量重建合并池（下推/改计划后调用）
     */
    public static function rebuildAll(): void
    {
        $tasks = self::loadActiveTaskRows();
        $punchDoneMap = self::loadPunchDoneMap();
        $buckets = [];
        foreach ($tasks as $task) {
            $remain = self::taskRemainCapacity($task, $punchDoneMap);
            if ($remain <= 0.0001) {
                continue;
            }
            $task['_remain_capacity'] = $remain;
            $keyInfo = DuangangMergeKeyHelper::buildFromTaskRow($task);
            $mk = $keyInfo['mergeKey'];
            if (!isset($buckets[$mk])) {
                $buckets[$mk] = [
                    'meta' => $keyInfo,
                    'members' => [],
                    'capacity' => 0.0,
                ];
            }
            $buckets[$mk]['members'][] = $task;
            $buckets[$mk]['capacity'] += $remain;
        }

        $reportedMap = [];
        $reportRows = Db::name('duangang_merge_report')
            ->alias('r')
            ->leftJoin('duangang_merge_group g', 'g.id = r.merge_group_id')
            ->where('r.is_delete', 0)
            ->where('g.is_delete', 0)
            ->field('g.merge_key, SUM(r.report_num) AS total')
            ->group('g.merge_key')
            ->select()
            ->toArray();
        foreach ($reportRows as $rr) {
            $reportedMap[(string) ($rr['merge_key'] ?? '')] = (float) ($rr['total'] ?? 0);
        }

        Db::name('duangang_merge_member')->where('is_delete', 0)->update(['is_delete' => 1]);
        Db::name('duangang_merge_group')->where('is_delete', 0)->update(['is_delete' => 1, 'status' => 1]);

        $now = datetime();
        foreach ($buckets as $mk => $bucket) {
            $reported = (float) ($reportedMap[$mk] ?? 0);
            $capacity = round((float) $bucket['capacity'], 3);
            $status = ($capacity <= 0.0001 || $reported >= $capacity - 0.0001) ? 1 : 0;
            if ($capacity <= 0.0001) {
                continue;
            }
            $ySpecDisplay = self::collectYMaterialSpecDisplay($bucket['members'], (string) ($bucket['meta']['steelGrade'] ?? ''));
            $groupId = (int) Db::name('duangang_merge_group')->insertGetId([
                'merge_key' => $mk,
                'steel_grade' => $bucket['meta']['steelGrade'],
                'y_material_spec' => $ySpecDisplay,
                'y_material_model' => $bucket['meta']['yMaterialModel'],
                'c_material_spec' => $bucket['meta']['cMaterialSpec'],
                'capacity_total' => $capacity,
                'reported_total' => round($reported, 3),
                'status' => $status,
                'is_delete' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            foreach ($bucket['members'] as $task) {
                $remain = (float) ($task['_remain_capacity'] ?? self::taskRemainCapacity($task, $punchDoneMap));
                Db::name('duangang_merge_member')->insert([
                    'merge_group_id' => $groupId,
                    'product_task_id' => (int) ($task['id'] ?? 0),
                    'task_code' => (string) ($task['task_code'] ?? ''),
                    'relation_code' => (string) ($task['relation_code'] ?? ''),
                    'material_id' => (int) ($task['material_id'] ?? 0),
                    'task_num' => (float) ($task['task_num'] ?? 0),
                    'remain_capacity' => $remain,
                    'is_delete' => 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
    }

    public static function rebuildByPlanCode(string $planCode): void
    {
        $planCode = trim($planCode);
        if ($planCode === '') {
            return;
        }
        // 合并池按 merge_key 跨计划聚合，单计划变更需全量重建；仅在下推/改料等场景调用，编辑穿孔设备勿调用
        self::scheduleRebuildAll();
    }

    /**
     * @return array{count:int,pages:int,page:int,limit:int,list:array}
     */
    public static function list(int $status = 0, int $page = 1, int $limit = 10, string $keyword = ''): array
    {
        $query = Db::name('duangang_merge_group')
            ->where('is_delete', 0)
            ->where('status', $status);
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('steel_grade', $like)
                    ->whereOr('y_material_spec', 'like', $like)
                    ->whereOr('y_material_model', 'like', $like)
                    ->whereOr('c_material_spec', 'like', $like);
            });
        }
        $count = (int) (clone $query)->count();
        $pages = $limit > 0 ? (int) ceil($count / $limit) : 1;
        $query->order('update_time', 'desc');
        if ($page > 0 && $limit > 0) {
            $query->page($page, $limit);
        }
        $list = $query->select()->toArray();
        foreach ($list as &$row) {
            self::enrichGroupRow($row);
        }
        unset($row);

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 列表/详情展示字段补全
     *
     * @param array<string,mixed> $row
     */
    public static function enrichGroupRow(array &$row): void
    {
        $cap = (float) ($row['capacity_total'] ?? 0);
        $rep = (float) ($row['reported_total'] ?? 0);
        $row['remain_total'] = round(max(0, $cap - $rep), 3);

        if (empty($row['y_material_spec']) && !empty($row['id'])) {
            $row['y_material_spec'] = self::loadYMaterialSpecDisplay((int) $row['id'], (string) ($row['steel_grade'] ?? ''));
        }

        $row['display_y_material_spec'] = self::formatYMaterialSpecLabel($row);
        $row['display_title'] = self::formatDisplayTitle($row);
    }

    /**
     * @param array<int,array<string,mixed>> $members
     */
    public static function collectYMaterialSpecDisplay(array $members, string $steelGrade): string
    {
        if ($steelGrade !== '') {
            return $steelGrade;
        }
        $specs = [];
        foreach ($members as $task) {
            $spec = trim((string) ($task['y_material_spec'] ?? ''));
            if ($spec !== '') {
                $specs[$spec] = true;
            }
        }

        return implode('、', array_keys($specs));
    }

    public static function loadYMaterialSpecDisplay(int $groupId, string $steelGrade): string
    {
        if ($steelGrade !== '') {
            return $steelGrade;
        }
        $specs = Db::name('duangang_merge_member')
            ->alias('m')
            ->leftJoin('material y', 'y.id = m.material_id')
            ->where('m.merge_group_id', $groupId)
            ->where('m.is_delete', 0)
            ->where('y.material_spec', '<>', '')
            ->group('y.material_spec')
            ->column('y.material_spec');

        return implode('、', array_filter($specs));
    }

    public static function formatYMaterialSpecLabel(array $group): string
    {
        $steelGrade = trim((string) ($group['steel_grade'] ?? ''));
        $ySpec = trim((string) ($group['y_material_spec'] ?? ''));
        if ($steelGrade !== '') {
            if ($ySpec !== '' && $ySpec !== $steelGrade && mb_strpos($ySpec, $steelGrade) === false) {
                return $steelGrade . '（' . $ySpec . '）';
            }

            return $steelGrade;
        }

        return $ySpec;
    }

    public static function formatDisplayTitle(array $group): string
    {
        $parts = [];
        $yLabel = self::formatYMaterialSpecLabel($group);
        if ($yLabel !== '') {
            $parts[] = $yLabel;
        }
        if (!empty($group['y_material_model'])) {
            $parts[] = $group['y_material_model'];
        }
        if (!empty($group['c_material_spec'])) {
            $parts[] = $group['c_material_spec'];
        }

        return implode(' · ', $parts);
    }

    public static function info(int $groupId): array
    {
        $group = Db::name('duangang_merge_group')->where('id', $groupId)->where('is_delete', 0)->find();
        if (empty($group)) {
            exception('合并池不存在');
        }
        $members = Db::name('duangang_merge_member')
            ->alias('m')
            ->leftJoin('production p', 'p.production_plan_code = m.relation_code')
            ->where('m.merge_group_id', $groupId)
            ->where('m.is_delete', 0)
            ->field('m.*,p.production_plan_name')
            ->select()
            ->toArray();
        $group['members'] = $members;
        self::enrichGroupRow($group);

        return $group;
    }

    /**
     * 断钢合并报工
     */
    public static function report(array $param): array
    {
        Db::startTrans();
        try {
            $groupId = (int) ($param['merge_group_id'] ?? 0);
            $group = Db::name('duangang_merge_group')->where('id', $groupId)->where('is_delete', 0)->lock(true)->find();
            if (empty($group)) {
                exception('合并池不存在');
            }
            if ((int) ($group['status'] ?? 0) === 1) {
                exception('该合并池已完成');
            }
            $reportNum = round((float) ($param['report_num'] ?? 0), 3);
            if ($reportNum <= 0) {
                exception('报工数量必须大于0');
            }
            $cap = (float) ($group['capacity_total'] ?? 0);
            $rep = (float) ($group['reported_total'] ?? 0);
            if ($rep + $reportNum > $cap + 0.0001) {
                exception('报工数量超过合并池剩余（剩余' . round(max(0, $cap - $rep), 3) . '吨）');
            }

            $duangangCode = RoutingProcessHelper::getProcessCodeByName(ProductionConfig::PROCESS_NAME_DUANGANG);
            if ($duangangCode === '') {
                exception('未配置断钢工序');
            }

            $defectiveItems = $param['defective_num'] ?? [];
            $defectiveNum = 0.0;
            if (is_array($defectiveItems)) {
                foreach ($defectiveItems as $v) {
                    $v = (array) $v;
                    $defectiveNum += (float) ($v['num'] ?? 0);
                }
            }

            $member = Db::name('duangang_merge_member')
                ->where('merge_group_id', $groupId)
                ->where('is_delete', 0)
                ->order('id', 'asc')
                ->find();
            $materialId = (int) ($member['material_id'] ?? 0);

            $reportCode = PublicService::getCode(6)['code'];
            $insert = [
                'merge_group_id' => $groupId,
                'report_code' => $reportCode,
                'processes_code' => $duangangCode,
                'report_num' => $reportNum,
                'defective_num' => $defectiveNum,
                'device_code' => (string) ($param['device_code'] ?? ''),
                'device_name' => (string) ($param['device_name'] ?? ''),
                'material_id' => $materialId,
                'remark' => (string) ($param['remark'] ?? ''),
                'defective' => json_encode($defectiveItems, JSON_UNESCAPED_UNICODE),
                'data' => json_encode($param, JSON_UNESCAPED_UNICODE),
                'report_date' => ProductReportService::calcBusinessReportDate(),
                'class' => (int) ($param['class'] ?? 0),
                'create_uid' => user_id(),
                'create_time' => datetime(),
                'is_delete' => 0,
            ];
            Db::name('duangang_merge_report')->insert($insert);
            $insert['id'] = (int) Db::name('duangang_merge_report')->getLastInsID();

            $newRep = round($rep + $reportNum, 3);
            $newStatus = ($newRep >= $cap - 0.0001) ? 1 : 0;
            Db::name('duangang_merge_group')->where('id', $groupId)->update([
                'reported_total' => $newRep,
                'status' => $newStatus,
                'update_time' => datetime(),
            ]);

            // [AI-2026-06] 镜像写入 product_report（盘点/日汇总/工资撤销联表）后再计薪
            $mirrorMeta = DuangangMergeReportMirror::syncFromMergeReport($insert, $group);

            self::advanceMembersToPunch($groupId, $duangangCode);

            $primaryTaskCode = (string) ($mirrorMeta['primary_task_code'] ?? '');
            $primaryMaterialId = (int) ($mirrorMeta['primary_material_id'] ?? 0);
            if ($primaryTaskCode !== '' && $primaryMaterialId > 0) {
                $wageMaterialSpec = trim((string) ($group['y_material_model'] ?? ''));
                if ($wageMaterialSpec === '') {
                    $wageMaterialSpec = trim((string) ($group['y_material_spec'] ?? ''));
                }
                $wageParam = [
                    'relation_code' => $primaryTaskCode,
                    'material_id' => $primaryMaterialId,
                    'processes_code' => $duangangCode,
                    'report_num' => $reportNum,
                    'defective_num' => $defectiveItems,
                    'is_replace' => $param['is_replace'] ?? 0,
                    'report_date' => $insert['report_date'],
                    'create_time' => $insert['create_time'],
                    'report_code' => $reportCode,
                    'device_code' => (string) ($param['device_code'] ?? ''),
                    'class' => (int) ($param['class'] ?? 0),
                    'wage_material_spec' => $wageMaterialSpec,
                ];
                $processModel = new ProcessesModel();
                $process = $processModel->where('processes_code', $duangangCode)->find();
                $taskModel = new ProductTaskModel();
                $englishMaterial = $taskModel->alias('a')
                    ->where('a.task_code', $primaryTaskCode)
                    ->leftJoin('ya_production b', 'a.relation_code = b.production_plan_code')
                    ->find();
                if ($process && $englishMaterial) {
                    ProductReportService::ProcessWage($wageParam, $process, $englishMaterial, $param['is_replace'] ?? 0);
                }
            }

            try {
                DailySummaryService::refresh($insert['report_date'], ProductionConfig::PROCESS_NAME_DUANGANG);
            } catch (\Exception $ex) {
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('断钢合并报工失败:' . $e->getMessage());
        }

        $reportCode = $reportCode ?? '';

        // 报工已增量更新 reported_total/status，全量 rebuildAll 仅在计划下推或手动重建时执行
        return ['merge_group_id' => $groupId, 'report_code' => $reportCode];
    }

    /**
     * 合并断钢报工后，成员工单流转到穿孔工序
     */
    private static function advanceMembersToPunch(int $groupId, string $duangangCode): void
    {
        $members = Db::name('duangang_merge_member')
            ->where('merge_group_id', $groupId)
            ->where('is_delete', 0)
            ->select()
            ->toArray();
        $taskModel = new ProductTaskModel();
        foreach ($members as $m) {
            $task = $taskModel->where('id', (int) ($m['product_task_id'] ?? 0))->find();
            if (empty($task)) {
                continue;
            }
            $routingCode = (string) ($task['routing_code'] ?? '');
            $current = (string) ($task['process_code'] ?? '');
            if ($current !== $duangangCode && $current !== '') {
                continue;
            }
            $next = RoutingProcessHelper::getNextProcessCode($routingCode, $duangangCode);
            if ($next !== '') {
                $taskModel->where('id', (int) $task['id'])->update(['process_code' => $next]);
            }
        }
    }

    public static function isDuangangProcessCode(string $processesCode): bool
    {
        $duangangCode = RoutingProcessHelper::getProcessCodeByName(ProductionConfig::PROCESS_NAME_DUANGANG);

        return $duangangCode !== '' && $processesCode === $duangangCode;
    }
}
