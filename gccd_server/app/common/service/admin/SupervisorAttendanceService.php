<?php

namespace app\common\service\admin;

use think\facade\Db;
use app\common\model\admin\SupervisorAttendanceModel;
use app\common\service\admin\SupervisorWageService;

class SupervisorAttendanceService
{
    /**
     * 确保数据表存在（自动建表）
     */
    public static function ensureTable()
    {
        DatabaseInitService::ensure();
    }

    /**
     * 获取指定日期签到状态
     */
    public static function getStatusByDate($userId, $date)
    {
        self::ensureTable();

        $record = SupervisorAttendanceModel::where('user_id', $userId)
            ->where('attendance_date', $date)
            ->find();

        if (!$record) {
            return [
                'signed_in'  => false,
                'signed_out' => false,
                'record'     => null,
            ];
        }

        return [
            'signed_in'  => true,
            'signed_out' => $record['status'] == 1,
            'record'     => $record,
        ];
    }

    /**
     * 签到
     * @param int $userId
     * @param float $workHours 今日计划工时（默认12小时）
     * @param string $remark 备注
     * @return array
     */
    public static function signIn($userId, $workHours = 12.0, $remark = '')
    {
        self::ensureTable();

        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        // 检查今天是否已签到
        $exists = SupervisorAttendanceModel::where('user_id', $userId)
            ->where('attendance_date', $today)
            ->find();

        if ($exists) {
            exception('今天已签到，请勿重复操作');
        }

        $data = [
            'user_id'         => $userId,
            'attendance_date' => $today,
            'check_in_time'   => $now,
            'work_hours'      => $workHours,
            'status'          => 1,
            'remark'          => $remark,
            'create_time'     => $now,
            'update_time'     => $now,
        ];

        $model = new SupervisorAttendanceModel();
        $model->save($data);

        // 签到时实时写入当日工资
        SupervisorWageService::writeDailyWage($userId, $today, $workHours);

        return self::getTodayStatus($userId);
    }

    /**
     * 签退
     * @param int $userId
     * @param float|null $workHours 实际工作小时数（可选，不传则用签到时的值）
     * @param string $remark 备注
     * @return array
     */
    public static function signOut($userId, $workHours = null, $remark = '')
    {
        self::ensureTable();

        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        $record = SupervisorAttendanceModel::where('user_id', $userId)
            ->where('attendance_date', $today)
            ->where('status', 0)
            ->find();

        if (!$record) {
            exception('今天尚未签到或已签退');
        }

        $update = [
            'check_out_time' => $now,
            'status'         => 1,
            'update_time'    => $now,
        ];

        if ($workHours !== null) {
            $update['work_hours'] = $workHours;
        }

        if ($remark) {
            $update['remark'] = $remark;
        }

        SupervisorAttendanceModel::where('id', $record['id'])->update($update);

        // 签退时实时写入当日工资
        $actualHours = $workHours !== null ? $workHours : (float) $record['work_hours'];
        SupervisorWageService::writeDailyWage($userId, $today, $actualHours);

        return self::getTodayStatus($userId);
    }

    /**
     * 获取今日签到状态
     * @param int $userId
     * @return array
     */
    public static function getTodayStatus($userId)
    {
        self::ensureTable();

        $today = date('Y-m-d');

        $record = SupervisorAttendanceModel::where('user_id', $userId)
            ->where('attendance_date', $today)
            ->find();

        if (!$record) {
            return [
                'signed_in'  => false,
                'signed_out' => false,
                'record'     => null,
            ];
        }

        return [
            'signed_in'  => true,
            'signed_out' => $record['status'] == 1,
            'record'     => $record,
        ];
    }

    /**
     * 获取签到历史
     * @param int $userId
     * @param int $page
     * @param int $limit
     * @return array
     */
    public static function getHistory($userId, $page = 1, $limit = 20)
    {
        self::ensureTable();

        $query = SupervisorAttendanceModel::where('user_id', $userId)
            ->order('attendance_date', 'desc');

        $total = $query->count();
        $list  = $query->page($page, $limit)->select();

        return [
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'pages'      => ceil($total / $limit),
        ];
    }

    /**
     * 获取指定月份的签到汇总
     * @param int $userId
     * @param string $month Y-m格式
     * @return array
     */
    public static function getMonthSummary($userId, $month = '')
    {
        self::ensureTable();

        if (empty($month)) {
            $month = date('Y-m');
        }

        $startDate = $month . '-01';
        $endDate   = date('Y-m-t', strtotime($startDate));

        $records = SupervisorAttendanceModel::where('user_id', $userId)
            ->where('attendance_date', '>=', $startDate)
            ->where('attendance_date', '<=', $endDate)
            ->select();

        $totalDays    = 0;
        $totalHours   = 0;
        $signedInDays = 0;
        $signedOutDays = 0;

        foreach ($records as $r) {
            $totalDays++;
            $totalHours += floatval($r['work_hours']);
            if ($r['status'] == 1) {
                $signedOutDays++;
            }
        }

        // 当月天数
        $monthDays = date('t', strtotime($startDate));

        return [
            'month'          => $month,
            'month_days'     => intval($monthDays),
            'signed_days'    => $totalDays,
            'signed_out_days' => $signedOutDays,
            'total_hours'    => round($totalHours, 1),
            'records'        => $records,
        ];
    }
}
