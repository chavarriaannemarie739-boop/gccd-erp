<?php

namespace app\common\service\admin;

use think\facade\Db;
use app\common\config\DailyReportConfig;
use app\common\model\admin\UserWageModel;
use app\common\model\admin\UserWageDetailModel;
use app\common\model\admin\ProcessWageModel;
use app\common\model\admin\SupervisorAttendanceModel;
use app\common\service\admin\DailySummaryService;

class SupervisorWageService
{
    /** 满勤小时基准 */
    private const DEFAULT_WORK_HOURS = 12.0;

    /**
     * 获取用户固定工资配置（来自 ya_process_wage）
     */
    public static function getFixedWageConfig(int $userId): ?array
    {
        $row = ProcessWageModel::where('user_id', $userId)
            ->where('status', 1)
            ->where('is_delete', 0)
            ->order('id', 'desc')
            ->find();
        return empty($row) ? null : $row->toArray();
    }

    /**
     * 根据配置计算当天日薪基准
     * @param array $processWageRow 固定工资配置行
     * @param string $date 日期 Y-m-d
     * @return float 日薪基准（元/天）
     */
    public static function dailyBaseFromConfig(array $processWageRow, string $date = ''): float
    {
        $amount  = (float) ($processWageRow['wage'] ?? 0);
        $isMonth = (int) ($processWageRow['is_month'] ?? 0);
        if ($isMonth === 1) {
            $dateStr    = $date ?: date('Y-m-d');
            $daysInMonth = (int) date('t', strtotime($dateStr));
            return $daysInMonth > 0 ? round($amount / $daysInMonth, 2) : $amount;
        }
        return $amount;
    }

    /**
     * 签退时实时写入当日工资（relation_code='ATTENDANCE'）
     * 若 process_wage 配置了 processes_code，按工序均摊到每个设备班次成本；
     * 否则写入单条记录（不含设备班次信息）。
     * @param int $userId
     * @param string $attendanceDate Y-m-d
     * @param float $workHours 实际工时
     * @return array ['written'=>bool, 'wage'=>float, 'msg'=>string]
     */
    public static function writeDailyWage(int $userId, string $attendanceDate, float $workHours): array
    {
        $config = self::getFixedWageConfig($userId);
        if (!$config) {
            return ['written' => false, 'wage' => 0, 'msg' => '未配置固定工资'];
        }

        $dailyBase = self::dailyBaseFromConfig($config, $attendanceDate);
        $todayWage = round($dailyBase / self::DEFAULT_WORK_HOURS * $workHours, 2);

        if ($todayWage <= 0) {
            return ['written' => false, 'wage' => 0, 'msg' => '日工资为0'];
        }

        // 检查是否已写入（防重复）
        $detail = new UserWageDetailModel();
        $exists = $detail->where('user_id', $userId)
            ->where('date', $attendanceDate)
            ->where('relation_code', 'ATTENDANCE')
            ->find();

        if ($exists) {
            return ['written' => false, 'wage' => $todayWage, 'msg' => '当日工资已写入'];
        }

        $year  = substr($attendanceDate, 0, 4);
        $month = substr($attendanceDate, 5, 2);
        $username = user_name($userId);
        $now      = date('Y-m-d H:i:s');

        // 解析工序编码，获取设备+班次组合
        $processesCode = trim($config['processes_code'] ?? '');
        $pairs = [];
        if ($processesCode !== '') {
            $pairs = self::resolveDeviceClassPairs($processesCode);
        }

        Db::startTrans();
        try {
            if (!empty($pairs)) {
                // 有工序配置：均摊到每个设备班次组合，使日报成本汇总能自动归集
                $count = count($pairs);
                $shareAmount = round($todayWage / $count, 2);
                $allocSum = 0;
                foreach ($pairs as $i => $pair) {
                    $amount = ($i === $count - 1)
                        ? round($todayWage - $allocSum, 2)
                        : $shareAmount;
                    $allocSum += $amount;

                    $detail->insert([
                        'user_id'       => $userId,
                        'username'      => $username ?: '未知',
                        'date'          => $attendanceDate,
                        'wage'          => $amount,
                        'wage_sum'      => $amount,
                        'report_num'    => 0,
                        'relation_code' => 'ATTENDANCE',
                        'device_code'   => $pair['device_code'],
                        'class'         => $pair['class'],
                        'create_time'   => $now,
                        'create_uid'    => user_id(),
                    ]);
                }
            } else {
                // 无工序配置：单条记录，不含设备班次（不影响日报成本汇总，但保留月工资记录）
                $detail->insert([
                    'user_id'       => $userId,
                    'username'      => $username ?: '未知',
                    'date'          => $attendanceDate,
                    'wage'          => $todayWage,
                    'wage_sum'      => $todayWage,
                    'report_num'    => 0,
                    'relation_code' => 'ATTENDANCE',
                    'create_time'   => $now,
                    'create_uid'    => user_id(),
                ]);
            }

            // 更新/创建月汇总 user_wage
            $wageModel = new UserWageModel();
            $existing = $wageModel->where('user_id', $userId)
                ->where('year', $year)
                ->where('month', $month)
                ->find();

            if ($existing) {
                $newTotal = (float) $existing['wage'] + $todayWage;
                $wageModel->where('id', $existing['id'])->update([
                    'wage'        => $newTotal,
                    'update_time' => $now,
                    'update_uid'  => user_id(),
                ]);
            } else {
                $wageModel->insert([
                    'user_id'     => $userId,
                    'year'        => $year,
                    'month'       => $month,
                    'wage'        => $todayWage,
                    'create_time' => $now,
                    'create_uid'  => user_id(),
                    'update_time' => $now,
                    'update_uid'  => user_id(),
                ]);
            }

            Db::commit();

            // 刷新当日日报汇总，使主任工资成本立即归集到各工序班次
            if (!empty($pairs)) {
                DailySummaryService::refresh($attendanceDate);
            }

            return ['written' => true, 'wage' => $todayWage, 'msg' => '写入成功'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['written' => false, 'wage' => 0, 'msg' => '写入失败：' . $e->getMessage()];
        }
    }

    /**
     * 根据工序编码解析设备+班次组合
     * 从 DailyReportConfig 中匹配以 processesCode 开头的子项，提取去重后的 (device_code, class) 列表。
     * 支持多工序逗号分隔，如 "断钢,矫直,酸洗"。
     *
     * @param string $processesCode 工序编码
     * @return array 每个元素: ['device_code'=>string, 'class'=>int]
     */
    private static function resolveDeviceClassPairs(string $processesCode): array
    {
        $pairs = [];
        $codes = explode(',', $processesCode);
        $seen = [];

        // 设备名称→数据库实际名称映射（与 DailySummaryService 一致）
        $deviceNameMap = [
            '球化南1'   => '球化南',
            '穿孔40机'  => '穿孔40机',
            '穿孔50机'  => '穿孔50机',
            '球化北'    => '球化北',
            '酸洗车间'  => '酸洗车间',
        ];

        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }

            foreach (DailyReportConfig::$processMapping as $key => $info) {
                // 模糊匹配：工序编码为子项前缀（如"穿孔"匹配"穿孔甲/乙/丙/丁"）
                if (strpos($key, $code) !== 0) {
                    continue;
                }
                $type = $info['type'] ?? '';
                if (in_array($type, ['statistics', 'auxiliary'])) {
                    continue;
                }

                $deviceNames = [];
                if ($type === 'single') {
                    $deviceNames = [$info['device'] ?? ''];
                } elseif ($type === 'multiple') {
                    // 多设备工序只需取第一台（calcWage 会 WHERE IN 该工序所有设备汇总）
                    $deviceNames = isset($info['devices'][0]) ? [$info['devices'][0]] : [];
                }

                foreach ($deviceNames as $dName) {
                    if ($dName === '') {
                        continue;
                    }
                    $mapped = $deviceNameMap[$dName] ?? $dName;
                    $dCode = Db::name('device')
                        ->where('device_name', $mapped)
                        ->where('is_delete', 0)
                        ->value('device_code');
                    if (empty($dCode)) {
                        continue;
                    }

                    // 每个设备班次覆盖 class=0（白班）和 class=1（夜班），对应日报汇总的迭代
                    foreach ([0, 1] as $class) {
                        $ukey = $dCode . '_' . $class;
                        if (!isset($seen[$ukey])) {
                            $seen[$ukey] = true;
                            $pairs[] = ['device_code' => $dCode, 'class' => $class];
                        }
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * 计算某主任的月工资（基于签到数据）
     * @param int $userId
     * @param string $year  YYYY
     * @param string $month MM
     * @return array ['wage'=>, 'attendance_days'=>, 'attendance_hours'=>, 'daily_base'=>, 'details'=>]
     */
    public static function calculateMonthlyWage(int $userId, string $year, string $month): array
    {
        $monthStart = "{$year}-{$month}-01";
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $daysInMonth = (int) date('t', strtotime($monthStart));

        // 查签到记录
        $attendanceRecords = SupervisorAttendanceModel::where('user_id', $userId)
            ->where('attendance_date', '>=', $monthStart)
            ->where('attendance_date', '<=', $monthEnd)
            ->select()
            ->toArray();

        $attendanceDays  = count($attendanceRecords);
        $totalHours      = 0;
        $adjustedDays    = 0; // 不满勤折算后的等效天数

        foreach ($attendanceRecords as $r) {
            $hours = (float) ($r['work_hours'] ?? self::DEFAULT_WORK_HOURS);
            $totalHours += $hours;
            $adjustedDays += $hours / self::DEFAULT_WORK_HOURS;
        }

        // 查固定工资配置
        $processRow = ProcessWageModel::where('user_id', $userId)
            ->where('status', 1)
            ->where('is_delete', 0)
            ->order('id', 'desc')
            ->find();

        $monthlySalary = 0;
        $dailyBase     = 0;
        $configExists  = false;

        if ($processRow) {
            $configExists  = true;
            $amount        = (float) ($processRow['wage'] ?? 0);
            $isMonth       = (int) ($processRow['is_month'] ?? 0);

            if ($isMonth === 1) {
                $monthlySalary = $amount;
                $dailyBase     = $daysInMonth > 0 ? $amount / $daysInMonth : $amount;
            } else {
                // 日薪
                $dailyBase     = $amount;
                $monthlySalary = $amount * $daysInMonth; // 满月工资
            }
        }

        // 实发工资 = 日基准 × 等效出勤天数
        $actualWage = round($dailyBase * $adjustedDays, 2);

        return [
            'user_id'          => $userId,
            'year'             => $year,
            'month'            => $month,
            'monthly_salary'   => $monthlySalary,
            'daily_base'       => round($dailyBase, 2),
            'days_in_month'    => $daysInMonth,
            'attendance_days'  => $attendanceDays,
            'attendance_hours' => round($totalHours, 1),
            'adjusted_days'    => round($adjustedDays, 2),
            'actual_wage'      => $actualWage,
            'config_exists'    => $configExists,
            'records'          => $attendanceRecords,
        ];
    }

    /**
     * 生成并写入主任月度工资（写入 user_wage + user_wage_details）
     * @param int $userId
     * @param string $year  YYYY
     * @param string $month MM
     * @return array
     */
    public static function generateMonthlyWage(int $userId, string $year, string $month): array
    {
        // 1. 计算工资
        $calc = self::calculateMonthlyWage($userId, $year, $month);

        if (!$calc['config_exists']) {
            exception('该用户未配置固定工资，请先在计件定额表中设置（is_month=1）');
        }

        if ($calc['attendance_days'] <= 0) {
            exception('该用户本月无签到记录，无法生成工资');
        }

        Db::startTrans();
        try {
            $model = new UserWageModel();

            // 2. 写入/更新 user_wage（月汇总）
            $existing = $model->where('user_id', $userId)
                ->where('year', $year)
                ->where('month', $month)
                ->find();

            if ($existing) {
                // 如果有已有的报工工资，累加上去
                $newWage = (float) $existing['wage'] + $calc['actual_wage'];
                $model->where('id', $existing['id'])->update([
                    'wage'        => $newWage,
                    'update_time' => date('Y-m-d H:i:s'),
                    'update_uid'  => user_id(),
                ]);
                $userWageId = $existing['id'];
            } else {
                $userData = [
                    'user_id'     => $userId,
                    'year'        => $year,
                    'month'       => $month,
                    'wage'        => $calc['actual_wage'],
                    'create_time' => date('Y-m-d H:i:s'),
                    'create_uid'  => user_id(),
                    'update_time' => date('Y-m-d H:i:s'),
                    'update_uid'  => user_id(),
                ];
                $userWageId = $model->insertGetId($userData);
            }

            // 3. 写入 user_wage_details（每日明细）
            $detail = new UserWageDetailModel();
            $username = user_name($userId);

            foreach ($calc['records'] as $record) {
                $recordDate = $record['attendance_date'];
                $hours      = (float) ($record['work_hours'] ?? self::DEFAULT_WORK_HOURS);
                $dayWage    = round($calc['daily_base'] / self::DEFAULT_WORK_HOURS * $hours, 2);

                // 检查当天是否已写入（防重复）
                $existsDetail = $detail->where('user_id', $userId)
                    ->where('date', $recordDate)
                    ->where('relation_code', 'ATTENDANCE')
                    ->find();

                if ($existsDetail) {
                    continue; // 已存在则跳过
                }

                $detail->insert([
                    'user_id'       => $userId,
                    'username'      => $username,
                    'date'          => $recordDate,
                    'wage'          => $dayWage,
                    'report_num'    => 0,
                    'relation_code' => 'ATTENDANCE',
                    'create_time'   => date('Y-m-d H:i:s'),
                    'create_uid'    => user_id(),
                ]);
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('工资生成失败：' . $e->getMessage());
        }

        // 重新查一遍返回
        $wageModel = new UserWageModel();
        $record = $wageModel->where('user_id', $userId)
            ->where('year', $year)
            ->where('month', $month)
            ->find();

        return $record ? $record->toArray() : $calc;
    }

    /**
     * 批量生成所有有固定工资配置的主任/主管月工资
     * @param string $year
     * @param string $month
     * @return array
     */
    public static function generateAllMonthlyWage(string $year, string $month): array
    {
        // 找所有有签到记录且有固定工资配置的用户
        $users = Db::name('process_wage')
            ->alias('pw')
            ->join('supervisor_attendance sa', 'pw.user_id = sa.user_id')
            ->where('pw.status', 1)
            ->where('pw.is_delete', 0)
            ->where('sa.attendance_date', '>=', "{$year}-{$month}-01")
            ->where('sa.attendance_date', '<=', date('Y-m-t', strtotime("{$year}-{$month}-01")))
            ->distinct(true)
            ->column('pw.user_id');

        $results = [];
        foreach ($users as $uid) {
            try {
                $results[] = self::generateMonthlyWage($uid, $year, $month);
            } catch (\Exception $e) {
                $results[] = ['user_id' => $uid, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * 获取主任工资详情（含考勤汇总+工资明细）
     * @param int $userId
     * @param string $year
     * @param string $month
     * @return array
     */
    public static function getWageDetail(int $userId, string $year, string $month): array
    {
        $calc = self::calculateMonthlyWage($userId, $year, $month);

        // 查工资明细
        $wageRows = UserWageDetailModel::where('user_id', $userId)
            ->where('date', 'like', "{$year}-{$month}%")
            ->where('relation_code', 'ATTENDANCE')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        return [
            'summary' => $calc,
            'details' => $wageRows,
        ];
    }
}
