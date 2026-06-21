<?php

namespace app\admin\controller\member;

use app\common\controller\BaseController;
use app\common\service\admin\SupervisorWageService;

/**
 * 主任工资管理（PC端）
 */
class SupervisorWage extends BaseController
{
    /**
     * 预览某主任某月工资（不写入）
     */
    public function preview()
    {
        $param = $this->params([
            'user_id/d' => 0,
            'year/s'    => date('Y'),
            'month/s'   => date('m'),
        ]);
        if (!$param['user_id']) {
            exception('请选择员工');
        }
        $data = SupervisorWageService::calculateMonthlyWage(
            $param['user_id'],
            $param['year'],
            $param['month']
        );
        return success($data);
    }

    /**
     * 生成某主任某月工资（写入 user_wage + user_wage_details）
     */
    public function generate()
    {
        $param = $this->params([
            'user_id/d' => 0,
            'year/s'    => date('Y'),
            'month/s'   => date('m'),
        ]);
        if (!$param['user_id']) {
            exception('请选择员工');
        }
        $data = SupervisorWageService::generateMonthlyWage(
            $param['user_id'],
            $param['year'],
            $param['month']
        );
        return success($data, '工资生成成功');
    }

    /**
     * 批量生成所有主任月工资
     */
    public function generateAll()
    {
        $param = $this->params([
            'year/s'  => date('Y'),
            'month/s' => date('m'),
        ]);
        $data = SupervisorWageService::generateAllMonthlyWage(
            $param['year'],
            $param['month']
        );
        return success($data, '批量生成完成');
    }

    /**
     * 主任工资详情（含考勤汇总）
     */
    public function detail()
    {
        $param = $this->params([
            'user_id/d' => 0,
            'year/s'    => date('Y'),
            'month/s'   => date('m'),
        ]);
        if (!$param['user_id']) {
            exception('请选择员工');
        }
        $data = SupervisorWageService::getWageDetail(
            $param['user_id'],
            $param['year'],
            $param['month']
        );
        return success($data);
    }
}
