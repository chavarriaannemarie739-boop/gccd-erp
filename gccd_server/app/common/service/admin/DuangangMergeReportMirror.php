<?php

namespace app\common\service\admin;

use think\facade\Db;

/**
 * 断钢合并报工 → ya_product_report 镜像（报工记录、盘点、日汇总、工资撤销联表）
 */
class DuangangMergeReportMirror
{
    public const REMARK_PREFIX = 'duangang_merge:g';

    /**
     * 合并报工成功后写入 product_report 并按 FIFO 分摊到成员工单
     *
     * @param array<string,mixed> $mergeRow ya_duangang_merge_report 行
     * @param array<string,mixed> $group    ya_duangang_merge_group 行
     *
     * @param bool $isBackfill 历史回填：成员可能已软删、remain_capacity 为 0
     *
     * @return array{allocations:array<int,array<string,mixed>>,primary_task_code:string,primary_material_id:int}
     */
    public static function syncFromMergeReport(array $mergeRow, array $group, bool $isBackfill = false): array
    {
        $groupId = (int) ($mergeRow['merge_group_id'] ?? 0);
        $mainCode = trim((string) ($mergeRow['report_code'] ?? ''));
        if ($groupId <= 0 || $mainCode === '') {
            return ['allocations' => [], 'primary_task_code' => '', 'primary_material_id' => 0];
        }

        if (self::hasMirrorForMainCode($mainCode)) {
            return self::loadExistingAllocationMeta($mainCode);
        }

        $reportNum = round((float) ($mergeRow['report_num'] ?? 0), 3);
        $defectiveNum = round((float) ($mergeRow['defective_num'] ?? 0), 3);
        $processesCode = (string) ($mergeRow['processes_code'] ?? '');

        $members = self::loadMembersForMirror($groupId, $isBackfill);
        $allocations = self::allocateFifo($members, $reportNum, $defectiveNum, false);
        if ($allocations === [] && $isBackfill) {
            $allocations = self::allocateFifo($members, $reportNum, $defectiveNum, true);
        }
        if ($allocations === [] && $isBackfill) {
            $allocations = self::allocateFallbackSingle($members, $reportNum, $defectiveNum);
        }
        if ($allocations === [] && $isBackfill) {
            $fallback = self::resolveFallbackTaskFromMergeRow($mergeRow, $reportNum, $defectiveNum);
            if ($fallback !== null) {
                $allocations = [$fallback];
            }
        }
        if ($allocations === []) {
            exception('合并报工镜像失败：无可用成员工单');
        }

        $remark = self::buildRemark($groupId, $mainCode);
        $taskKeys = [];
        $isFirst = true;

        foreach ($allocations as $alloc) {
            $rowCode = $isFirst ? $mainCode : ($mainCode . '-m' . (int) $alloc['member_id']);
            $isFirst = false;

            $taskRow = ProductReportService::resolveTaskRowForReportPublic(
                (string) $alloc['task_code'],
                (int) $alloc['material_id']
            );
            $derived = ProductReportService::taskDerivedReportFieldsPublic($taskRow);

            $payload = [
                'duangang_merge' => true,
                'merge_group_id' => $groupId,
                'main_report_code' => $mainCode,
                'member_id' => (int) $alloc['member_id'],
                'share_num' => (float) $alloc['share_num'],
                'device_code' => (string) ($mergeRow['device_code'] ?? ''),
                'device_name' => (string) ($mergeRow['device_name'] ?? ''),
            ];

            Db::name('product_report')->insert(array_merge([
                'report_code' => $rowCode,
                'relation_code' => (string) $alloc['task_code'],
                'material_id' => (int) $alloc['material_id'],
                'processes_code' => $processesCode,
                'report_num' => (float) $alloc['share_num'],
                'defective_num' => (float) $alloc['defective_num'],
                'defective' => (string) ($mergeRow['defective'] ?? '[]'),
                'device_code' => (string) ($mergeRow['device_code'] ?? ''),
                'report_date' => $mergeRow['report_date'],
                'class' => (int) ($mergeRow['class'] ?? 0),
                'create_uid' => (int) ($mergeRow['create_uid'] ?? 0),
                'create_time' => (string) ($mergeRow['create_time'] ?? datetime()),
                'remark' => $remark,
                'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'is_delete' => 0,
            ], $derived));

            $taskKeys[(string) $alloc['task_code'] . "\0" . (int) $alloc['material_id']] = [
                'task_code' => (string) $alloc['task_code'],
                'material_id' => (int) $alloc['material_id'],
            ];
        }

        foreach ($taskKeys as $pair) {
            ProductReportService::rebuildTaskReportRollup($pair['task_code'], $pair['material_id']);
        }

        $first = $allocations[0];

        return [
            'allocations' => $allocations,
            'primary_task_code' => (string) ($first['task_code'] ?? ''),
            'primary_material_id' => (int) ($first['material_id'] ?? 0),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadMembersForMirror(int $groupId, bool $includeDeleted): array
    {
        $query = Db::name('duangang_merge_member')
            ->where('merge_group_id', $groupId)
            ->order('id', 'asc');
        if (!$includeDeleted) {
            $query->where('is_delete', 0);
        }

        return $query->select()->toArray();
    }

    /**
     * @param array<int,array<string,mixed>> $members
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allocateFifo(
        array $members,
        float $reportNum,
        float $defectiveNum,
        bool $useTaskNumWhenNoCapacity = false
    ): array {
        $remaining = round($reportNum, 3);
        $result = [];
        if ($remaining <= 0.0001) {
            return $result;
        }

        foreach ($members as $m) {
            if ($remaining <= 0.0001) {
                break;
            }
            $cap = round((float) ($m['remain_capacity'] ?? 0), 3);
            if ($cap <= 0.0001 && $useTaskNumWhenNoCapacity) {
                $cap = round((float) ($m['task_num'] ?? 0), 3);
            }
            if ($cap <= 0.0001) {
                continue;
            }
            $share = round(min($cap, $remaining), 3);
            $defShare = $reportNum > 0.0001
                ? round($defectiveNum * ($share / $reportNum), 3)
                : 0.0;
            $result[] = [
                'member_id' => (int) ($m['id'] ?? 0),
                'task_code' => (string) ($m['task_code'] ?? ''),
                'material_id' => (int) ($m['material_id'] ?? 0),
                'share_num' => $share,
                'defective_num' => $defShare,
            ];
            $remaining = round($remaining - $share, 3);
        }

        if ($remaining > 0.0001 && $result !== []) {
            $lastIdx = count($result) - 1;
            $result[$lastIdx]['share_num'] = round((float) $result[$lastIdx]['share_num'] + $remaining, 3);
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $members
     *
     * @return array<int,array<string,mixed>>
     */
    private static function allocateFallbackSingle(array $members, float $reportNum, float $defectiveNum): array
    {
        foreach ($members as $m) {
            $taskCode = trim((string) ($m['task_code'] ?? ''));
            $materialId = (int) ($m['material_id'] ?? 0);
            if ($taskCode === '' || $materialId <= 0) {
                continue;
            }

            return [[
                'member_id' => (int) ($m['id'] ?? 0),
                'task_code' => $taskCode,
                'material_id' => $materialId,
                'share_num' => round($reportNum, 3),
                'defective_num' => round($defectiveNum, 3),
            ]];
        }

        return [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function resolveFallbackTaskFromMergeRow(
        array $mergeRow,
        float $reportNum,
        float $defectiveNum
    ): ?array {
        $materialId = (int) ($mergeRow['material_id'] ?? 0);
        if ($materialId <= 0) {
            return null;
        }
        $task = Db::name('product_task')
            ->where('material_id', $materialId)
            ->where('is_delete', 0)
            ->order('id', 'desc')
            ->find();
        if (empty($task)) {
            $task = Db::name('product_task')
                ->where('material_id', $materialId)
                ->order('id', 'desc')
                ->find();
        }
        if (empty($task)) {
            return null;
        }

        return [
            'member_id' => 0,
            'task_code' => (string) ($task['task_code'] ?? ''),
            'material_id' => $materialId,
            'share_num' => round($reportNum, 3),
            'defective_num' => round($defectiveNum, 3),
        ];
    }

    public static function buildRemark(int $groupId, string $mainCode): string
    {
        return self::REMARK_PREFIX . $groupId . ':c' . $mainCode;
    }

    public static function resolveMainReportCode(array $report): string
    {
        $remark = (string) ($report['remark'] ?? '');
        if (preg_match('/duangang_merge:g\d+:c(.+)$/u', $remark, $m)) {
            return trim($m[1]);
        }
        $data = json_decode((string) ($report['data'] ?? '{}'), true);
        if (is_array($data) && !empty($data['main_report_code'])) {
            return trim((string) $data['main_report_code']);
        }
        $code = trim((string) ($report['report_code'] ?? ''));
        if ($code !== '' && Db::name('duangang_merge_report')->where('report_code', $code)->where('is_delete', 0)->find()) {
            return $code;
        }
        if (preg_match('/^(.+)-m\d+$/u', $code, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    public static function isDuangangMergeMirror(array $report): bool
    {
        return self::resolveMainReportCode($report) !== '';
    }

    /**
     * 后台删报工时同步软删 merge 表及同组镜像行
     *
     * @return array<int,array{task_code:string,material_id:int}>
     */
    public static function syncOnDelete(array $report): array
    {
        $mainCode = self::resolveMainReportCode($report);
        if ($mainCode === '') {
            return [];
        }

        $taskKeys = self::removeMirrorRows($mainCode);

        $merge = Db::name('duangang_merge_report')
            ->where('report_code', $mainCode)
            ->where('is_delete', 0)
            ->find();

        if (!empty($merge)) {
            $groupId = (int) ($merge['merge_group_id'] ?? 0);
            $deduct = round((float) ($merge['report_num'] ?? 0), 3);
            Db::name('duangang_merge_report')->where('id', (int) $merge['id'])->update([
                'is_delete' => 1,
                'update_time' => datetime(),
            ]);
            if ($groupId > 0 && $deduct > 0) {
                $group = Db::name('duangang_merge_group')->where('id', $groupId)->find();
                if (!empty($group)) {
                    $newRep = round(max(0.0, (float) ($group['reported_total'] ?? 0) - $deduct), 3);
                    $cap = (float) ($group['capacity_total'] ?? 0);
                    $status = ($cap > 0.0001 && $newRep >= $cap - 0.0001) ? 1 : 0;
                    Db::name('duangang_merge_group')->where('id', $groupId)->update([
                        'reported_total' => $newRep,
                        'status' => $status,
                        'update_time' => datetime(),
                    ]);
                }
            }
        }

        return array_values($taskKeys);
    }

    /**
     * 编辑主报工数量后重建分摊镜像
     */
    public static function resyncAfterEdit(array $oldReport, array $newReport): void
    {
        $mainCode = self::resolveMainReportCode($newReport);
        if ($mainCode === '') {
            return;
        }

        if ((string) ($newReport['report_code'] ?? '') !== $mainCode) {
            return;
        }

        $oldNum = round((float) ($oldReport['report_num'] ?? 0), 3);
        $newNum = round((float) ($newReport['report_num'] ?? 0), 3);
        if (abs($oldNum - $newNum) < 0.0001
            && (string) ($oldReport['report_date'] ?? '') === (string) ($newReport['report_date'] ?? '')) {
            return;
        }

        $merge = Db::name('duangang_merge_report')
            ->where('report_code', $mainCode)
            ->where('is_delete', 0)
            ->find();
        if (empty($merge)) {
            return;
        }

        $groupId = (int) ($merge['merge_group_id'] ?? 0);
        $numDelta = round($newNum - $oldNum, 3);

        Db::name('duangang_merge_report')->where('id', (int) $merge['id'])->update([
            'report_num' => $newNum,
            'report_date' => $newReport['report_date'] ?? $merge['report_date'],
            'update_time' => datetime(),
        ]);

        if ($groupId > 0 && abs($numDelta) > 0.0001) {
            $group = Db::name('duangang_merge_group')->where('id', $groupId)->find();
            if (!empty($group)) {
                $newRep = round(max(0.0, (float) ($group['reported_total'] ?? 0) + $numDelta), 3);
                $cap = (float) ($group['capacity_total'] ?? 0);
                $status = ($cap > 0.0001 && $newRep >= $cap - 0.0001) ? 1 : 0;
                Db::name('duangang_merge_group')->where('id', $groupId)->update([
                    'reported_total' => $newRep,
                    'status' => $status,
                    'update_time' => datetime(),
                ]);
            }
        }

        $taskKeys = self::removeMirrorRows($mainCode);
        foreach ($taskKeys as $pair) {
            ProductReportService::rebuildTaskReportRollup($pair['task_code'], $pair['material_id']);
        }

        $merge['report_num'] = $newNum;
        $merge['report_date'] = $newReport['report_date'] ?? $merge['report_date'];
        $group = Db::name('duangang_merge_group')->where('id', $groupId)->where('is_delete', 0)->find();
        if (!empty($group)) {
            self::syncFromMergeReport($merge, $group);
        }
    }

    /**
     * @return array<int,array{task_code:string,material_id:int}>
     */
    public static function removeMirrorRows(string $mainCode): array
    {
        if ($mainCode === '') {
            return [];
        }

        $mirrorRows = Db::name('product_report')
            ->where(function ($q) use ($mainCode) {
                $q->where('report_code', $mainCode)
                    ->whereOr('report_code', 'like', $mainCode . '-m%');
            })
            ->where('is_delete', 0)
            ->select()
            ->toArray();

        $taskKeys = [];
        foreach ($mirrorRows as $row) {
            $tc = (string) ($row['relation_code'] ?? '');
            $mid = (int) ($row['material_id'] ?? 0);
            if ($tc !== '' && $mid > 0) {
                $taskKeys[$tc . "\0" . $mid] = ['task_code' => $tc, 'material_id' => $mid];
            }
        }

        if ($mirrorRows !== []) {
            Db::name('product_report')
                ->where(function ($q) use ($mainCode) {
                    $q->where('report_code', $mainCode)
                        ->whereOr('report_code', 'like', $mainCode . '-m%');
                })
                ->where('is_delete', 0)
                ->update(['is_delete' => 1]);
        }

        return array_values($taskKeys);
    }

    /**
     * 删除时扩展为同组全部镜像 id
     *
     * @param array<int> $ids
     *
     * @return array<int>
     */
    public static function expandDeleteIds(array $ids): array
    {
        if ($ids === []) {
            return $ids;
        }
        $rows = Db::name('product_report')->whereIn('id', $ids)->where('is_delete', 0)->select()->toArray();
        $expanded = $ids;
        foreach ($rows as $row) {
            $mainCode = self::resolveMainReportCode($row);
            if ($mainCode === '') {
                continue;
            }
            $siblingIds = Db::name('product_report')
                ->where(function ($q) use ($mainCode) {
                    $q->where('report_code', $mainCode)
                        ->whereOr('report_code', 'like', $mainCode . '-m%');
                })
                ->where('is_delete', 0)
                ->column('id');
            foreach ($siblingIds as $sid) {
                $expanded[] = (int) $sid;
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    /**
     * 历史回填：补缺失镜像
     *
     * @return array{scanned:int,mirrored:int,skipped:int,failed:int,errors:array<int,string>}
     */
    public static function backfillMissing(bool $dryRun = true): array
    {
        $rows = Db::name('duangang_merge_report')
            ->where('is_delete', 0)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $mirrored = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        foreach ($rows as $mergeRow) {
            $mainCode = trim((string) ($mergeRow['report_code'] ?? ''));
            if ($mainCode === '' || self::hasMirrorForMainCode($mainCode)) {
                $skipped++;
                continue;
            }
            if ($dryRun) {
                $mirrored++;
                continue;
            }
            $groupId = (int) ($mergeRow['merge_group_id'] ?? 0);
            $group = Db::name('duangang_merge_group')->where('id', $groupId)->find();
            if (empty($group)) {
                $skipped++;
                continue;
            }
            try {
                self::syncFromMergeReport($mergeRow, $group, true);
                $mirrored++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $mainCode . ': ' . $e->getMessage();
            }
        }

        return [
            'scanned' => count($rows),
            'mirrored' => $mirrored,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private static function hasMirrorForMainCode(string $mainCode): bool
    {
        return (bool) Db::name('product_report')
            ->where('report_code', $mainCode)
            ->where('is_delete', 0)
            ->count();
    }

    /**
     * @return array{allocations:array<int,array<string,mixed>>,primary_task_code:string,primary_material_id:int}
     */
    private static function loadExistingAllocationMeta(string $mainCode): array
    {
        $rows = Db::name('product_report')
            ->where(function ($q) use ($mainCode) {
                $q->where('report_code', $mainCode)->whereOr('report_code', 'like', $mainCode . '-m%');
            })
            ->where('is_delete', 0)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $allocations = [];
        foreach ($rows as $row) {
            $allocations[] = [
                'member_id' => 0,
                'task_code' => (string) ($row['relation_code'] ?? ''),
                'material_id' => (int) ($row['material_id'] ?? 0),
                'share_num' => (float) ($row['report_num'] ?? 0),
                'defective_num' => (float) ($row['defective_num'] ?? 0),
            ];
        }
        $first = $allocations[0] ?? ['task_code' => '', 'material_id' => 0];

        return [
            'allocations' => $allocations,
            'primary_task_code' => (string) ($first['task_code'] ?? ''),
            'primary_material_id' => (int) ($first['material_id'] ?? 0),
        ];
    }
}
