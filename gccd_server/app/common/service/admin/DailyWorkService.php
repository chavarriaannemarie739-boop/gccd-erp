<?php

namespace app\common\service\admin;

use think\facade\Db;
use app\common\model\admin\DailyWorkModel;
use app\common\model\admin\UserWageDetailModel;
use app\common\model\admin\ProductReportModel;
use app\common\model\system\UserModel;
use app\common\service\admin\DailySummaryService;

/**
 * 日工服务
 */
class DailyWorkService
{
    /** 满勤小时基准 */
    private const DEFAULT_WORK_HOURS = 12.0;

    /** 全天日工工资 */
    private const DAILY_WAGE_FULL = 200.0;

    /** 半天日工工资 */
    private const DAILY_WAGE_HALF = 100.0;

    /** 班长全天岗位津贴 */
    private const LEADER_ALLOWANCE_FULL = 200.0;

    /**
     * 日工报工（支持多人）
     * @param array $param
     * @return array
     * @throws \think\db\exception
     */
    public static function report(array $param): array
    {
        Db::startTrans();
        try {
            $reporterId = user_id();
            if ($reporterId <= 0) {
                exception('未登录');
            }

            $workDate = $param['work_date'] ?? date('Y-m-d');
            $workType = (int) ($param['work_type'] ?? 1);
            $workHours = floatval($param['work_hours'] ?? ($workType == 1 ? 12 : 6));
            $remark = $param['remark'] ?? '';

            if ($workHours <= 0 || $workHours > self::DEFAULT_WORK_HOURS) {
                exception('工时输入不合法');
            }

            // 获取报工人员列表：优先使用 user_ids，否则为当前用户
            $userIds = $param['user_ids'] ?? [];
            if (empty($userIds)) {
                $userIds = [$reporterId];
            }

            $dailyWage = ($workType == 1) ? self::DAILY_WAGE_FULL : self::DAILY_WAGE_HALF;
            $allowanceRatio = $workHours / self::DEFAULT_WORK_HOURS;
            $now = date('Y-m-d H:i:s');
            $createdIds = [];
            $totalWages = [];

            foreach ($userIds as $workerId) {
                $workerId = (int) $workerId;
                if ($workerId <= 0) {
                    continue;
                }

                // 判断该工人是否为班长
                $isTeamLeader = PublicService::is_class($workerId);
                $teamLeaderAllowance = $isTeamLeader
                    ? round(self::LEADER_ALLOWANCE_FULL * $allowanceRatio, 2)
                    : 0.00;
                $totalWage = round($dailyWage + $teamLeaderAllowance, 2);

                // 写入日工记录
                $dailyWork = new DailyWorkModel();
                $dailyWork->insert([
                    'user_id' => $workerId,
                    'work_date' => $workDate,
                    'work_type' => $workType,
                    'work_hours' => $workHours,
                    'daily_wage' => $dailyWage,
                    'team_leader_allowance' => $teamLeaderAllowance,
                    'total_wage' => $totalWage,
                    'remark' => $remark,
                    'report_id' => 0,
                ]);

                // 创建 product_report 记录
                $reportCode = 'DW' . date('Ymd') . sprintf('%04d', $dailyWork->id);
                $productReport = new ProductReportModel();
                $productReport->insert([
                    'report_code' => $reportCode,
                    'relation_code' => 'DAILY_WORK',
                    'material_id' => 0,
                    'report_num' => 0,
                    'processes_code' => 'daily_work',
                    'class' => 0,
                    'report_date' => $workDate,
                    'data' => json_encode([
                        'work_type' => $workType,
                        'work_hours' => $workHours,
                        'daily_wage' => $dailyWage,
                        'allowance' => $teamLeaderAllowance,
                        'remark' => $remark,
                    ]),
                    'is_daily_work' => 1,
                    'create_time' => $now,
                    'create_uid' => $reporterId,
                ]);

                // 回写 report_id
                $dailyWork->update(['report_id' => $productReport->id]);

                // 写入工资明细 - 日工工资
                $username = user_name($workerId);
                (new UserWageDetailModel())->insert([
                    'user_id' => $workerId,
                    'username' => $username,
                    'date' => $workDate,
                    'wage' => $dailyWage,
                    'relation_code' => $reportCode,
                    'wage_type' => 'daily_work',
                    'create_time' => $now,
                    'create_uid' => $reporterId,
                ]);

                // 岗位津贴
                if ($teamLeaderAllowance > 0) {
                    (new UserWageDetailModel())->insert([
                        'user_id' => $workerId,
                        'username' => $username,
                        'date' => $workDate,
                        'wage' => $teamLeaderAllowance,
                        'relation_code' => $reportCode,
                        'wage_type' => 'team_leader_allowance',
                        'create_time' => $now,
                        'create_uid' => $reporterId,
                    ]);
                }

                $createdIds[] = $dailyWork->id;
                $totalWages[] = $totalWage;
            }

            // 更新日汇总表
            try {
                DailySummaryService::refresh($workDate);
            } catch (\Exception $e) {
            }

            Db::commit();

            return [
                'ids' => $createdIds,
                'count' => count($createdIds),
                'total_wage' => round(array_sum($totalWages), 2),
            ];
        } catch (\Exception $e) {
            Db::rollback();
            exception('日工报工失败：' . $e->getMessage());
        }
    }

    /**
     * 修改日工报工
     * @param int $id
     * @param array $param
     * @return array
     * @throws \think\db\exception
     */
    public static function update(int $id, array $param): array
    {
        Db::startTrans();
        try {
            $dailyWork = DailyWorkModel::find($id);
            if (!$dailyWork) {
                exception('日工记录不存在');
            }

            $oldDate = $dailyWork['work_date'];

            $workDate = $param['work_date'] ?? $dailyWork['work_date'];
            $workType = (int) ($param['work_type'] ?? $dailyWork['work_type']);
            $workHours = floatval($param['work_hours'] ?? $dailyWork['work_hours']);
            $remark = $param['remark'] ?? $dailyWork['remark'];

            if ($workHours <= 0 || $workHours > self::DEFAULT_WORK_HOURS) {
                exception('工时输入不合法');
            }

            // 重新计算工资
            $dailyWage = ($workType == 1) ? self::DAILY_WAGE_FULL : self::DAILY_WAGE_HALF;
            $isTeamLeader = PublicService::is_class($dailyWork['user_id']);
            $allowanceRatio = $workHours / self::DEFAULT_WORK_HOURS;
            $teamLeaderAllowance = round(self::LEADER_ALLOWANCE_FULL * $allowanceRatio, 2);
            $totalWage = round($dailyWage + $teamLeaderAllowance, 2);

            // 更新日工记录
            $dailyWork->update([
                'work_date' => $workDate,
                'work_type' => $workType,
                'work_hours' => $workHours,
                'daily_wage' => $dailyWage,
                'team_leader_allowance' => $teamLeaderAllowance,
                'total_wage' => $totalWage,
                'remark' => $remark,
            ]);

            // 更新 product_report 记录
            $reportId = $dailyWork['report_id'] ?? 0;
            if ($reportId > 0) {
                $productReport = (new ProductReportModel())->where('id', $reportId)->find();
            } else {
                // 回退：通过 report_code 查找
                $reportCode = 'DW' . date('Ymd', strtotime($dailyWork['create_time'])) . sprintf('%04d', $id);
                $productReport = (new ProductReportModel())->where('report_code', $reportCode)->find();
            }
            if ($productReport) {
                $productReport->update([
                'report_date' => $workDate,
                'data' => json_encode([
                    'work_type' => $workType,
                    'work_hours' => $workHours,
                    'daily_wage' => $dailyWage,
                    'allowance' => $teamLeaderAllowance,
                    'remark' => $remark,
                ]),
                ]);

            // 删除旧工资明细
                (new UserWageDetailModel())
                ->where('relation_code', $productReport['report_code'])
                ->delete();

            // 写入新工资明细
                $username = user_name($dailyWork['user_id']);
                $now = date('Y-m-d H:i:s');

                (new UserWageDetailModel())->insert([
                'user_id' => $dailyWork['user_id'],
                'username' => $username,
                'date' => $workDate,
                'wage' => $dailyWage,
                'relation_code' => $productReport['report_code'],
                'wage_type' => 'daily_work',
                'create_time' => $now,
                'create_uid' => user_id(),
                ]);

                if ($teamLeaderAllowance > 0) {
                    (new UserWageDetailModel())->insert([
                    'user_id' => $dailyWork['user_id'],
                    'username' => $username,
                    'date' => $workDate,
                    'wage' => $teamLeaderAllowance,
                    'relation_code' => $productReport['report_code'],
                    'wage_type' => 'team_leader_allowance',
                    'create_time' => $now,
                    'create_uid' => user_id(),
                    ]);
                }
            } // end if ($productReport)

            // 更新日汇总
            try {
                DailySummaryService::refresh($oldDate);
                DailySummaryService::refresh($workDate);
            } catch (\Exception $e) {
            }

            Db::commit();

            return ['id' => $id, 'total_wage' => $totalWage];
        } catch (\Exception $e) {
            Db::rollback();
            exception('修改日工报工失败：' . $e->getMessage());
        }
    }

    /**
     * 删除日工报工
     * @param int $id
     * @throws \think\db\exception
     */
    public static function delete(int $id): void
    {
        Db::startTrans();
        try {
            $dailyWork = DailyWorkModel::find($id);
            if (!$dailyWork) {
                exception('日工记录不存在');
            }

            $oldDate = $dailyWork['work_date'];
            $reportId = $dailyWork['report_id'] ?? 0;
            if ($reportId > 0) {
                $reportCode = (new ProductReportModel())
                    ->where('id', $reportId)
                    ->value('report_code');
            } else {
                $reportCode = 'DW' . date('Ymd', strtotime($dailyWork['create_time'])) . sprintf('%04d', $id);
            }

            // 删除日工记录
            $dailyWork->delete();

            // 删除 product_report 记录
            if ($reportId > 0) {
                (new ProductReportModel())->where('id', $reportId)->delete();
            } else {
                (new ProductReportModel())->where('report_code', $reportCode)->delete();
            }

            // 删除工资明细
            (new UserWageDetailModel())
                ->where('relation_code', $reportCode)
                ->delete();

            // 更新日汇总
            try {
                DailySummaryService::refresh($oldDate);
            } catch (\Exception $e) {
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('删除日工报工失败：' . $e->getMessage());
        }
    }

    /**
     * 日工列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     */
    public static function list(array $where = [], int $page = 1, int $limit = 10): array
    {
        $model = new DailyWorkModel();
        $query = $model->alias('dw')
            ->join('ya_user u', 'dw.user_id = u.user_id')
            ->field('dw.*, u.nickname, u.phone, dw.id as daily_work_id');

        // 条件筛选
        if (!empty($where)) {
            if (!empty($where['work_date'])) {
                if (strpos($where['work_date'], '~') !== false) {
                    [$start, $end] = explode('~', $where['work_date']);
                    $query->whereBetween('dw.work_date', [trim($start), trim($end)]);
                } else {
                    $query->where('dw.work_date', $where['work_date']);
                }
            }
            if (!empty($where['user_id'])) {
                $query->where('dw.user_id', $where['user_id']);
            }
        }

        $count = $query->count();
        $list = $query->order('dw.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return compact('count', 'page', 'limit', 'list');
    }

    /**
     * 日工统计
     * @param array $where
     * @return array
     */
    public static function stat(array $where = []): array
    {
        $model = new DailyWorkModel();
        $query = $model;

        if (!empty($where['work_date'])) {
            if (strpos($where['work_date'], '~') !== false) {
                [$start, $end] = explode('~', $where['work_date']);
                $query->whereBetween('work_date', [trim($start), trim($end)]);
            } else {
                $query->where('work_date', $where['work_date']);
            }
        }

        $stats = $query->field(
            'SUM(daily_wage) as daily_wage_total,
             SUM(team_leader_allowance) as allowance_total,
             SUM(total_wage) as wage_total,
             COUNT(DISTINCT user_id) as user_count'
        )->find();

        return [
            'daily_wage_total' => floatval($stats['daily_wage_total'] ?? 0),
            'allowance_total' => floatval($stats['allowance_total'] ?? 0),
            'wage_total' => floatval($stats['wage_total'] ?? 0),
            'user_count' => intval($stats['user_count'] ?? 0),
        ];
    }

    /**
     * 按日期获取日工记录
     * @param int $userId
     * @param string $date
     * @return array
     */
    public static function getByDate(int $userId, string $date): array
    {
        $records = (new DailyWorkModel())
            ->where('user_id', $userId)
            ->where('work_date', $date)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $totalDailyWage = 0;
        $totalAllowance = 0;

        foreach ($records as $r) {
            $totalDailyWage += (float) ($r['daily_wage'] ?? 0);
            $totalAllowance += (float) ($r['team_leader_allowance'] ?? 0);
        }

        return [
            'records' => $records,
            'total_daily_wage' => round($totalDailyWage, 2),
            'total_allowance' => round($totalAllowance, 2),
            'total_wage' => round($totalDailyWage + $totalAllowance, 2),
        ];
    }
}
