<?php

namespace app\common\service\admin;

use app\common\config\ProductionConfig;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProductTaskModel;
use app\common\service\utils\DuangangMergeKeyHelper;
use app\common\service\utils\MaterialSearchHelper;
use think\facade\Db;
use think\facade\Log;

/**
 * 断钢合并池：列表、报工、重建
 */
class DuangangMergeService
{
    /** @var bool 同一请求内合并多次重建调度 */
    private static $rebuildScheduled = false;

    /**
     * 成员计划容量 = 任务原数量（与报工、盘点独立，不用穿孔折算）
     *
     * @param array<string,mixed> $taskRow
     * @param array<string,float>|null $punchDoneMap 已废弃，忽略
     */
    public static function taskRemainCapacity(array $taskRow, ?array $punchDoneMap = null): float
    {
        // [AI-2026-08] 计划与报工分离：容量=task_num，不再用穿孔÷0.97
        return round(max(0.0, (float) ($taskRow['task_num'] ?? 0)), 3);
    }

    /**
     * 合并池是否达到完成容差（已报 ≥ 计划×0.97）；未满计划仍可补报
     */
    public static function isMergeGroupComplete(float $reportedTotal, float $capacityTotal): bool
    {
        if ($capacityTotal <= 0.0001) {
            return true;
        }
        $threshold = round($capacityTotal * ProductionConfig::PLAN_COMPLETE_RATIO, 3);

        return $reportedTotal + 0.0001 >= $threshold;
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

        // [AI-2026-08] 未完成工单入池；计划容量用 task_num，不因后道报工扣减计划
        return $model->with(['getPlanName', 'getMaterial', 'getYMaterial'])
            ->where('is_delete', 0)
            ->where('status', '<>', 2)
            ->select()
            ->toArray();
    }

    /**
     * 全量重建合并池（下推/改计划后调用）
     */
    public static function rebuildAll(): void
    {
        $tasks = self::loadActiveTaskRows();
        $buckets = [];
        foreach ($tasks as $task) {
            $planCap = self::taskRemainCapacity($task);
            if ($planCap <= 0.0001) {
                continue;
            }
            $task['_plan_capacity'] = $planCap;
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
            $buckets[$mk]['capacity'] += $planCap;
        }

        // [AI-2026-08] 按报工 material_id + 原组成品规格重算已报；须包含已软删合并组，
        // 否则历史报工仍挂旧 group_id 时会把 reported_total 算成 0。
        $reportedMap = [];
        $reportIdsByKey = [];
        $reportRows = Db::name('duangang_merge_report')
            ->alias('r')
            ->leftJoin('duangang_merge_group g', 'g.id = r.merge_group_id')
            ->leftJoin('material y', 'y.id = r.material_id')
            ->where('r.is_delete', 0)
            ->field(
                'r.id,r.report_num,y.material_name,y.manufacturer,y.material_spec,y.material_model,'
                . 'g.y_material_model,g.c_material_spec,g.y_material_spec AS group_y_spec'
            )
            ->select()
            ->toArray();
        foreach ($reportRows as $rr) {
            $keyInfo = DuangangMergeKeyHelper::buildFromTaskRow([
                'y_material_name' => (string) ($rr['material_name'] ?? ''),
                'y_material_manufacturer' => (string) ($rr['manufacturer'] ?? ''),
                'y_material_spec' => (string) (($rr['material_spec'] ?? '') !== ''
                    ? $rr['material_spec']
                    : ($rr['group_y_spec'] ?? '')),
                'y_material_model' => (string) (($rr['material_model'] ?? '') !== ''
                    ? $rr['material_model']
                    : ($rr['y_material_model'] ?? '')),
                'c_material_spec' => (string) ($rr['c_material_spec'] ?? ''),
            ]);
            $mk = (string) ($keyInfo['mergeKey'] ?? '');
            if ($mk === '') {
                continue;
            }
            $reportedMap[$mk] = round(($reportedMap[$mk] ?? 0) + (float) ($rr['report_num'] ?? 0), 3);
            $rid = (int) ($rr['id'] ?? 0);
            if ($rid > 0) {
                $reportIdsByKey[$mk][] = $rid;
            }
        }

        Db::name('duangang_merge_member')->where('is_delete', 0)->update(['is_delete' => 1]);
        Db::name('duangang_merge_group')->where('is_delete', 0)->update(['is_delete' => 1, 'status' => 1]);

        $now = datetime();
        foreach ($buckets as $mk => $bucket) {
            $reported = (float) ($reportedMap[$mk] ?? 0);
            $capacity = round((float) $bucket['capacity'], 3);
            if ($capacity <= 0.0001) {
                continue;
            }
            // [AI-2026-08] 池完成=已报≥计划×0.97；未满计划仍可补报
            $status = self::isMergeGroupComplete($reported, $capacity) ? 1 : 0;
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
            // 历史报工挂到新组，便于清理软删垃圾且列表不再全表扫
            $reportIds = $reportIdsByKey[$mk] ?? [];
            if ($reportIds !== []) {
                foreach (array_chunk($reportIds, 500) as $chunk) {
                    Db::name('duangang_merge_report')
                        ->whereIn('id', $chunk)
                        ->update(['merge_group_id' => $groupId]);
                }
            }
            foreach ($bucket['members'] as $task) {
                $planCap = (float) ($task['_plan_capacity'] ?? self::taskRemainCapacity($task));
                Db::name('duangang_merge_member')->insert([
                    'merge_group_id' => $groupId,
                    'product_task_id' => (int) ($task['id'] ?? 0),
                    'task_code' => (string) ($task['task_code'] ?? ''),
                    'relation_code' => (string) ($task['relation_code'] ?? ''),
                    'material_id' => (int) ($task['material_id'] ?? 0),
                    'task_num' => (float) ($task['task_num'] ?? 0),
                    'remain_capacity' => $planCap,
                    'is_delete' => 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }

        // [AI-2026-08] 硬删软删残留，避免列表对 2 万+ 行 filesort
        Db::name('duangang_merge_member')->where('is_delete', 1)->delete();
        Db::name('duangang_merge_group')->where('is_delete', 1)->delete();
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
     * @param array<string,string>|string $filters 四字段筛选；兼容旧版 keyword 字符串
     * @return array{count:int,pages:int,page:int,limit:int,list:array}
     */
    public static function list(int $status = 0, int $page = 1, int $limit = 10, $filters = []): array
    {
        if (is_string($filters)) {
            $filters = ['keyword' => $filters];
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $name = trim((string) ($filters['y_material_name'] ?? ''));
        $manufacturer = trim((string) ($filters['y_material_manufacturer'] ?? ''));
        $spec = trim((string) ($filters['y_material_spec'] ?? ''));
        $model = trim((string) ($filters['y_material_model'] ?? ''));

        $query = Db::name('duangang_merge_group')
            ->where('is_delete', 0)
            ->where('status', $status);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($q) use ($like) {
                $q->whereLike('steel_grade', $like)
                    ->whereOr('y_material_spec', 'like', $like)
                    ->whereOr('y_material_model', 'like', $like)
                    ->whereOr('c_material_spec', 'like', $like);
            });
        }
        if ($name !== '' || $manufacturer !== '' || $spec !== '' || $model !== '') {
            $matchedIds = self::groupIdsByRoundSteelFilters($name, $manufacturer, $spec, $model);
            if ($matchedIds === []) {
                return [
                    'count' => 0,
                    'pages' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'list' => [],
                ];
            }
            $query->whereIn('id', $matchedIds);
        }
        $count = (int) (clone $query)->count();
        $pages = $limit > 0 ? (int) ceil($count / $limit) : 1;
        $query->order('update_time', 'desc');
        if ($page > 0 && $limit > 0) {
            $query->page($page, $limit);
        }
        $list = $query->select()->toArray();
        $labelMap = self::loadRoundSteelLabelsByGroupIds(array_column($list, 'id'));
        foreach ($list as &$row) {
            self::enrichGroupRow($row, $labelMap[(int) ($row['id'] ?? 0)] ?? null);
        }
        unset($row);

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * [AI-2026-08] 按成员物料档案聚合名称/厂商/材质，供断钢列表展示。
     *
     * @param array<int,int|string> $groupIds
     * @return array<int, array{name:string,manufacturer:string,spec:string}>
     */
    public static function loadRoundSteelLabelsByGroupIds(array $groupIds): array
    {
        $ids = [];
        foreach ($groupIds as $groupId) {
            $id = (int) $groupId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = Db::name('duangang_merge_member')
            ->alias('m')
            ->leftJoin('material y', 'y.id = m.material_id AND y.is_delete = 0')
            ->whereIn('m.merge_group_id', array_values($ids))
            ->where('m.is_delete', 0)
            ->field('m.merge_group_id,y.material_name,y.manufacturer,y.material_spec')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $groupId = (int) ($row['merge_group_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            if (!isset($map[$groupId])) {
                $map[$groupId] = [
                    'names' => [],
                    'manufacturers' => [],
                    'specs' => [],
                ];
            }
            $name = trim((string) ($row['material_name'] ?? ''));
            $manufacturer = trim((string) ($row['manufacturer'] ?? ''));
            $spec = trim((string) ($row['material_spec'] ?? ''));
            if ($name !== '') {
                $map[$groupId]['names'][$name] = true;
            }
            if ($manufacturer !== '') {
                $map[$groupId]['manufacturers'][$manufacturer] = true;
            }
            if ($spec !== '') {
                $map[$groupId]['specs'][$spec] = true;
            }
        }

        $result = [];
        foreach ($map as $groupId => $bucket) {
            $result[$groupId] = [
                'name' => implode('、', array_keys($bucket['names'])),
                'manufacturer' => implode('、', array_keys($bucket['manufacturers'])),
                'spec' => implode('、', array_keys($bucket['specs'])),
            ];
        }

        return $result;
    }

    /**
     * @return int[]
     */
    public static function groupIdsByRoundSteelFilters(
        string $name,
        string $manufacturer,
        string $spec,
        string $model
    ): array {
        $query = Db::name('duangang_merge_member')
            ->alias('m')
            ->leftJoin('material y', 'y.id = m.material_id AND y.is_delete = 0')
            ->leftJoin('duangang_merge_group g', 'g.id = m.merge_group_id AND g.is_delete = 0')
            ->where('m.is_delete', 0);
        if ($name !== '') {
            $query->whereLike('y.material_name', '%' . MaterialSearchHelper::likeKeyword($name) . '%');
        }
        if ($manufacturer !== '') {
            $query->whereLike('y.manufacturer', '%' . MaterialSearchHelper::likeKeyword($manufacturer) . '%');
        }
        if ($spec !== '') {
            $query->where(function ($q) use ($spec) {
                $like = '%' . MaterialSearchHelper::likeKeyword($spec) . '%';
                $q->whereLike('y.material_spec', $like)
                    ->whereOr('g.steel_grade', 'like', $like)
                    ->whereOr('g.y_material_spec', 'like', $like);
            });
        }
        if ($model !== '') {
            $query->where(function ($q) use ($model) {
                $like = '%' . MaterialSearchHelper::likeKeyword($model) . '%';
                $q->whereLike('y.material_model', $like)
                    ->whereOr('g.y_material_model', 'like', $like);
            });
        }

        return array_values(array_unique(array_map('intval', $query->column('m.merge_group_id'))));
    }

    /**
     * 列表/详情展示字段补全
     *
     * @param array<string,mixed> $row
     * @param array{name?:string,manufacturer?:string,spec?:string}|null $labels
     */
    public static function enrichGroupRow(array &$row, ?array $labels = null): void
    {
        $cap = (float) ($row['capacity_total'] ?? 0);
        $rep = (float) ($row['reported_total'] ?? 0);
        $row['remain_total'] = round(max(0, $cap - $rep), 3);
        // [AI-2026-08] 列表展示：整数吨去掉尾小数，避免被误认为折算值
        $row['capacity_total_display'] = self::formatTonDisplay($cap);
        $row['reported_total_display'] = self::formatTonDisplay($rep);
        $row['remain_total_display'] = self::formatTonDisplay((float) $row['remain_total']);

        if ($labels === null && !empty($row['id'])) {
            $labels = self::loadRoundSteelLabelsByGroupIds([(int) $row['id']])[(int) $row['id']] ?? null;
        }
        $labels = $labels ?? ['name' => '', 'manufacturer' => '', 'spec' => ''];

        // [AI-2026-08] 合并组表无名称/厂商列，运行时从成员 material_id 回填当前档案。
        $row['y_material_name'] = trim((string) ($row['y_material_name'] ?? '')) !== ''
            ? trim((string) $row['y_material_name'])
            : (string) ($labels['name'] ?? '');
        $row['y_material_manufacturer'] = trim((string) ($row['y_material_manufacturer'] ?? '')) !== ''
            ? trim((string) $row['y_material_manufacturer'])
            : (string) ($labels['manufacturer'] ?? '');

        if (empty($row['y_material_spec']) && !empty($row['id'])) {
            $row['y_material_spec'] = (string) ($labels['spec'] ?? '') !== ''
                ? (string) $labels['spec']
                : self::loadYMaterialSpecDisplay((int) $row['id'], (string) ($row['steel_grade'] ?? ''));
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

    /**
     * 吨数展示：整数不带小数，否则最多 3 位去尾零
     */
    public static function formatTonDisplay(float $ton): string
    {
        if (abs($ton - round($ton)) < 0.0001) {
            return (string) (int) round($ton);
        }
        $s = number_format($ton, 3, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');

        return $s === '' ? '0' : $s;
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
            $reportNum = round((float) ($param['report_num'] ?? 0), 3);
            if ($reportNum <= 0) {
                exception('报工数量必须大于0');
            }
            $cap = (float) ($group['capacity_total'] ?? 0);
            $rep = (float) ($group['reported_total'] ?? 0);
            // [AI-2026-08] 硬上限=计划合计；达标完成(≥97%)后未满计划仍可补报，禁止超计划
            if ($rep + 0.0001 >= $cap) {
                exception('该合并池已报满计划数量');
            }
            if ($rep + $reportNum > $cap + 0.0001) {
                exception('报工数量不能超过计划剩余（剩余' . round(max(0, $cap - $rep), 3) . '吨）');
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
            $newStatus = self::isMergeGroupComplete($newRep, $cap) ? 1 : 0;
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
