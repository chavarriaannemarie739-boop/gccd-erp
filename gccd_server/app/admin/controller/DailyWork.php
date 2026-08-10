<?php

namespace app\admin\controller;

use app\common\controller\BaseController;
use app\common\service\admin\DailyWorkService;
use app\common\service\admin\PublicService;

/**
 * 日工管理
 */
class DailyWork extends BaseController
{
    /**
     * 日工报工
     */
    public function report()
    {
        $param = $this->request->param();
        $result = DailyWorkService::report($param);

        return success($result, '报工成功');
    }

    /**
     * 修改日工报工
     */
    public function edit()
    {
        $id = (int) $this->request->param('id', 0);
        $param = $this->request->param();

        if ($id <= 0) {
            return error('参数错误');
        }

        $result = DailyWorkService::update($id, $param);

        return success($result, '修改成功');
    }

    /**
     * 删除日工报工
     */
    public function dele()
    {
        $id = (int) $this->request->param('id', 0);

        if ($id <= 0) {
            return error('参数错误');
        }

        DailyWorkService::delete($id);

        return success([], '删除成功');
    }

    /**
     * 日工列表
     */
    public function list()
    {
        $page = (int) $this->request->param('page', 1);
        $limit = (int) $this->request->param('limit', 10);
        $where = $this->request->param('where/a', []);

        $result = DailyWorkService::list($where, $page, $limit);

        return success($result, '获取成功');
    }

    /**
     * 日工统计
     */
    public function stat()
    {
        $where = $this->request->param('where/a', []);
        $result = DailyWorkService::stat($where);

        return success($result, '获取成功');
    }

    /**
     * 按日期获取日工记录
     */
    public function getByDate()
    {
        $userId = (int) $this->request->param('user_id', 0);
        $date = $this->request->param('date', date('Y-m-d'));

        if ($userId <= 0) {
            $userId = user_id();
        }

        $records = DailyWorkService::list(['work_date' => $date], 1, 100);

        $totalDailyWage = 0;
        $totalAllowance = 0;

        foreach ($records['list'] as $r) {
            $totalDailyWage += (float) ($r['daily_wage'] ?? 0);
            $totalAllowance += (float) ($r['team_leader_allowance'] ?? 0);
        }

        return success([
            'records' => $records['list'],
            'total_daily_wage' => round($totalDailyWage, 2),
            'total_allowance' => round($totalAllowance, 2),
            'total_wage' => round($totalDailyWage + $totalAllowance, 2),
        ], '获取成功');
    }

    /**
     * 检查当前用户是否为班长
     */
    public function checkLeader()
    {
        $userId = user_id();
        $isTeamLeader = PublicService::is_class($userId);

        return success(['isTeamLeader' => $isTeamLeader], '获取成功');
    }
}
