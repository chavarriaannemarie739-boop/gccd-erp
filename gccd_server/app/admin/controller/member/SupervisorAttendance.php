<?php

namespace app\admin\controller\member;

use app\common\controller\BaseController;
use app\common\service\admin\SupervisorAttendanceService;

/**
 * 主任签到管理（小程序端）
 */
class SupervisorAttendance extends BaseController
{
    /**
     * 获取今日签到状态
     */
    public function today()
    {
        $userId = user_id(true);
        $data   = SupervisorAttendanceService::getTodayStatus($userId);
        return success($data);
    }

    /**
     * 签到
     */
    public function signIn()
    {
        $userId    = user_id(true);
        $param     = $this->params([
            'work_hours/f' => 12.0,
            'remark/s'     => '',
        ]);
        $data = SupervisorAttendanceService::signIn($userId, $param['work_hours'], $param['remark']);
        return success($data, '签到成功');
    }

    /**
     * 签退
     */
    public function signOut()
    {
        $userId    = user_id(true);
        $param     = $this->params([
            'work_hours/f' => null,
            'remark/s'     => '',
        ]);
        $data = SupervisorAttendanceService::signOut($userId, $param['work_hours'], $param['remark']);
        return success($data, '签退成功');
    }

    /**
     * 签到历史
     */
    public function history()
    {
        $userId = user_id(true);
        $data   = SupervisorAttendanceService::getHistory($userId, $this->page(), $this->limit());
        return success($data);
    }

    /**
     * 月度汇总
     */
    public function summary()
    {
        $userId = user_id(true);
        $param  = $this->params(['month/s' => '']);
        $month  = $param['month'] ?: date('Y-m');
        $data   = SupervisorAttendanceService::getMonthSummary($userId, $month);
        return success($data);
    }

    /**
     * 按日期查询签到状态（用于查看历史某天）
     */
    public function dateStatus()
    {
        $userId = user_id(true);
        $param  = $this->params(['date/s' => date('Y-m-d')]);
        $data   = SupervisorAttendanceService::getStatusByDate($userId, $param['date']);
        return success($data);
    }
}
