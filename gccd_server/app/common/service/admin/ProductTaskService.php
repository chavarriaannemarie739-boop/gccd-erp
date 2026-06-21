<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\config\ProductionConfig;
use app\common\model\admin\InStockModel;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\RoutingDetailModel;
use app\common\model\admin\ProductTaskModel;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class ProductTaskService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'routing_code' => '',
        'routing_name' => '', //
        'content' => '',
        'status' => ''
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'material_id' => '',
        'task_code' => '', //
        'processes_code' => '',
        'relation_code' => '',
        'routing_code' => '',
        'create_start_date' => '',
        'create_end_date' => '',
    ];

    /**
     * 列表行：补全 routing、按末道工序汇总 report_num（与分页列表一致）
     */
    public static function applyTaskListReportAggregates(array &$list): void
    {
        if ($list === []) {
            return;
        }
        $processModel = new ProcessesModel();
        $finallyProcesses = $processModel->where('is_finnally', 1)->column('processes_code');
        foreach ($list as $k => &$v) {
            if (empty($v['routing_code']) && !empty($v['relation_code'])) {
                $plan = Db::name('production')->where('production_plan_code', $v['relation_code'])->find();
                if ($plan && !empty($plan['routing_code'])) {
                    $v['routing_code'] = $plan['routing_code'];
                    $routing = Db::name('routing')->where('routing_code', $plan['routing_code'])->find();
                    $v['routing_name'] = $routing ? $routing['routing_name'] : '';
                    Db::name('product_task')->where('id', $v['id'])->update(['routing_code' => $plan['routing_code']]);
                }
            }
            $v['report_num'] = 0;
            if (!empty($v['report'])) {
                $data = json_decode($v['report'], true);
                if (is_array($data)) {
                    foreach ($finallyProcesses as $processCode) {
                        if (isset($data[$processCode]['report_num'])) {
                            $v['report_num'] += (int) $data[$processCode]['report_num'];
                        }
                    }
                }
            }
        }
        unset($v);
    }

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
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {


        $model = new ProductTaskModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            $list = $model->with(['getProcessName', 'getGyName', 'getPlanName', 'getMaterial', 'getYMaterial'])
                ->where($where)
                ->order($order)
                ->select()
                ->toArray();
            self::applyTaskListReportAggregates($list);
            $c = count($list);
            return ['list' => $list, 'count' => $c, 'page' => 1, 'pages' => 1, 'limit' => $c];
        }

        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getProcessName', 'getGyName', 'getPlanName', 'getMaterial', 'getYMaterial'])
            ->where($where)
            ->page($page)
            ->limit($limit)
            ->order($order)
            ->select()
            ->toArray();
        self::applyTaskListReportAggregates($list);
        /*为列表添加采购前的数据*/
        return compact('count', 'pages', 'page', 'limit', 'list');
    }
    /**
     * 任务详情
     *
     * @param int  $id   id
     * @param bool $exce 不存在是否抛出异常
     *
     * @return array|Exception
     */
    public static function info($id, $exce = true, $processes_code = null)
    {
        $info = null;
        if (empty($info)) {
            $model = new ProductTaskModel();
            $info = $model->with(['getProcessName','getGyName','getPlanName','getMaterial','getYMaterial'])->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的任务信息不存在');
                }
                return [];
            }
            /*如果传递的工序为空--那么将获取默认工序的数据*/
            if (empty($processes_code)) {
                $processes_code = $info['process_code'];
            }
            if (!empty($info['report'])) {
                /*将数据转换成数组*/
                $data = json_decode($info['report'], true);
                // 初始化累加值
                $info['report_num'] = 0;
                if ($data != null) {
                    // 方式1：累加当前工序的所有 report_num（如果数据结构是多条记录）
                    foreach ($data as $key => $value) {
                        if ($key == $processes_code && isset($value['report_num'])) {
                            $info['report_num'] += (float)$value['report_num'];
                        }
                    }
                } else {
                    $info['report_num'] = 0;
                }
            }

            /* 兜底防御：详情页也做一次工艺空值防御 */
            if (empty($info['routing_code']) && !empty($info['relation_code'])) {
                $plan = \think\facade\Db::name('production')->where('production_plan_code', $info['relation_code'])->find();
                if ($plan && !empty($plan['routing_code'])) {
                    $info['routing_code'] = $plan['routing_code'];
                    $routing = \think\facade\Db::name('routing')->where('routing_code', $plan['routing_code'])->find();
                    $info['routing_name'] = $routing ? $routing['routing_name'] : '';
                    \think\facade\Db::name('product_task')->where('id', $info['id'])->update(['routing_code' => $plan['routing_code']]);
                }
            }

            /*添加当前任务的物料内容*/
            $content = $model->with(['getProcessName','getGyName','getPlanName','getMaterial','getYMaterial'])->where('task_code', $info['task_code'])->select()->toArray();
            $info['content'] = $content;
            $info = $info->toArray();
        }
        /*生成调试单号*/
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
        $startedHere = !Db::connect()->getPdo()->inTransaction();
        if ($startedHere) {
            Db::startTrans();
        }
        try {
            /*创建生产任务*/
            $model = new ProductTaskModel();
            /*生成工艺编码*/
            if (empty($param['task_code'])) {
                $param['task_code'] = PublicService::getCode(4)['code'];
            }
            foreach ($param['content'] as $k => &$v) {
                $v['task_code'] = $param['task_code'];
                $v['relation_code'] = $param['relation_code'];
                $v['routing_code'] = $param['routing_code'];
                $v['create_time'] = datetime();
                $v['create_uid'] = user_id();
                $taskId = $model->insertGetId($v);
                ProductionPunchDeviceService::copyToTask(
                    (string) $param['relation_code'],
                    (string) $param['task_code'],
                    (int) $taskId
                );
            }
            if ($startedHere) {
                Db::commit();
            }
        } catch (\Exception $e) {
            if ($startedHere) {
                Db::rollback();
            }
            exception('创建失败:' . $e->getMessage());
        }

        return $param;
    }

    /**
     * 选择工序顺序
     * @param $param
     * @return mixed
     */
    public static function addProcess($param)
    {
        Db::startTrans();
        try {
            $content = $param['content'] ?? [];
            foreach ($content as $idx => &$row) {
                if (!isset($row['sort']) || $row['sort'] === '' || $row['sort'] === null) {
                    $row['sort'] = $idx + 1;
                } else {
                    $row['sort'] = (int) $row['sort'];
                }
            }
            unset($row);
            $sorts = array_column($content, 'sort');
            if (count($sorts) !== count(array_unique($sorts))) {
                \exception('同一个工艺不允许出现相同顺序的工序');
            }
            $param['content'] = $content;

            foreach ($param['content'] as $k => &$v) {
                $v['routing_code'] = $param['routing_code'];
                $v['create_time'] = datetime();
                $v['create_uid'] = user_id();
                (new RoutingDetailModel())->insert($v);
            }
            unset($v);

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
        $model = new ProductTaskModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        if ($model->where($pk, 'in', $ids)->count() < 1) {
            exception('生产任务记录不存在');
        }
        $res = $model->where($pk, 'in', $ids)->update($param);
        if ($res === false) {
            exception('更新生产任务失败');
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

        $model = new ProductTaskModel();
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

    /**
     * 返回「该生产计划下全部工单已结束」的 relation_code 列表。
     * 规则：单条工单已结束 = status=2（手动点完成或自动完成）。
     * 自动完成条件：每个工序报工量 >= 计划量×97% 且 <= 计划量。
     *
     * @return string[]
     */
    public static function relationCodesAllTasksFinishedMiniStyle(): array
    {
        $model = new ProductTaskModel();
        $rows = $model->where('is_delete', 0)
            ->field('relation_code,status')
            ->select()
            ->toArray();
        $byRel = [];
        foreach ($rows as $v) {
            $code = $v['relation_code'] ?? '';
            if ($code === '') {
                continue;
            }
            $byRel[$code][] = $v;
        }
        $done = [];
        foreach ($byRel as $rel => $tasks) {
            if ($tasks === []) {
                continue;
            }
            $allFinished = true;
            foreach ($tasks as $t) {
                $st = (int) ($t['status'] ?? 0);
                if ($st !== 2) {
                    $allFinished = false;
                    break;
                }
            }
            if ($allFinished) {
                $done[] = $rel;
            }
        }
        return $done;
    }

    /**
     * 小程序：按工序（及穿孔设备）列出进行中工单
     *
     * @param string $processesCode 目标工序编码
     * @param string $deviceCode    穿孔时必传
     */
    public static function listForMini(
        string $processesCode,
        string $deviceCode = '',
        int $page = 1,
        int $limit = 10,
        string $keyword = ''
    ): array {
        $processesCode = trim($processesCode);
        if ($processesCode === '') {
            exception('请指定工序');
        }
        $processRow = Db::name('processes')->where('processes_code', $processesCode)->where('is_delete', 0)->find();
        $processName = (string) ($processRow['processes_name'] ?? '');

        $model = new ProductTaskModel();
        $query = $model->with(['getProcessName', 'getGyName', 'getPlanName', 'getMaterial', 'getYMaterial'])
            ->where('is_delete', 0);

        if ($processName === ProductionConfig::PROCESS_NAME_PUNCH) {
            if ($deviceCode === '') {
                exception('请先选择穿孔设备');
            }
            $taskCodes = Db::name('product_task_punch_device')
                ->where('device_code', $deviceCode)
                ->column('task_code');
            if ($taskCodes === []) {
                return ['list' => [], 'count' => 0, 'page' => $page, 'pages' => 0, 'limit' => $limit];
            }
            $query->whereIn('task_code', array_unique($taskCodes));
        }

        $all = $query->order(['create_time' => 'desc'])->select()->toArray();
        self::applyTaskListReportAggregates($all);

        $keyword = trim($keyword);
        $filtered = [];
        foreach ($all as $row) {
            if (self::isTaskFinishedForMini($row, $processesCode)) {
                continue;
            }
            if (!self::miniTaskMatchesKeyword($row, $keyword)) {
                continue;
            }
            self::enrichMiniTaskRow($row, $processesCode);
            $filtered[] = $row;
        }

        $count = count($filtered);
        $pages = $limit > 0 ? (int) ceil($count / $limit) : 1;
        $offset = ($page - 1) * $limit;
        $list = ($limit > 0 && $page > 0) ? array_slice($filtered, $offset, $limit) : $filtered;

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 小程序工单列表：关键字匹配（圆钢/成品规格型号、计划名）
     *
     * @param array<string,mixed> $row
     */
    private static function miniTaskMatchesKeyword(array $row, string $keyword): bool
    {
        if ($keyword === '') {
            return true;
        }
        $kw = mb_strtolower($keyword);
        $fields = [
            $row['production_plan_name'] ?? '',
            $row['y_material_spec'] ?? '',
            $row['y_material_model'] ?? '',
            $row['c_material_spec'] ?? '',
            $row['c_material_model'] ?? '',
        ];
        foreach ($fields as $field) {
            $text = trim((string) $field);
            if ($text !== '' && mb_strpos(mb_strtolower($text), $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 小程序工单列表：补全当前工序报工与剩余量
     *
     * @param array<string,mixed> $row
     */
    private static function enrichMiniTaskRow(array &$row, string $processesCode): void
    {
        $taskNum = (float) ($row['task_num'] ?? 0);
        $reportObj = $row['report'] ?? '';
        if (is_string($reportObj)) {
            $reportObj = json_decode($reportObj, true);
        }
        $processReport = 0.0;
        if (is_array($reportObj) && isset($reportObj[$processesCode])) {
            $cell = $reportObj[$processesCode];
            $processReport = is_array($cell) ? (float) ($cell['report_num'] ?? 0) : 0.0;
        }
        $row['process_report_num'] = round($processReport, 3);
        $row['remain_total'] = round(max(0.0, $taskNum - $processReport), 3);
    }

    /**
     * 当前工序是否已报满
     */
    public static function isTaskFinishedForMini(array $item, string $processCode = ''): bool
    {
        $taskNum = (float) ($item['task_num'] ?? 0);
        if ($taskNum <= 0) {
            return false;
        }
        $code = $processCode !== '' ? $processCode : (string) ($item['process_code'] ?? '');
        $reportObj = $item['report'] ?? '';
        if (is_string($reportObj)) {
            $reportObj = json_decode($reportObj, true);
        }
        if (!is_array($reportObj)) {
            return false;
        }
        $cell = $reportObj[$code] ?? null;
        $reportNum = is_array($cell) ? (float) ($cell['report_num'] ?? 0) : 0;

        return $reportNum >= $taskNum;
    }
}
