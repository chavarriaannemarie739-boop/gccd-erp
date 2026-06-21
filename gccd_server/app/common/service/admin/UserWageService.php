<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\InStockModel;
use app\common\model\admin\UserWageDetailModel;
use app\common\model\admin\UserWageModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class UserWageService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'month' => '',
        'year' => '',
        'wage' => '', //
        'user_id' => ''
    ];
    /*搜索的字段内容获取*/
    public static $search_field = [
        'material_spec' => '',
        'wage' => '', //
        'username' => '', // 用户名
        'create_start_date' => '',
        'create_end_date' => '',
        'year' => '',
        'month' => '',
        'period_start' => '',
        'period_end' => '',
        'user_id' => '',
        'check_name' => '',
        'check_code' => '',
    ];
    public static $detail_field = [
        'username' => '', //员工姓名
        'relation_code' => '', //关联单号
        'create_start_time' => '',
        'create_end_time' => '',
    ];
    /**
     * 列表
     *
     * @param array  $where 条件
     * @param int    $page  页数
     * @param int    $limit 数量
     * @param array  $order 排序
     * @param string $field 字段
     *
     * @return array
     */
    /**
     * @param array|null $periodRange 月份区间 ['period_start'=>'2025-11','period_end'=>'2026-02']，与单独 year/month 互斥由控制器保证
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [], $periodRange = null)
    {
        $model = new UserWageModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }

        $applyPeriod = function ($q) use ($periodRange) {
            if (!empty($periodRange['period_start']) && !empty($periodRange['period_end'])) {
                $q->whereRaw(
                    "CONCAT(`year`, '-', LPAD(`month`, 2, '0')) BETWEEN ? AND ?",
                    [$periodRange['period_start'], $periodRange['period_end']]
                );
            }
            return $q;
        };

        $baseQuery = function () use ($model, $where, $applyPeriod) {
            return $applyPeriod($model->newQuery()->where($where));
        };

        $summary = [];
        $sumRow = $baseQuery()->fieldRaw('COALESCE(SUM(`wage`),0) as total_wage, COUNT(*) as record_count, COUNT(DISTINCT `user_id`) as user_count')->find();
        $summary = [
            'total_wage_sum' => 0.0,
            'record_count' => 0,
            'distinct_user_count' => 0,
        ];
        if ($sumRow) {
            $arr = $sumRow->toArray();
            $summary = [
                'total_wage_sum' => (float) ($arr['total_wage'] ?? 0),
                'record_count' => (int) ($arr['record_count'] ?? 0),
                'distinct_user_count' => (int) ($arr['user_count'] ?? 0),
            ];
        }

        // group 不能传含逗号的纯字符串：Builder 会 explode(',')，会把 LPAD(...,2,...) 拆坏
        $groupYmExpr = "CONCAT(`year`, '-', LPAD(`month`, 2, '0'))";
        $trendRows = $baseQuery()
            ->fieldRaw("{$groupYmExpr} as ym, SUM(`wage`) as total")
            ->group([$groupYmExpr])
            ->orderRaw('ym ASC')
            ->select()
            ->toArray();
        $summary['monthly_trend'] = array_map(static function ($r) {
            return [
                'ym' => $r['ym'] ?? '',
                'total' => isset($r['total']) ? (float) $r['total'] : 0.0,
            ];
        }, $trendRows);

        if ($page == 0 || $limit == 0) {
            $list = $applyPeriod($model->newQuery()->with(['getUserName'])->where($where))
                ->order($order)->select()->toArray();
            return array_merge(compact('list'), ['summary' => $summary]);
        }
        $count = $baseQuery()->count();
        $pages = ceil($count / $limit);
        $list = $applyPeriod($model->newQuery()->with(['getUserName'])->where($where))
            ->page($page)->limit($limit)->order($order)->select()->toArray();
        return array_merge(compact('count', 'pages', 'page', 'limit', 'list'), ['summary' => $summary]);
    }
    /**
     * 信息
     *
     * @param int  $id   id
     * @param bool $exce 不存在是否抛出异常
     *
     * @return array|Exception
     */
    public static function info($id, $exce = true)
    {
        $info = null;

        if (empty($info)) {
            $model = new UserWageModel();
            $detail = new UserWageDetailModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的员工工资信息不存在');
                }
                return [];
            }
            /*查找员工当月详细工资记录*/
            $content = $detail->where('date', 'like', $info['year'] . '-' . $info['month'] . '%')->where('user_id', $info['user_id'])->select()->toArray();
            $info['details'] = $content;
            $info = $info->toArray();
        }

        // 查询该用户当月考勤汇总
        try {
            $userId   = $info['user_id'] ?? 0;
            $year     = $info['year'] ?? date('Y');
            $month    = $info['month'] ?? date('m');
            $monthStart = "{$year}-{$month}-01";
            $monthEnd   = date('Y-m-t', strtotime($monthStart));

            $attendanceModel = new \app\common\model\admin\SupervisorAttendanceModel();
            $attendanceRecords = $attendanceModel->where('user_id', $userId)
                ->where('attendance_date', '>=', $monthStart)
                ->where('attendance_date', '<=', $monthEnd)
                ->select()
                ->toArray();

            $attendanceDays  = count($attendanceRecords);
            $totalHours      = 0;
            $signedOutDays   = 0;

            foreach ($attendanceRecords as $ar) {
                $hours = (float) ($ar['work_hours'] ?? 0);
                $totalHours += $hours;
                if ($ar['status'] == 1) {
                    $signedOutDays++;
                }
            }

            $info['attendance'] = [
                'has_attendance'  => $attendanceDays > 0,
                'attendance_days' => $attendanceDays,
                'signed_out_days' => $signedOutDays,
                'total_hours'     => round($totalHours, 1),
                'records'         => $attendanceRecords,
            ];
        } catch (\Exception $e) {
            // 表可能不存在，不阻断
            $info['attendance'] = [
                'has_attendance'  => false,
                'attendance_days' => 0,
                'signed_out_days' => 0,
                'total_hours'     => 0,
                'records'         => [],
            ];
        }

        // 日工工资汇总
        try {
            $dailyWorkWage = 0;
            $dailyWorkAllowance = 0;
            foreach ($info['details'] ?? [] as $d) {
                $wt = $d['wage_type'] ?? '';
                if ($wt === 'daily_work') {
                    $dailyWorkWage += (float)($d['wage'] ?? 0);
                } elseif ($wt === 'team_leader_allowance') {
                    $dailyWorkAllowance += (float)($d['wage'] ?? 0);
                }
            }
            $info['daily_work_summary'] = [
                'daily_work_wage' => round($dailyWorkWage, 2),
                'team_leader_allowance' => round($dailyWorkAllowance, 2),
                'total' => round($dailyWorkWage + $dailyWorkAllowance, 2),
            ];
        } catch (\Exception $e) {
            $info['daily_work_summary'] = [
                'daily_work_wage' => 0,
                'team_leader_allowance' => 0,
                'total' => 0,
            ];
        }

        return $info;
    }
    /**
     * 添加
     *
     * @param array $param 信息
     *
     * @return array|Exception
     */
    public static function add($param)
    {
        Db::startTrans();
        try {
            $model = new UserWageModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $data = $model->where('material_spec', $param['material_spec'])->find();
            if (!empty($data)) {
                \exception('不允许存在相同名字的物料规格');
            }
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $id = $model->insertGetId($param);
            $param[$pk] = $id;
            // 提交事务
            Db::commit();
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }

     /**
     * 修改
     *
     * @param int|array $ids   id
     * @param array     $param 信息
     *
     * @return array|Exception
     */
    public static function edit($ids, $param = [])
    {
        $model = new UserWageModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        $res = $model->where($pk, 'in', $ids)->update($param);
        if (empty($res)) {
            exception();
        }
        $param['ids'] = $ids;


        return $param;
    }

    /**
     * 删除
     *
     * @param array $ids  id
     * @param bool  $real 是否真实删除
     *
     * @return array|Exception
     */
    public static function dele($ids, $real = true)
    {
        $model = new UserWageModel();
        $pk = $model->getPk();

        if ($real) {
            $res = $model->where($pk, 'in', $ids)->delete();
        } else {
            $update = delete_update();
            $res = $model->where($pk, 'in', $ids)->update($update);
        }

        if (empty($res)) {
            exception();
        }
        $update['ids'] = $ids;
        return $update;
    }
    public static function getUserDayWage($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new UserWageDetailModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        /*为列表添加采购前的数据*/
        return compact('count', 'pages', 'page', 'limit', 'list');
    }
}
