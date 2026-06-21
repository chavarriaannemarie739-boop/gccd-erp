<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\service\admin\DailyWorkService;
use think\facade\Db;

/**
 * 日工管理
 */
class DailyWork extends Backend
{
    protected $model = null;

    /**
     * 初始化
     */
    protected function _initialize()
    {
        parent::_initialize();
        // 权限检查
        $this->auth->check('daily_work');
    }

    /**
     * 日工报工
     */
    public function report()
    {
        $param = $this->request->param();
        $result = DailyWorkService::report($param);
        $this->success('报工成功', $result);
    }

    /**
     * 修改日工报工
     */
    public function edit()
    {
        $id = (int) $this->request->param('id', 0);
        $param = $this->request->param();

        if ($id <= 0) {
            $this->error('参数错误');
        }

        $result = DailyWorkService::update($id, $param);
        $this->success('修改成功', $result);
    }

    /**
     * 删除日工报工
     */
    public function dele()
    {
        $id = (int) $this->request->param('id', 0);

        if ($id <= 0) {
            $this->error('参数错误');
        }

        DailyWorkService::delete($id);
        $this->success('删除成功');
    }

    /**
     * 日工列表
     */
    public function list()
    {
        $page = (int) $this->request->param('page', 1);
        $limit = (int) $this->request->param('limit', 10);
        $where = $this->request->param('where', 'json');

        $result = DailyWorkService::list($where, $page, $limit);
        $this->success('获取成功', $result);
    }

    /**
     * 日工统计
     */
    public function stat()
    {
        $where = $this->request->param('where', 'json');
        $result = DailyWorkService::stat($where);
        $this->success('获取成功', $result);
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

        $this->success('获取成功', [
            'records' => $records['list'],
            'total_daily_wage' => round($totalDailyWage, 2),
            'total_allowance' => round($totalAllowance, 2),
            'total_wage' => round($totalDailyWage + $totalAllowance, 2),
        ]);
    }

    /**
     * 检查当前用户是否为班长
     */
    public function checkLeader()
    {
        $userId = user_id();
        $isTeamLeader = \app\common\service\admin\PublicService::is_class($userId);
        $this->success('获取成功', ['isTeamLeader' => $isTeamLeader]);
    }
}
