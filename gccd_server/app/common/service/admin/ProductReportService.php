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
use app\common\model\admin\DeviceModel;
use app\common\model\admin\InStockModel;
use app\common\model\admin\MaterialModel;
use app\common\model\admin\PowerModel;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProcessWageModel;
use app\common\model\admin\ProductPowerModel;
use app\common\model\admin\ProductTaskModel;
use app\common\model\admin\RoutingDetailModel;
use app\common\model\admin\ProductReportModel;
use app\common\model\admin\UserWageDetailModel;
use app\common\model\admin\UserWageModel;
use app\common\model\admin\WageModel;
use app\common\model\system\UserModel;
use app\common\service\admin\DailySummaryService;
use app\common\service\utils\MaterialSearchHelper;
use app\common\service\wechat\Exception;
use think\console\output\Ask;
use think\facade\App;
use think\facade\Filesystem;
use think\facade\Db;
use think\Model;

/**
 *
 */
class ProductReportService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'routing_code' => '',
        'report_code' => '', //
        'relation_code' => '',
        'material_id' => '',
        'report_num' => '',
        'processes_code' => '',
        'power_num' => '',
        'gas_num' => '',
        'remark' => '',
        'defective_num' => [],  //不良品项,
        'yarn_count' => '',
        'report_date' => '',
        'class' => ''
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'id' => '',
        'report_code' => '',
        'material_spec' => '', //
        'is_product' => '',
        'routing_code' => '',
        'relation_code' => '',
        'processes_code' => '',
        'device_code' => '',
        'device_name' => '',           // 设备名称
        'processes_name' => '',        // 工序名称
        'class' => '',             // 班次（南/北）
        'plan_code' => '',             // 计划编码
        'production_plan_name' => '',  // 生产计划名称
        'create_name' => '',           // 创建人姓名
        'c_material_spec' => '',       // 成品物料规格
        'y_material_name' => '',       // 圆钢名称
        'y_material_manufacturer' => '', // 圆钢厂商
        'y_material_spec' => '',       // 原料物料规格
        'c_material_model' => '',      // 成品物料型号
        'y_material_model' => '',      // 原料物料型号
        'y_mateial_name' => '',        // 历史拼写读兼容
        'y_mateial_manufacturer' => '',
        'y_mateial_spec' => '',
        'y_mateial_model' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'report_date_start' => '',
        'report_date_end' => '',
        'create_uid' => ''
    ];

    /**
     * 能耗列表检索字段。能耗表不关联生产任务，不声明圆钢筛选参数。
     *
     * @var array<string,mixed>
     */
    public static $powerSearchField = [
        'routing_code' => '',
        'relation_code' => '',
        'class' => '',
        'device_code' => '',
        'device_name' => '',
        'processes_name' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'report_date_start' => '',
        'report_date_end' => '',
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
    public static function list(
        $where = [],
        $page = 1,
        $limit = 10,
        $order = [],
        $field = [],
        array $roundFilters = []
    )
    {
        $model = new ProductReportModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            $query = $model->alias('pr')->field('pr.*')->where($where);
            MaterialSearchHelper::applyProductReportRoundSteelFilters(
                $query,
                $roundFilters,
                'pr'
            );
            $list = $query->with(['getProcess','getTask','getPlan','getMaterial','getYMaterial'])
                ->order($order)
                ->select()
                ->toArray();
            return self::enrichReportListBatch($list);
        }

        // [AI-2026-06] 日工同批合并后再分页，避免每页条数不足
        $groupResult = self::buildMergedDisplayGroups(
            $where,
            $order,
            $roundFilters,
            $page,
            $limit
        );
        $count = $groupResult['count'];
        $pages = $limit > 0 ? (int) ceil($count / $limit) : 0;
        $pageGroups = $groupResult['groups'];

        if ($pageGroups === []) {
            return [
                'count' => $count,
                'pages' => $pages,
                'page' => $page,
                'limit' => $limit,
                'list' => [],
            ];
        }

        $fetchIds = [];
        foreach ($pageGroups as $group) {
            foreach ($group['ids'] as $id) {
                $fetchIds[] = $id;
            }
        }
        $fetchIds = array_values(array_unique($fetchIds));

        $list = $model->with(['getProcess','getTask','getPlan','getMaterial','getYMaterial'])
            ->whereIn('id', $fetchIds)
            ->order($order)
            ->select()
            ->toArray();

        $list = self::enrichReportListBatch($list);

        $rowById = [];
        foreach ($list as $row) {
            $rowById[(int) ($row['id'] ?? 0)] = $row;
        }

        $mergedList = [];
        foreach ($pageGroups as $group) {
            $groupRows = [];
            foreach ($group['ids'] as $id) {
                if (isset($rowById[$id])) {
                    $groupRows[] = $rowById[$id];
                }
            }
            if ($groupRows === []) {
                continue;
            }
            if ($group['is_daily_merge']) {
                $dailyMerged = self::mergeDailyWorkListRows($groupRows);
                if ($dailyMerged !== []) {
                    $mergedList[] = $dailyMerged[0];
                }
            } else {
                $mergedList[] = $groupRows[0];
            }
        }

        $list = $mergedList;

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    /**
     * 按列表展示顺序构建分组：日工同批合并为一组，其它每条一组
     *
     * @param array $where
     * @param array $order
     * @param array $roundFilters
     * @param int $page
     * @param int $limit
     * @return array{count:int,groups:array<int,array{ids:int[],is_daily_merge:bool}>}
     */
    private static function buildMergedDisplayGroups(
        array $where,
        array $order,
        array $roundFilters,
        int $page,
        int $limit
    ): array
    {
        $groups = [];
        $selectedDailyGroupIndex = [];
        $seenDailyKeys = [];
        $groupCount = 0;
        $pageStart = max(0, ($page - 1) * $limit);
        $pageEnd = $pageStart + $limit;
        $chunkSize = max(100, min(500, $limit * 10));
        $offset = 0;
        $chunkOrder = $order;
        if (!isset($chunkOrder['id']) && !isset($chunkOrder['pr.id'])) {
            $chunkOrder['pr.id'] = 'desc';
        }

        do {
            $query = (new ProductReportModel())
                ->alias('pr')
                ->where($where);
            MaterialSearchHelper::applyProductReportRoundSteelFilters(
                $query,
                $roundFilters,
                'pr'
            );
            $rows = $query
                ->order($chunkOrder)
                ->field('pr.id,pr.is_daily_work,pr.create_time,pr.create_uid')
                ->limit($offset, $chunkSize)
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (!empty($row['is_daily_work']) && (int) $row['is_daily_work'] === 1) {
                    $groupKey = ($row['create_time'] ?? '') . '_' . ($row['create_uid'] ?? '');
                    if (isset($seenDailyKeys[$groupKey])) {
                        if (isset($selectedDailyGroupIndex[$groupKey])) {
                            $groups[$selectedDailyGroupIndex[$groupKey]]['ids'][] = $id;
                        }
                        continue;
                    }
                    $seenDailyKeys[$groupKey] = true;
                    if ($groupCount >= $pageStart && $groupCount < $pageEnd) {
                        $selectedDailyGroupIndex[$groupKey] = count($groups);
                        $groups[] = ['ids' => [$id], 'is_daily_merge' => true];
                    }
                    $groupCount++;
                    continue;
                }
                if ($groupCount >= $pageStart && $groupCount < $pageEnd) {
                    $groups[] = ['ids' => [$id], 'is_daily_merge' => false];
                }
                $groupCount++;
            }
            $offset += count($rows);
        } while (count($rows) === $chunkSize);

        return ['count' => $groupCount, 'groups' => $groups];
    }

    /**
     * 报工列表批量 enrichment（工资、日工、物料等）
     *
     * @param array $list
     * @return array
     */
    private static function enrichReportListBatch(array $list): array
    {
        if ($list === []) {
            return [];
        }

        $usermodel = new UserModel();
        $allReportCodes = array_filter(array_column($list, 'report_code'));
        $allCreateUids = array_unique(array_filter(array_column($list, 'create_uid')));
        $uidNicknameMap = [];
        if (!empty($allCreateUids)) {
            $uidNicknameMap = $usermodel->whereIn('user_id', $allCreateUids)->column('nickname', 'user_id');
        }

        $wageByReportCode = [];
        if (!empty($allReportCodes)) {
            $wageRows = Db::name('user_wage_details')
                ->whereIn('relation_code', $allReportCodes)
                ->whereRaw('(is_delete IS NULL OR is_delete = 0)')
                ->field('id,user_id,username,wage,relation_code')
                ->select()
                ->toArray();
            foreach ($wageRows as $wr) {
                $rc = (string) $wr['relation_code'];
                $wageByReportCode[$rc][] = $wr;
            }
        }

        $deviceNameByCode = [];
        $codesNeedName = [];
        foreach ($list as $row) {
            $dataArr = json_decode((string) ($row['data'] ?? ''), true);
            if (!empty($dataArr['device_name'])) {
                continue;
            }
            $dc = trim((string) ($row['device_code'] ?? ''));
            if ($dc !== '') {
                $codesNeedName[] = $dc;
            }
        }
        if ($codesNeedName !== []) {
            $deviceNameByCode = Db::name('device')
                ->whereIn('device_code', array_values(array_unique($codesNeedName)))
                ->where('is_delete', 0)
                ->column('device_name', 'device_code');
        }

        $dailyWorkUserByReportId = [];
        $dailyReportIds = [];
        foreach ($list as $dailyRow) {
            if (!empty($dailyRow['is_daily_work']) && (int) $dailyRow['is_daily_work'] === 1) {
                $dailyReportIds[] = (int) $dailyRow['id'];
            }
        }
        if ($dailyReportIds !== []) {
            $dailyWorkRows = Db::name('daily_work')
                ->whereIn('report_id', $dailyReportIds)
                ->field('report_id,user_id')
                ->select()
                ->toArray();
            foreach ($dailyWorkRows as $dailyWorkRow) {
                $reportId = (int) ($dailyWorkRow['report_id'] ?? 0);
                $workerId = (int) ($dailyWorkRow['user_id'] ?? 0);
                if ($reportId > 0 && $workerId > 0) {
                    $dailyWorkUserByReportId[$reportId] = user_name($workerId);
                }
            }
        }

        foreach ($list as $k => &$v) {
            self::enrichReportListRow($v);
            $v['create_name'] = $uidNicknameMap[$v['create_uid']] ?? '';
            $arr = json_decode($v['data'], true);
            if (isset($arr['product_user']) && is_array($arr['product_user'])) {
                $v['product_user'] = PublicService::ArrayToString($arr['product_user']);
            } else {
                $v['product_user'] = '';
            }
            if (isset($arr['card_user']) && is_array($arr['card_user'])) {
                $v['card_user'] = PublicService::ArrayToString($arr['card_user']);
            } else {
                $v['card_user'] = '';
            }
            $wageTotal = 0.0;
            $wageParts = [];
            $rc = (string) $v['report_code'];
            if (!empty($wageByReportCode[$rc])) {
                foreach ($wageByReportCode[$rc] as $wd) {
                    $w = (float) ($wd['wage'] ?? 0);
                    $userName = !empty($wd['username']) ? $wd['username'] : 'uid:' . $wd['user_id'];
                    $wageParts[] = $userName . ':' . number_format($w, 2, '.', '');
                    $wageTotal += $w;
                }
            }
            $v['wage_detail'] = !empty($wageParts) ? implode(';', $wageParts) : '';
            $v['wage_total'] = round($wageTotal, 2);
            $v['wage'] = $v['wage_total'];
            if (!empty($arr['device_name'])) {
                $v['device_name'] = (string) $arr['device_name'];
            } else {
                $dc = trim((string) ($v['device_code'] ?? ''));
                $v['device_name'] = ($dc !== '' && !empty($deviceNameByCode[$dc]))
                    ? (string) $deviceNameByCode[$dc]
                    : '';
            }
            $name = '';
            $defectiveData = json_decode($v['defective']);
            if (is_array($defectiveData)) {
                foreach ($defectiveData as $k2 => $v2) {
                    $v2 = (array) $v2;
                    if (!isset($v2['name'])) {
                        continue;
                    }
                    $part = $v2['name'] . ':' . ($v2['num'] ?? '');
                    $name = ($name === '') ? $part : ($name . ',' . $part);
                }
            }
            $v['defective'] = $name;

            if (!empty($v['is_daily_work']) && $v['is_daily_work'] == 1) {
                $v['production_plan_name'] = '日工';
                $v['plan_code'] = '';
                $v['y_material_name'] = '';
                $v['y_material_manufacturer'] = '';
                $v['y_material_spec'] = '';
                $v['y_material_model'] = '';
                $v['c_material_spec'] = '';
                $v['c_material_model'] = '';
                $v['processes_name'] = ProductionConfig::PROCESS_NAME_PUNCH;
                $v['processes_code'] = 'daily_work';

                $data = json_decode($v['data'], true);
                $v['work_type'] = $data['work_type'] ?? 1;
                $v['work_hours'] = $data['work_hours'] ?? 12;
                $v['daily_wage'] = $data['daily_wage'] ?? 0;
                $v['allowance'] = $data['allowance'] ?? 0;
                $v['remark'] = $data['remark'] ?? '';
                $v['work_type_label'] = ((int) $v['work_type'] === 2) ? '半天日工' : '全天日工';

                $reportId = (int) ($v['id'] ?? 0);
                $v['daily_work_user_name'] = $dailyWorkUserByReportId[$reportId] ?? '';
            }
        }
        unset($v);

        return $list;
    }

    /**
     * 日工报工：同一次提交（相同提交时间+提交人）合并为一条，生产人员并列展示
     *
     * @param array $list
     * @return array
     */
    private static function mergeDailyWorkListRows(array $list): array
    {
        $result = [];
        $groupIndex = [];

        foreach ($list as $row) {
            if (empty($row['is_daily_work']) || (int) $row['is_daily_work'] !== 1) {
                $result[] = $row;
                continue;
            }

            $groupKey = ($row['create_time'] ?? '') . '_' . ($row['create_uid'] ?? '');
            $workerName = trim((string) ($row['daily_work_user_name'] ?? ''));

            if (!isset($groupIndex[$groupKey])) {
                $row['_dailyUserNames'] = $workerName !== '' ? [$workerName] : [];
                $row['_mergedWageTotal'] = (float) ($row['wage_total'] ?? 0);
                $row['_mergedWageDetail'] = (string) ($row['wage_detail'] ?? '');
                $row['_mergedReportCodes'] = [];
                $reportCode = trim((string) ($row['report_code'] ?? ''));
                if ($reportCode !== '') {
                    $row['_mergedReportCodes'][] = $reportCode;
                }
                $groupIndex[$groupKey] = count($result);
                $result[] = $row;
                continue;
            }

            $idx = $groupIndex[$groupKey];
            if ($workerName !== '' && !in_array($workerName, $result[$idx]['_dailyUserNames'], true)) {
                $result[$idx]['_dailyUserNames'][] = $workerName;
            }
            $result[$idx]['_mergedWageTotal'] += (float) ($row['wage_total'] ?? 0);
            $detail = trim((string) ($row['wage_detail'] ?? ''));
            if ($detail !== '') {
                $existingDetail = trim((string) ($result[$idx]['_mergedWageDetail'] ?? ''));
                $result[$idx]['_mergedWageDetail'] = $existingDetail === ''
                    ? $detail
                    : ($existingDetail . ';' . $detail);
            }
            $mergedCode = trim((string) ($row['report_code'] ?? ''));
            if ($mergedCode !== '') {
                $result[$idx]['_mergedReportCodes'][] = $mergedCode;
            }
        }

        foreach ($result as &$row) {
            if (empty($row['is_daily_work']) || (int) $row['is_daily_work'] !== 1) {
                continue;
            }
            if (!empty($row['_dailyUserNames'])) {
                $mergedNames = implode('、', $row['_dailyUserNames']);
                $row['product_user'] = $mergedNames;
                $row['daily_work_user_name'] = $mergedNames;
            }
            if (isset($row['_mergedWageTotal'])) {
                $row['wage_total'] = round((float) $row['_mergedWageTotal'], 2);
                $row['wage'] = $row['wage_total'];
            }
            if (isset($row['_mergedWageDetail'])) {
                $row['wage_detail'] = $row['_mergedWageDetail'];
            }
            if (!empty($row['_mergedReportCodes'])) {
                $row['merged_report_codes'] = array_values(array_unique($row['_mergedReportCodes']));
            }
            unset(
                $row['_dailyUserNames'],
                $row['_mergedWageTotal'],
                $row['_mergedWageDetail'],
                $row['_mergedReportCodes']
            );
        }
        unset($row);

        return $result;
    }
    public static function powerlist($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new ProductPowerModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            $all_list = $model->with(['getDevice','getProcess'])->where($where)->order($order)->select()->toArray();
            $merged_list = self::mergeNorthSouthData($all_list, $model);
            return [
                'count' => count($merged_list),
                'list' => $merged_list
            ];
        }

        $all_list = $model->with(['getDevice','getProcess'])->where($where)->order($order)->select()->toArray();

        $merged_list = self::mergeNorthSouthData($all_list, $model);

        $count = count($merged_list);
        $pages = ceil($count / $limit);
        $offset = ($page - 1) * $limit;
        $list = array_slice($merged_list, $offset, $limit);

        return compact('count', 'pages', 'page', 'limit', 'list');
    }

    private static function mergeNorthSouthData($all_list, $model)
    {
        $merged_list = [];
        $processed_ids = [];

        $user_ids = array_unique(array_column($all_list, 'create_uid'));
        $users = [];
        if (!empty($user_ids)) {
            $usermodel = new UserModel();
            $users = $usermodel->whereIn('user_id', $user_ids)->column('nickname', 'user_id');
        }

        foreach ($all_list as $k => &$v) {
            if (in_array($v['id'], $processed_ids)) {
                continue;
            }

            if (isset($users[$v['create_uid']])) {
                $v['create_name'] = $users[$v['create_uid']];
            }

            if (!empty($v['direction']) && in_array($v['direction'], ['南', '北'])) {
                $oppositeDirection = $v['direction'] == '北' ? '南' : '北';

                $currentTime = strtotime($v['create_time']);
                $startTime = date('Y-m-d H:i:s', $currentTime - 30);
                $endTime = date('Y-m-d H:i:s', $currentTime + 30);

                $device2 = $model->where('device_code', $v['device_code'])
                    ->where('direction', $oppositeDirection)
                    ->where('create_time', '>=', $startTime)
                    ->where('create_time', '<=', $endTime)
                    ->where('id', '<>', $v['id'])
                    ->find();

                if ($device2) {
                    $device2 = $device2->toArray();
                    $processed_ids[] = $device2['id'];

                    if (isset($users[$device2['create_uid']])) {
                        $device2['create_name'] = $users[$device2['create_uid']];
                    }

                    $south_data = $v['direction'] == '南' ? $v : $device2;
                    $north_data = $v['direction'] == '北' ? $v : $device2;

                    $merged_item = $v;
                    $merged_item['direction'] = '南北合并';

                    // remark 只取南向的数据（两条一样）
                    $merged_item['remark'] = $south_data['remark'] ?? '';

                    $merged_item['merged_id'] = $device2['id'];

                    $merged_item['power_num'] = '南:' . $south_data['power_num'] . ' 北:' . $north_data['power_num'];

                    // gas_num 只取南向的数据（两条一样）
                    $merged_item['gas_num'] = $south_data['gas_num'];

                    $merged_item['power_consume'] = number_format((float)$south_data['power_consume'] + (float)$north_data['power_consume'], 2, '.', '');
                    $merged_item['gas_consume'] = number_format((float)$south_data['gas_consume'] + (float)$north_data['gas_consume'], 2, '.', '');

                    $merged_list[] = $merged_item;
                } else {
                    $merged_list[] = $v;
                }
            } else {
                $merged_list[] = $v;
            }
        }

        return $merged_list;
    }


    /* 删除能耗抄表记录
    *
    * @param array $ids  能耗记录ID数组
    * @param bool  $real 是否真实删除（true=物理删除，false=逻辑删除）
    *
    * @return array
    */
    public static function deletePowerRecord($ids, $real = true)
    {
        Db::startTrans();
        try {
            if (empty($ids)) {
                exception('请选择要删除的记录');
            }

            $model = new ProductPowerModel();
            $pk = $model->getPk();

            // 确保 ids 是数组
            if (!is_array($ids)) {
                $ids = [$ids];
            }

            // 查询要删除的记录
            $records = $model->whereIn($pk, $ids)->select()->toArray();

            if (empty($records)) {
                exception('能耗记录不存在');
            }

            if ($real) {
                // 物理删除
                $res = $model->whereIn($pk, $ids)->delete();
            } else {
                // 逻辑删除
                $update = delete_update();
                $res = $model->whereIn($pk, $ids)->update($update);
            }

            if (empty($res)) {
                exception('删除失败');
            }

            // 提交事务
            Db::commit();

            return [
                'ids' => $ids,
                'count' => count($ids)
            ];
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('删除失败：' . $e->getMessage());
        }
    }




    /**
     * 工艺详情
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
            $task_model = new ProductTaskModel();
            $model = new ProductReportModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的报工信息不存在');
                }
                return [];
            }
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
        Db::startTrans();
        try {
            /*创建报工记录*/
            $model = new ProductReportModel();
            /*生成报工编码*/
            if (empty($param['report_code'])) {
                $param['report_code'] = PublicService::getCode(6)['code'];
            }
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $param['report_date'] = self::calcReportDate();

            /*需要计算一下同工序最新报工的电表气表数字*/
            if (!empty($param['defective_num'])) {
                $param['defective'] = json_encode($param['defective_num']);
                /*计算一下不良品项*/
                $defective_num = 0;
                foreach ($param['defective_num'] as $v) {
                    $defective_num += $v['num'];
                }
            }
            $model->insert($param);
            /*不同工序存储不同的数据*/
            $param['defective_num'] = $defective_num;
            /*同步修改生产任务已经报工的数量*/
            $task_model = new ProductTaskModel();
            $task_data = $task_model->where('task_code', $param['relation_code'])
                ->where('material_id', $param['material_id'])
                ->find();
            /*如果存在多条应该获取哪一条*/
            if (!empty($task_data)) {
                $task_model->where('task_code', $param['relation_code'])
                    ->where('material_id', $param['material_id'])
                    ->update(['report_num' => $task_data['report_num'] + (float)$param['report_num'],'defective_num' => $defective_num + (float)$param['defective_num']]);
            }
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
        $model = new ProductReportModel();
        $pk = $model->getPk();
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        if (empty($ids)) {
            exception('请选择要修改的记录');
        }

        Db::startTrans();
        try {
            $updatedIds = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                $oldRow = $model->where($pk, $id)->where('is_delete', 0)->find();
                if (empty($oldRow)) {
                    exception('报工记录不存在');
                }
                $old = is_array($oldRow) ? $oldRow : $oldRow->toArray();

                $patch = $param;
                unset($patch[$pk], $patch['ids']);
                $patch = self::normalizeReportEditPatch($patch);
                $patch = self::applyTaskContextToReportPatch($old, $patch);
                if (isset($patch['defective_num'])) {
                    $patch['defective'] = json_encode($patch['defective_num'], JSON_UNESCAPED_UNICODE);
                }

                $mergedData = self::mergeEditIntoReportData($old, $patch);
                $patch['data'] = json_encode($mergedData, JSON_UNESCAPED_UNICODE);
                $patch['update_uid'] = user_id();
                $patch['update_time'] = datetime();

                $res = $model->where($pk, $id)->update($patch);
                if (empty($res)) {
                    exception('修改失败');
                }

                $newRow = $model->where($pk, $id)->find();
                $new = is_array($newRow) ? $newRow : $newRow->toArray();
                self::cascadeAfterReportEdit($old, $new);
                $updatedIds[] = $id;
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('修改失败：' . $e->getMessage());
        }

        $param['ids'] = $updatedIds;
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
        $model = new ProductReportModel();
        $pk = $model->getPk();
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        if (empty($ids)) {
            exception('请选择要删除的记录');
        }

        $ids = DuangangMergeReportMirror::expandDeleteIds($ids);

        $records = $model->where($pk, 'in', $ids)->where('is_delete', 0)->select()->toArray();
        if (empty($records)) {
            exception('记录不存在');
        }

        Db::startTrans();
        try {
            foreach ($records as $r) {
                self::cascadeBeforeReportDelete($r);
            }

            $mergeSynced = [];
            $mergeTaskKeys = [];
            foreach ($records as $r) {
                $mainCode = DuangangMergeReportMirror::resolveMainReportCode($r);
                if ($mainCode === '' || isset($mergeSynced[$mainCode])) {
                    continue;
                }
                foreach (DuangangMergeReportMirror::syncOnDelete($r) as $pair) {
                    $mergeTaskKeys[$pair['task_code'] . "\x00" . $pair['material_id']] = $pair;
                }
                $mergeSynced[$mainCode] = true;
            }

            if ($real) {
                $res = $model->where($pk, 'in', $ids)->delete();
            } else {
                $update = delete_update();
                $res = $model->where($pk, 'in', $ids)->update($update);
            }
            if (empty($res)) {
                exception('删除失败');
            }

            $taskKeys = self::collectTaskKeysFromReports($records);
            foreach ($mergeTaskKeys as $pair) {
                $taskKeys[$pair['task_code'] . "\x00" . $pair['material_id']] = $pair;
            }
            foreach ($taskKeys as $pair) {
                self::rebuildTaskReportRollup($pair['task_code'], $pair['material_id']);
            }

            foreach ($records as $r) {
                self::cascadeAfterReportDelete($r);
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            exception('删除失败：' . $e->getMessage());
        }

        return ['ids' => $ids];
    }
    /*能耗统计列表*/
    /*当日---当月（分工序带工序名称；分设备带设备名称）*/
    public static function powerStat()
    {
        $model = new ProductPowerModel();
        $todayLike = date('Y-m-d') . '%';
        $monthLike = date('Y-m') . '%';

        $today_gas = $model->where('is_delete', 0)->where('create_time', 'like', $todayLike)->sum('gas_consume');
        $today_power = $model->where('is_delete', 0)->where('create_time', 'like', $todayLike)->sum('power_consume');
        $month_gas = $model->where('is_delete', 0)->where('create_time', 'like', $monthLike)->sum('gas_consume');
        $month_power = $model->where('is_delete', 0)->where('create_time', 'like', $monthLike)->sum('power_consume');

        $processes_month_gas = self::powerStatByProcess($monthLike);
        $processes_today_gas = self::powerStatByProcess($todayLike);

        $devices_month = self::powerStatByDevice($monthLike);
        $devices_today = self::powerStatByDevice($todayLike);

        return [
            'today_gas' => $today_gas,
            'today_power' => $today_power,
            'month_gas' => $month_gas,
            'month_power' => $month_power,
            'processes_month_gas' => $processes_month_gas,
            'processes_today_gas' => $processes_today_gas,
            'devices_month' => $devices_month,
            'devices_today' => $devices_today,
            'stat_date' => date('Y-m-d'),
            'stat_month' => date('Y-m'),
        ];
    }

    /**
     * 分工序汇总（关联工序名称）
     */
    private static function powerStatByProcess(string $timeLike): array
    {
        $sql = "
            SELECT
                pp.processes_code,
                MAX(pr.processes_name) AS processes_name,
                COALESCE(SUM(pp.gas_consume), 0) AS gas_num,
                COALESCE(SUM(pp.power_consume), 0) AS power_num
            FROM ya_product_power pp
            LEFT JOIN ya_processes pr ON pp.processes_code = pr.processes_code AND pr.is_delete = 0
            WHERE pp.is_delete = 0 AND pp.create_time LIKE ?
            GROUP BY pp.processes_code
            ORDER BY power_num DESC
        ";
        $rows = Db::query($sql, [$timeLike]);
        foreach ($rows as &$row) {
            $row['gas_num'] = round((float) ($row['gas_num'] ?? 0), 3);
            $row['power_num'] = round((float) ($row['power_num'] ?? 0), 3);
            if (empty($row['processes_name'])) {
                $row['processes_name'] = $row['processes_code'] ? (string) $row['processes_code'] : '—';
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * 分设备汇总（关联设备名称）
     */
    private static function powerStatByDevice(string $timeLike): array
    {
        $sql = "
            SELECT
                pp.device_code,
                MAX(d.device_name) AS device_name,
                MAX(d.processes_code) AS processes_code,
                MAX(prn.processes_name) AS processes_name,
                COALESCE(SUM(pp.gas_consume), 0) AS gas_num,
                COALESCE(SUM(pp.power_consume), 0) AS power_num
            FROM ya_product_power pp
            LEFT JOIN ya_device d ON pp.device_code = d.device_code AND d.is_delete = 0
            LEFT JOIN ya_processes prn ON d.processes_code = prn.processes_code AND prn.is_delete = 0
            WHERE pp.is_delete = 0
              AND pp.create_time LIKE ?
              AND pp.device_code IS NOT NULL
              AND pp.device_code <> ''
            GROUP BY pp.device_code
            ORDER BY power_num DESC
        ";
        $rows = Db::query($sql, [$timeLike]);
        foreach ($rows as &$row) {
            $row['gas_num'] = round((float) ($row['gas_num'] ?? 0), 3);
            $row['power_num'] = round((float) ($row['power_num'] ?? 0), 3);
            if (empty($row['device_name'])) {
                $row['device_name'] = $row['device_code'] ? (string) $row['device_code'] : '—';
            }
            if (empty($row['processes_name']) && !empty($row['processes_code'])) {
                $row['processes_name'] = (string) $row['processes_code'];
            }
        }
        unset($row);

        return $rows;
    }
    /*分工序报工接口*/
    public static function report($param)
    {
        Db::startTrans();
        try {
            /*对酸洗车间的报工要进行二次处理*/

            /*创建报工记录*/
            $model = new ProductReportModel();
            $task_model = new ProductTaskModel();
            /*生成报工编码*/
            if (empty($param['report_code'])) {
                $param['report_code'] = PublicService::getCode(6)['code'];
            }
            $param['create_time'] = datetime();
            $param['create_uid'] = user_id();
            $param['report_date'] = self::calcReportDate();
            $param['defective'] = json_encode($param['defective_num']);
            /*计算一下不良品项*/
            $defective_num = 0;
            foreach ($param['defective_num'] as $v) {
                $defective_num += (float)$v['num'];
            }
            $param['defective_num'] = $defective_num;
            /*获取工序名称*/
            $process_model = new ProcessesModel();
            $process = $process_model->where('processes_code', $param['processes_code'])->find();
            if (empty($process)) {
                \exception('没有找到对应工序');
            }
            /* [AI-2026-06] 断钢强制走合并报工 */
            if (DuangangMergeService::isDuangangProcessCode((string) $param['processes_code'])
                || (string) ($process['processes_name'] ?? '') === ProductionConfig::PROCESS_NAME_DUANGANG) {
                \exception('断钢工序请使用「断钢报工」合并入口');
            }
            /*查询成品物料ID*/
            $english_material = $task_model->alias('a')
                ->where('a.task_code', $param['relation_code'])
                ->leftJoin('ya_production b', 'a.relation_code = b.production_plan_code')
                ->find();
            if (empty($english_material)) {
                \exception('找不到对应成品物料信息,请尝试重新报工');
            }
            /* 小程序 not_full_attend 转为 work_hours，供穿孔/矫直等按实际上班小时计算 */
            self::mergeNotFullAttendIntoWorkHours($param);
            /*计算工序对应的工资*/
            $wage = self::ProcessWage($param, $process, $english_material, $param['is_replace'] ?? 0);
//            return $wage;
            /*最后一道工序的报工生成入库单---成品*/
            if ($process['is_finnally'] == 1) {
                /*生成成品入库单*/
                /*查看当前工单是否存在对应的物料的报工内容*/
                $add_stock = [
                    'in_code' => '',
                    'warehouse' => '成品仓库',
                    'material_batch' => $english_material['production_plan_code'] ?? $param['relation_code'],
                    'relation_code' => $english_material['production_plan_code'] ?? $param['relation_code'],
                    'content' => [[
                        'material_id' => $param['material_id'], //物料id
                        'in_num' => (float)$param['report_num'],  //入库数量
                        'in_code' => '',
                        'remark' => self::inStockRemarkForReport((string)$param['report_code']),
                    ]]
                ];
                /*创建入库单*/
                $result = InStockService::add($add_stock);
            }
            /*请求参数存储Json*/
            $param['data']  = json_encode($param);
            /*不同工序存储不同的数据*/
            $model->insert($param);
            /*同步修改生产任务已经报工的数量*/
            $task_data = $task_model->where('task_code', $param['relation_code'])
                ->where('material_id', $param['material_id'])
                ->find();
            if (!empty($task_data)) {
                /* 新增加的限制判断：校验工序报工数量是否超出计划数量 */
                $taskNum = (float)($task_data['task_num'] ?? 0);
                $currentProcessCode = $param['processes_code'];
                $currentTotal = 0;
                if (!empty($task_data['report'])) {
                    $reportJson = json_decode($task_data['report'], true);
                    if (is_array($reportJson) && isset($reportJson[$currentProcessCode])) {
                        $currentTotal = (float)($reportJson[$currentProcessCode]['report_num'] ?? 0);
                    }
                }
                $newTotal = round($currentTotal + (float)$param['report_num'], 3);
                if ($taskNum > 0 && $newTotal > $taskNum) {
                    \exception('当前工序累计报工数量(' . $newTotal . ')不能超过计划数量(' . $taskNum . ')');
                }

                /*读取json数据*/
                if (!empty($task_data['report'])) {
                    $report = json_decode($task_data['report'], true); // 添加第二个参数 true 返回数组
                    /*减将对应工序的报工数量加起来*/
                    $processCode = $param['processes_code'];
                    if (isset($report[$processCode])) {
                        // 存在相同工序，累加数据
                        $report[$processCode]['report_num'] = round((float)$param['report_num'] + (float)$report[$processCode]['report_num'], 3);
                        $report[$processCode]['defective_num'] = round((float)$param['defective_num'] + (float)$report[$processCode]['defective_num'], 3);
                    } else {
                        // 不存在相同工序，添加新数据
                        $report[$processCode] = [
                            'report_num' => $param['report_num'],
                            'defective_num' => $param['defective_num'],
                        ];
                    }
                    /*替换所有的报工记录中json文件  更换成最新的*/
                    $task_model->where('task_code', $param['relation_code'])
                        ->where('material_id', $param['material_id'])
                        ->update(['report' => json_encode($report)]);
                } else {
                    /*已经存在的报工json文件为空，则新保存一个新json文件*/
                    $add = [
                        $param['processes_code'] => [
                            'report_num' => $param['report_num'],
                            'defective_num' => $param['defective_num'],
                        ]
                    ];
                    /*修改当前工序的内容*/
                    $task_model->where('task_code', $param['relation_code'])
                        ->where('material_id', $param['material_id'])
                        ->update(['report' => json_encode($add)]);
                }
                /*暂时使用修改工序*/
                if ($task_data['processes_code'] != $param['processes_code']) {
                    $task_model->where('task_code', $param['relation_code'])
                    ->where('material_id', $param['material_id'])
                    ->update(['report_num' => $param['report_num'],'defective_num' => (float)$defective_num,'process_code' => $param['processes_code']]);
                } else {
                    $task_model->where('task_code', $param['relation_code'])
                    ->where('material_id', $param['material_id'])
                    ->update(['report_num' => (float)$task_data['report_num'] + (float)$param['report_num'],'defective_num' => (float)$defective_num + (float)$param['defective_num']]);
                }
                /*修改当前工单的状态--检查全工序是否达标自动完成（须更新 product_task，勿用 product_report 模型）*/
                $taskRow = is_array($task_data) ? $task_data : $task_data->toArray();
                $newStatus = self::calcTaskStatus($taskRow, $param['relation_code']);
                $task_model->where('task_code', $param['relation_code'])
                    ->where('material_id', $param['material_id'])
                    ->update(['status' => $newStatus]);
            } else {
                \exception('没有找到对应单据信息');
            }
            /*修改当前工单的状态*/

            // 提交事务
            Db::commit();
            if ((string) ($process['processes_name'] ?? '') === ProductionConfig::PROCESS_NAME_PUNCH) {
                try {
                    DuangangMergeService::rebuildAll();
                } catch (\Exception $ex) {
                }
            }
            // 更新日汇总表
            try {
                $reportDate = self::calcReportDate();
                $processModel = new ProcessesModel();
                $pInfo = $processModel->where('processes_code', $param['processes_code'] ?? '')->find();
                $pName = $pInfo ? $pInfo['processes_name'] : '';
                DailySummaryService::refresh($reportDate, $pName);
            } catch (\Exception $ex) {
            }
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            exception('创建失败:' . $e->getMessage());
        }
        return $param;
    }
    /*单独整一个能耗的接口*/
    public static function reportpower($param)
    {
        $model = new ProductPowerModel();
        $deviceModel = new \app\common\model\admin\DeviceModel();

        $flag = count($param['list']);
        $array = [];
        $reportDate = self::calcReportDate();

        foreach ($param['list'] as $k => &$v) {
            if (!empty($v['device_code'])) {
                $deviceInfo = $deviceModel->where('device_code', $v['device_code'])->find();
                $deviceName = $deviceInfo ? $deviceInfo['device_name'] : '';

                $isAcidWash = (strpos($deviceName, '酸洗') !== false || strpos($deviceName, '酸洗车间') !== false);

                if ($isAcidWash) {
                    if (isset($v['phosphating'])) {
                        $v['phosphating'] = round(floatval($v['phosphating']), 3);
                    }
                    if (isset($v['saponification'])) {
                        $v['saponification'] = round(floatval($v['saponification']), 3);
                    }
                    if (isset($v['alkali'])) {
                        $v['alkali'] = round(floatval($v['alkali']), 3);
                    }
                }
            }
            if ($flag > 2) {
                $data = $model->where('device_code', $v['device_code'])
                    ->where('direction', $v['direction'])
                    ->order('id desc')
                    ->find();

                if (empty($data)) {
                    $v['gas_consume'] = $v['gas_num'];
                    $v['power_consume'] = $v['power_num'];
                } else {
                    $v['gas_consume'] = (float)$v['gas_num'] - (float)$data['gas_num'];
                    $v['power_consume'] = (float)$v['power_num'] - (float)$data['power_num'];
                }

                $v['create_time'] = date('Y-m-d H:i:s');
                $v['create_uid'] = user_id();
                $v['report_date'] = $reportDate;
                array_push($array, $v);
            } else {
                $data = $model->where('device_code', $v['device_code'])
                    ->order('id desc')
                    ->find();

                if (empty($data)) {
                    $v['gas_consume'] = $v['gas_num'];
                    $v['power_consume'] = $v['power_num'];
                } else {
                    $v['gas_consume'] = (float)$v['gas_num'] - (float)$data['gas_num'];
                    $v['power_consume'] = (float)$v['power_num'] - (float)$data['power_num'];
                }

                $v['create_time'] = date('Y-m-d H:i:s');
                $v['create_uid'] = user_id();
                $v['report_date'] = $reportDate;
                array_push($array, $v);
            }
        }

        $model->saveAll($array);
        // 更新日汇总表
        try {
            DailySummaryService::refresh($reportDate);
        } catch (\Exception $ex) {
        }
        return $param;
    }

    /**
     * 计算工资工资并新增工资详情记录
     * @param $param
     * @return
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function addWage($param)
    {
        $model = new UserWageModel();
        $detail = new UserWageDetailModel();
        /*参数是数组获取不为空*/
        if (is_array($param) && !empty($param)) {
            foreach ($param as $k => $v) {
                $wageDate = self::resolveWageDateForRow($v);
                $wageMonth = date('m', strtotime($wageDate));
                $wageYear = date('Y', strtotime($wageDate));
                /*校验用户ID是否存在-是否创建了当月的工资表*/
                $result = $model
                    ->where('user_id', $v['user_id'])
                    ->where('month', $wageMonth)
                    ->where('year', $wageYear)
                    ->find();
                if (empty($result)) {
                    /*创建新用户*/
                    $model->insert(['user_id' => $v['user_id'], 'month' => $wageMonth, 'year' => $wageYear, 'wage' => $v['wage']]);
                } else {
                    /*修改工资数据*/
                    $data = $model->where('id', $result['id'])->update(['wage' => ((float)$result['wage'] + (float)$v['wage'])]);
                }
                /*创建工资详情*/
                $detail->insert(
                    [
                        'user_id' => $v['user_id'],
                        'username' => user_name($v['user_id']),
                        'date' => $wageDate,
                        'wage' => $v['wage'],
                        'report_num' => (float)$v['report_num'],
                        'material_id' => $v['material_id'],
                        'relation_code' => $v['relation_code'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'create_uid' => user_id(),
                        'device_code' => $v['device_code'],
                    ]
                );
            }
            return true;
        } else {
            return false;
        }
    }

    /** 固定工资：满勤小时（与穿孔等一致） */
    private const DEFAULT_WORK_HOURS = 12.0;

    /** 酸洗工序产量工资：元/吨 */
    private const ACID_WASH_WAGE_PER_TON = 7.0;

    /**
     * 临时策略：计件定额（ya_wage）无匹配时仍允许报工成功，且不写入本工序工资明细。
     * 补全定额后请改为 false，恢复「未找到单价配置则创建失败」。
     */
    private const ALLOW_REPORT_WITHOUT_PIECE_QUOTA = true;

    /**
     * 临时策略：工序工资列表（ya_process_wage）未配置该员工时仍允许报工成功（当前仅球化分支会校验并抛错），
     * 不写入该员工本工序工资明细。补全工序工资后请改为 false。
     */
    private const ALLOW_REPORT_WITHOUT_PROCESS_WAGE_ROW = true;

    /**
     * 报工是否跳过工资计算与明细写入。
     * 为 false 时按工序正常计薪并写入 user_wage_details（工序成本日报等依赖此表）。
     */
    private const SKIP_ALL_WAGE_CALCULATION_FOR_REPORT = false;

    /**
     * 报工可选 work_hours：按姓名取当日实际上班小时，默认满勤
     */
    private static function resolveReportWorkHours(array $param, string $nickname, float $maxHours = self::DEFAULT_WORK_HOURS): float
    {
        if (!empty($param['work_hours']) && is_array($param['work_hours']) && array_key_exists($nickname, $param['work_hours'])) {
            $h = (float) $param['work_hours'][$nickname];
            if ($h < 0) {
                $h = 0.0;
            }
            return $h > $maxHours ? $maxHours : $h;
        }
        return $maxHours;
    }

    /**
     * 将 not_full_attend[{name,hours}] 合并进 work_hours[name]，便于各工序 resolveReportWorkHours
     */
    private static function mergeNotFullAttendIntoWorkHours(array &$param): void
    {
        if (empty($param['not_full_attend']) || !is_array($param['not_full_attend'])) {
            return;
        }
        if (empty($param['work_hours']) || !is_array($param['work_hours'])) {
            $param['work_hours'] = [];
        }
        foreach ($param['not_full_attend'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            if ($name === '') {
                continue;
            }
            $h = array_key_exists('hours', $row) ? (float) $row['hours'] : self::DEFAULT_WORK_HOURS;
            $param['work_hours'][$name] = $h;
        }
    }

    /** 月薪：金额÷当月自然日；日薪：金额即为当日基准 */
    private static function dailyBaseFromProcessWageRow(array $row): float
    {
        $amount = (float) ($row['wage'] ?? 0);
        $isMonth = (int) ($row['is_month'] ?? 0);
        if ($isMonth === 1) {
            $daysInMonth = (int) date('t');
            return $daysInMonth > 0 ? $amount / $daysInMonth : $amount;
        }
        return $amount;
    }

    /**
     * 固定工资按不满勤折算：日基准÷12×实际小时
     */
    private static function fixedWageByProcessRowAndHours(array $processWageRow, float $hours): float
    {
        $daily = self::dailyBaseFromProcessWageRow($processWageRow);
        $full = self::DEFAULT_WORK_HOURS;
        $h = $hours;
        if ($h < 0) {
            $h = 0.0;
        }
        if ($h > $full) {
            $h = $full;
        }
        if ($full <= 0) {
            return round($daily, 2);
        }
        return round($daily / $full * $h, 2);
    }

    /**
     * 固定工资表：每人全局唯一启用行（按 user_id，不按工序再筛）
     */
    private static function findGlobalFixedWageRowForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $m = new ProcessWageModel();
        $row = $m->where('user_id', $userId)
            ->where('status', 1)
            ->where('is_delete', 0)
            ->order('id', 'desc')
            ->find();
        return empty($row) ? null : $row->toArray();
    }

    /**
     * 报工参与人 user_id 集合：product_user ∪ card_user ∪ 报工人（自己报工不传 product_user 时仅报工人）
     */
    private static function collectReportParticipantUserIds(array $param): array
    {
        $user = new UserModel();
        $map = [];
        $reporter = (int) user_id();
        if ($reporter > 0) {
            $map[$reporter] = true;
        }
        $addNicknames = static function ($list) use (&$map, $user) {
            if (!is_array($list)) {
                return;
            }
            foreach ($list as $nickname) {
                $nickname = trim((string) $nickname);
                if ($nickname === '') {
                    continue;
                }
                $u = $user->where('nickname', $nickname)->find();
                if (!empty($u)) {
                    $map[(int) $u['user_id']] = true;
                }
            }
        };
        if (!empty($param['product_user']) && is_array($param['product_user'])) {
            $addNicknames($param['product_user']);
        }
        if (!empty($param['card_user']) && is_array($param['card_user'])) {
            $addNicknames($param['card_user']);
        }
        return array_map('intval', array_keys($map));
    }

    private static function wageEffectiveDateFromParam(array $param): string
    {
        if (!empty($param['report_date'])) {
            $rd = strtotime((string) $param['report_date']);
            if ($rd !== false) {
                return date('Y-m-d', $rd);
            }
        }
        $t = strtotime((string) ($param['create_time'] ?? ''));
        if ($t === false) {
            return self::calcReportDate();
        }
        return date('Y-m-d', $t);
    }

    /** 成品入库 remark 与报工 report_code 绑定，便于删改联动 */
    private const IN_STOCK_REPORT_REMARK_PREFIX = 'report:';

    private static function inStockRemarkForReport(string $reportCode): string
    {
        return self::IN_STOCK_REPORT_REMARK_PREFIX . $reportCode;
    }

    private static function resolveWageDateForRow(array $row): string
    {
        if (!empty($row['report_date'])) {
            $t = strtotime((string) $row['report_date']);
            if ($t !== false) {
                return date('Y-m-d', $t);
            }
        }
        $reportCode = (string) ($row['relation_code'] ?? '');
        if ($reportCode !== '') {
            $pr = Db::name('product_report')
                ->where('report_code', $reportCode)
                ->where('is_delete', 0)
                ->field('report_date')
                ->find();
            if (!empty($pr['report_date'])) {
                $t = strtotime((string) $pr['report_date']);
                if ($t !== false) {
                    return date('Y-m-d', $t);
                }
            }
        }
        return self::calcReportDate();
    }

    private static function normalizeReportEditPatch(array $patch): array
    {
        if (isset($patch['defective_num']) && is_array($patch['defective_num'])) {
            $defectiveNum = 0.0;
            foreach ($patch['defective_num'] as $v) {
                $v = (array) $v;
                $defectiveNum += (float) ($v['num'] ?? 0);
            }
            $patch['defective_num'] = $defectiveNum;
        }
        return $patch;
    }

    /**
     * 改生产任务单号时，从 product_task / production 同步圆钢、成品、工艺等字段。
     */
    private static function applyTaskContextToReportPatch(array $old, array $patch): array
    {
        $newTaskCode = trim((string) ($patch['relation_code'] ?? $old['relation_code'] ?? ''));
        $oldTaskCode = trim((string) ($old['relation_code'] ?? ''));
        if ($newTaskCode === '') {
            return $patch;
        }
        if ($newTaskCode === $oldTaskCode && !array_key_exists('relation_code', $patch)) {
            return $patch;
        }

        $preferredMid = $patch['material_id'] ?? $old['material_id'] ?? null;
        $taskRow = self::resolveTaskRowForReport($newTaskCode, $preferredMid);
        $derived = self::taskDerivedReportFields($taskRow);
        // 落库必须为 task_code（scgd-*），避免 scjh 计划号导致物料/推算关联错误
        $derived['relation_code'] = (string) ($taskRow['task_code'] ?? $newTaskCode);
        return array_merge($patch, $derived);
    }

    /**
     * @param string $taskCode product_task.task_code
     * @param mixed    $preferredMaterialId 优先匹配的圆钢物料 id
     */
    private static function resolveTaskRowForReport(string $taskCodeOrPlan, $preferredMaterialId = null): array
    {
        $code = trim($taskCodeOrPlan);
        if ($code === '') {
            exception('生产任务单号不能为空');
        }
        $taskModel = new ProductTaskModel();
        $rows = $taskModel->where('task_code', $code)->where('is_delete', 0)->select()->toArray();
        // 兼容误填计划单号 scjh-*：按 production.relation_code 反查任务
        if (empty($rows)) {
            $rows = $taskModel->where('relation_code', $code)->where('is_delete', 0)->order('id', 'asc')->select()->toArray();
        }
        if (empty($rows)) {
            exception('生产任务不存在：' . $code);
        }
        if ($preferredMaterialId !== null && $preferredMaterialId !== '') {
            foreach ($rows as $row) {
                if ((string) ($row['material_id'] ?? '') === (string) $preferredMaterialId) {
                    return $row;
                }
            }
        }
        return $rows[0];
    }

    private static function taskDerivedReportFields(array $taskRow): array
    {
        $planCode = trim((string) ($taskRow['relation_code'] ?? ''));
        $plan = $planCode !== '' ? Db::name('production')->where('production_plan_code', $planCode)->find() : null;

        $routingCode = trim((string) ($taskRow['routing_code'] ?? ''));
        if ($routingCode === '' && !empty($plan['routing_code'])) {
            $routingCode = (string) $plan['routing_code'];
        }

        $fields = [
            'material_id' => $taskRow['material_id'] ?? '',
            'routing_code' => $routingCode,
        ];

        $cols = Db::query("SHOW COLUMNS FROM ya_product_report LIKE 'english_material'");
        if (!empty($cols) && !empty($plan['english_material'])) {
            $fields['english_material'] = (int) $plan['english_material'];
        }

        return $fields;
    }

    /**
     * 列表展示：按当前 relation_code + material_id 解析计划名、圆钢/成品规格（避免只改单号后规格仍显示旧工单）。
     */
    private static function enrichReportListRow(array &$row): void
    {
        $taskCode = trim((string) ($row['relation_code'] ?? ''));
        if ($taskCode === '') {
            return;
        }
        try {
            $taskRow = self::resolveTaskRowForReport($taskCode, $row['material_id'] ?? null);
        } catch (\Exception $e) {
            return;
        }

        $row['plan_code'] = (string) ($taskRow['relation_code'] ?? '');
        $planCode = $row['plan_code'];
        $plan = null;
        if ($planCode !== '') {
            $plan = Db::name('production')->where('production_plan_code', $planCode)->find();
            if (!empty($plan)) {
                $row['production_plan_name'] = (string) ($plan['production_plan_name'] ?? '');
            }
        }

        $yMid = (int) ($taskRow['material_id'] ?? 0);
        if ($yMid > 0) {
            $yMat = Db::name('material')
                ->where('id', $yMid)
                ->where('is_delete', 0)
                ->where('material_property', '圆钢')
                ->find();
            if (!empty($yMat)) {
                $row['y_material_spec'] = (string) ($yMat['material_spec'] ?? '');
                $row['y_material_model'] = (string) ($yMat['material_model'] ?? '');
                $row['y_material_name'] = (string) ($yMat['material_name'] ?? '');
                $row['y_material_manufacturer'] = (string) ($yMat['manufacturer'] ?? '');
            }
        }

        $engId = 0;
        if (!empty($plan['english_material'])) {
            $engId = (int) $plan['english_material'];
        }
        if ($engId > 0) {
            $cMat = Db::name('material')->where('id', $engId)->find();
            if (!empty($cMat)) {
                $row['c_material_spec'] = (string) ($cMat['material_spec'] ?? '');
                $row['c_material_model'] = (string) ($cMat['material_model'] ?? '');
                $row['c_material_name'] = (string) ($cMat['material_name'] ?? '');
            }
        }
    }

    private static function mergeEditIntoReportData(array $old, array $patch): array
    {
        $base = json_decode($old['data'] ?? '{}', true);
        if (!is_array($base)) {
            $base = [];
        }
        foreach (
            [
            'report_num', 'yarn_count', 'class', 'report_date', 'relation_code', 'material_id',
            'processes_code', 'routing_code', 'device_code', 'remark', 'product_user', 'card_user',
            'is_replace', 'work_hours', 'not_full_attend', 'device_name',
            ] as $key
        ) {
            if (array_key_exists($key, $patch)) {
                $base[$key] = $patch[$key];
            }
        }
        if (isset($patch['defective_num']) && is_array($patch['defective_num'])) {
            $base['defective_num'] = $patch['defective_num'];
        } elseif (array_key_exists('defective_num', $patch)) {
            $base['defective_num'] = $patch['defective_num'];
        }
        return $base;
    }

    private static function buildReportParamFromRecord(array $record): array
    {
        $data = json_decode($record['data'] ?? '{}', true);
        if (!is_array($data)) {
            $data = [];
        }
        $param = array_merge($data, $record);
        $defective = json_decode($record['defective'] ?? '[]', true);
        if (is_array($defective) && isset($defective[0]['name'])) {
            $param['defective_num'] = $defective;
        } elseif (is_numeric($record['defective_num'] ?? null)) {
            $param['defective_num'] = [];
        } else {
            $param['defective_num'] = is_array($defective) ? $defective : [];
        }
        $param['report_code'] = (string) ($record['report_code'] ?? '');
        $param['relation_code'] = (string) ($record['relation_code'] ?? '');
        $param['processes_code'] = (string) ($record['processes_code'] ?? '');
        $param['material_id'] = $record['material_id'] ?? '';
        $param['report_num'] = (float) ($record['report_num'] ?? 0);
        $param['class'] = (int) ($record['class'] ?? 0);
        $param['report_date'] = (string) ($record['report_date'] ?? '');
        $param['create_time'] = (string) ($record['create_time'] ?? datetime());
        $param['create_uid'] = (int) ($record['create_uid'] ?? user_id());
        return $param;
    }

    private static function resolveProductionPlanCode(array $report): string
    {
        $taskCode = (string) ($report['relation_code'] ?? '');
        if ($taskCode === '') {
            return '';
        }
        $taskModel = new ProductTaskModel();
        $row = $taskModel->alias('a')
            ->where('a.task_code', $taskCode)
            ->leftJoin('ya_production b', 'a.relation_code = b.production_plan_code')
            ->field('b.production_plan_code')
            ->find();
        if (empty($row)) {
            return $taskCode;
        }
        $arr = is_array($row) ? $row : $row->toArray();
        return (string) ($arr['production_plan_code'] ?? $taskCode);
    }

    private static function resolveEnglishMaterialForReport(array $report): ?array
    {
        $taskModel = new ProductTaskModel();
        $englishMaterial = $taskModel->alias('a')
            ->where('a.task_code', $report['relation_code'] ?? '')
            ->leftJoin('ya_production b', 'a.relation_code = b.production_plan_code')
            ->find();
        if (empty($englishMaterial)) {
            return null;
        }
        return is_array($englishMaterial) ? $englishMaterial : $englishMaterial->toArray();
    }

    private static function collectTaskKeysFromReports(array $records): array
    {
        $keys = [];
        foreach ($records as $r) {
            $tc = (string) ($r['relation_code'] ?? '');
            $mid = $r['material_id'] ?? '';
            if ($tc !== '' && $mid !== '') {
                $keys[$tc . "\x00" . (string) $mid] = ['task_code' => $tc, 'material_id' => $mid];
            }
        }
        return $keys;
    }

    private static function revokeWageDetailsByReportCode(string $reportCode): void
    {
        if ($reportCode === '') {
            return;
        }
        $rows = Db::name('user_wage_details')
            ->where('relation_code', $reportCode)
            ->whereRaw('(is_delete IS NULL OR is_delete = 0)')
            ->field('id,user_id,wage,date,create_time')
            ->select()
            ->toArray();
        if (empty($rows)) {
            return;
        }
        $sumByUser = [];
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) ($r['id'] ?? 0);
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $sumByUser[$uid] = ($sumByUser[$uid] ?? 0.0) + (float) ($r['wage'] ?? 0);
        }
        $ids = array_values(array_filter($ids));
        foreach ($sumByUser as $uid => $deduct) {
            if ($uid <= 0 || $deduct <= 0.000001) {
                continue;
            }
            $sampleDate = '';
            foreach ($rows as $r) {
                if ((int) ($r['user_id'] ?? 0) === $uid) {
                    $sampleDate = trim((string) ($r['date'] ?? ''));
                    if ($sampleDate === '' && !empty($r['create_time'])) {
                        $sampleDate = date('Y-m-d', strtotime((string) $r['create_time']));
                    }
                    break;
                }
            }
            if ($sampleDate === '') {
                $sampleDate = date('Y-m-d');
            }
            $year = (int) date('Y', strtotime($sampleDate));
            $monthStr = date('m', strtotime($sampleDate));
            $uw = Db::name('user_wage')
                ->where('user_id', $uid)
                ->where('year', $year)
                ->where('month', $monthStr)
                ->find();
            if (!empty($uw)) {
                $newW = max(0.0, (float) ($uw['wage'] ?? 0) - $deduct);
                Db::name('user_wage')->where('id', (int) $uw['id'])->update(['wage' => $newW]);
            }
        }
        foreach (array_chunk($ids, 400) as $chunk) {
            Db::name('user_wage_details')->where('id', 'in', $chunk)->delete();
        }
    }

    private static function findInStockRowsForReport(array $report): array
    {
        $reportCode = (string) ($report['report_code'] ?? '');
        if ($reportCode !== '') {
            $tag = self::inStockRemarkForReport($reportCode);
            $tagged = Db::name('wms_in')
                ->where('is_delete', 0)
                ->where('remark', $tag)
                ->select()
                ->toArray();
            if (!empty($tagged)) {
                return $tagged;
            }
        }

        $plan = self::resolveProductionPlanCode($report);
        if ($plan === '') {
            return [];
        }
        $materialId = $report['material_id'] ?? '';
        $qty = (float) ($report['report_num'] ?? 0);
        $createTime = (string) ($report['create_time'] ?? '');
        $q = Db::name('wms_in')
            ->where('is_delete', 0)
            ->where('warehouse', '成品仓库')
            ->where('relation_code', $plan)
            ->where('material_id', $materialId);
        if ($qty > 0.00001) {
            $q->where('in_num', $qty);
        }
        if ($createTime !== '') {
            $t = strtotime($createTime);
            if ($t !== false) {
                $q->whereBetween('create_time', [
                    date('Y-m-d H:i:s', $t - 600),
                    date('Y-m-d H:i:s', $t + 600),
                ]);
            }
        }
        return $q->order('id', 'desc')->limit(3)->select()->toArray();
    }

    private static function voidInStockForReport(array $report): void
    {
        $rows = self::findInStockRowsForReport($report);
        if (empty($rows)) {
            return;
        }
        $model = new InStockModel();
        $pk = $model->getPk();
        foreach ($rows as $row) {
            $batch = trim((string) ($row['material_batch'] ?? ''));
            $num = (float) ($row['in_num'] ?? 0);
            $mid = (string) ($row['material_id'] ?? '');
            $wh = (string) ($row['warehouse'] ?? '');
            if ($batch !== '' && abs($num) > 0.00001) {
                InStockService::AddmaterialNum($mid, -$num, $batch, $wh);
            }
            $model->where($pk, (int) ($row['id'] ?? 0))->update([
                'is_delete' => 1,
                'delete_uid' => user_id(),
                'delete_time' => datetime(),
            ]);
        }
    }

    private static function createFinalProcessInStockForReport(array $report, array $englishMaterial): void
    {
        $planCode = $englishMaterial['production_plan_code'] ?? $report['relation_code'];
        $addStock = [
            'in_code' => '',
            'warehouse' => '成品仓库',
            'material_batch' => $planCode,
            'relation_code' => $planCode,
            'content' => [[
                'material_id' => $report['material_id'],
                'in_num' => (float) ($report['report_num'] ?? 0),
                'in_code' => '',
                'remark' => self::inStockRemarkForReport((string) ($report['report_code'] ?? '')),
            ]],
        ];
        InStockService::add($addStock);
    }

    private static function syncFinalProcessInStockOnEdit(array $old, array $new): void
    {
        $processModel = new ProcessesModel();
        $oldProcess = $processModel->where('processes_code', $old['processes_code'] ?? '')->find();
        $newProcess = $processModel->where('processes_code', $new['processes_code'] ?? '')->find();
        $wasFinal = !empty($oldProcess) && (int) ($oldProcess['is_finnally'] ?? 0) === 1;
        $isFinal = !empty($newProcess) && (int) ($newProcess['is_finnally'] ?? 0) === 1;

        $englishMaterial = self::resolveEnglishMaterialForReport($new);
        if ($wasFinal && !$isFinal) {
            self::voidInStockForReport($old);
            return;
        }
        if (!$wasFinal && $isFinal) {
            if ($englishMaterial) {
                self::createFinalProcessInStockForReport($new, $englishMaterial);
            }
            return;
        }
        if (!$wasFinal || !$isFinal) {
            return;
        }

        $planChanged = self::resolveProductionPlanCode($old) !== self::resolveProductionPlanCode($new)
            || (string) ($old['material_id'] ?? '') !== (string) ($new['material_id'] ?? '');
        if ($planChanged) {
            self::voidInStockForReport($old);
            if ($englishMaterial) {
                self::createFinalProcessInStockForReport($new, $englishMaterial);
            }
            return;
        }

        $rows = self::findInStockRowsForReport($old);
        if (empty($rows)) {
            if ($englishMaterial) {
                self::createFinalProcessInStockForReport($new, $englishMaterial);
            }
            return;
        }

        $row = $rows[0];
        $oldNum = (float) ($row['in_num'] ?? 0);
        $newNum = (float) ($new['report_num'] ?? 0);
        $delta = round($newNum - $oldNum, 3);
        if (abs($delta) <= 0.00001) {
            return;
        }
        $model = new InStockModel();
        $pk = $model->getPk();
        $model->where($pk, (int) ($row['id'] ?? 0))->update(['in_num' => $newNum]);
        $batch = trim((string) ($row['material_batch'] ?? ''));
        $mid = (string) ($row['material_id'] ?? '');
        $wh = (string) ($row['warehouse'] ?? '');
        if ($batch !== '') {
            InStockService::AddmaterialNum($mid, $delta, $batch, $wh);
        }
    }

    private static function recalculateWageForReport(array $report): void
    {
        if (self::SKIP_ALL_WAGE_CALCULATION_FOR_REPORT) {
            return;
        }
        $reportCode = (string) ($report['report_code'] ?? '');
        if ($reportCode === '') {
            return;
        }
        $mainCode = DuangangMergeReportMirror::resolveMainReportCode($report);
        $wageRevokeCode = $mainCode !== '' ? $mainCode : $reportCode;
        self::revokeWageDetailsByReportCode($wageRevokeCode);

        $processModel = new ProcessesModel();
        $process = $processModel->where('processes_code', $report['processes_code'] ?? '')->find();
        $englishMaterial = self::resolveEnglishMaterialForReport($report);
        if (empty($process) || empty($englishMaterial)) {
            return;
        }
        $param = self::buildReportParamFromRecord($report);
        $param = self::enrichReportParamForMergeWage($report, $param);
        $isReplace = (int) ($param['is_replace'] ?? 0);
        self::ProcessWage($param, $process, $englishMaterial, $isReplace);
    }

    /**
     * 断钢合并镜像行：计薪按 merge 表整单吨数与合并池型号
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $param
     *
     * @return array<string,mixed>
     */
    private static function enrichReportParamForMergeWage(array $report, array $param): array
    {
        $mainCode = DuangangMergeReportMirror::resolveMainReportCode($report);
        if ($mainCode === '') {
            return $param;
        }
        $merge = Db::name('duangang_merge_report')
            ->where('report_code', $mainCode)
            ->where('is_delete', 0)
            ->find();
        if (empty($merge)) {
            return $param;
        }
        $param['report_code'] = $mainCode;
        $param['report_num'] = (float) ($merge['report_num'] ?? 0);
        $groupId = (int) ($merge['merge_group_id'] ?? 0);
        if ($groupId > 0) {
            $group = Db::name('duangang_merge_group')->where('id', $groupId)->find();
            if (!empty($group)) {
                $spec = trim((string) ($group['y_material_model'] ?? ''));
                if ($spec === '') {
                    $spec = trim((string) ($group['y_material_spec'] ?? ''));
                }
                if ($spec !== '') {
                    $param['wage_material_spec'] = $spec;
                }
            }
        }

        return $param;
    }

    private static function refreshSummariesForReport(array $report, ?string $alsoRefreshDate = null): void
    {
        $dates = [];
        $d = trim((string) ($report['report_date'] ?? ''));
        if ($d !== '') {
            $dates[$d] = true;
        }
        if ($alsoRefreshDate !== null && trim($alsoRefreshDate) !== '') {
            $dates[trim($alsoRefreshDate)] = true;
        }
        if (empty($dates)) {
            return;
        }
        $processModel = new ProcessesModel();
        $pInfo = $processModel->where('processes_code', $report['processes_code'] ?? '')->find();
        $pName = $pInfo ? (string) $pInfo['processes_name'] : '';
        foreach (array_keys($dates) as $reportDate) {
            try {
                DailySummaryService::refresh($reportDate, $pName);
            } catch (\Exception $ex) {
            }
        }
    }

    private static function cascadeBeforeReportDelete(array $report): void
    {
        $mainCode = DuangangMergeReportMirror::resolveMainReportCode($report);
        $wageCode = $mainCode !== '' ? $mainCode : (string) ($report['report_code'] ?? '');
        self::revokeWageDetailsByReportCode($wageCode);
        self::voidInStockForReport($report);
    }

    private static function cascadeAfterReportDelete(array $report): void
    {
        self::refreshSummariesForReport($report);
    }

    private static function cascadeAfterReportEdit(array $old, array $new): void
    {
        $oldDate = (string) ($old['report_date'] ?? '');
        $newDate = (string) ($new['report_date'] ?? '');

        self::syncFinalProcessInStockOnEdit($old, $new);

        $taskKeys = self::collectTaskKeysFromReports([$old, $new]);
        foreach ($taskKeys as $pair) {
            self::rebuildTaskReportRollup($pair['task_code'], $pair['material_id']);
        }

        self::recalculateWageForReport($new);

        $also = ($oldDate !== '' && $oldDate !== $newDate) ? $oldDate : null;
        self::refreshSummariesForReport($new, $also);
    }

    /**
     * 同一自然日、同一工序、同一班次内重复报工：删除旧工资明细并冲减当月 user_wage，便于本次覆盖写入。
     */
    /**
     * @param string $taskCode 生产任务单号（product_task.task_code / product_report.relation_code）。
     *                         非空时只撤销属于同一计划任务的工资明细，防止同工人同天不同计划互删工资。
     */
    private static function revokePriorWageDetailsForShift(array $userIds, string $reportDate, string $processesCode, int $class, string $taskCode = ''): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === [] || $processesCode === '' || $reportDate === '') {
            return;
        }
        $q = Db::name('user_wage_details')
            ->alias('d')
            ->join('product_report pr', 'd.relation_code = pr.report_code')
            ->whereIn('d.user_id', $userIds)
            ->where('pr.processes_code', $processesCode)
            ->where('pr.class', $class)
            ->whereRaw("COALESCE(NULLIF(TRIM(d.date), ''), DATE(d.create_time)) = ?", [$reportDate])
            ->whereRaw('(d.is_delete IS NULL OR d.is_delete = 0)');
        // 精确限定到同一生产计划任务，避免同工人同天对不同计划报工时互相撤销工资
        if ($taskCode !== '') {
            $q->where('pr.relation_code', $taskCode);
        }
        $rows = $q->field('d.id,d.user_id,d.wage')->select()->toArray();
        if (empty($rows)) {
            $rows = self::revokePriorWageDetailsFromMergeReport($userIds, $reportDate, $processesCode, $class, $taskCode);
        }
        if (empty($rows)) {
            return;
        }
        $sumByUser = [];
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) ($r['id'] ?? 0);
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $sumByUser[$uid] = ($sumByUser[$uid] ?? 0.0) + (float) ($r['wage'] ?? 0);
        }
        $ids = array_values(array_filter($ids));
        $year = (int) date('Y', strtotime($reportDate));
        $monthStr = date('m', strtotime($reportDate));
        foreach ($sumByUser as $uid => $deduct) {
            if ($uid <= 0 || $deduct <= 0.000001) {
                continue;
            }
            $uw = Db::name('user_wage')
                ->where('user_id', $uid)
                ->where('year', $year)
                ->where('month', $monthStr)
                ->find();
            if (!empty($uw)) {
                $newW = max(0.0, (float) ($uw['wage'] ?? 0) - $deduct);
                Db::name('user_wage')->where('id', (int) $uw['id'])->update(['wage' => $newW]);
            }
        }
        foreach (array_chunk($ids, 400) as $chunk) {
            Db::name('user_wage_details')->where('id', 'in', $chunk)->delete();
        }
    }

    /**
     * 断钢合并报工：工资明细 relation_code 指向 merge 表 report_code 时的同班撤销
     *
     * @return array<int,array<string,mixed>>
     */
    private static function revokePriorWageDetailsFromMergeReport(
        array $userIds,
        string $reportDate,
        string $processesCode,
        int $class,
        string $taskCode = ''
    ): array {
        $q = Db::name('user_wage_details')
            ->alias('d')
            ->join('duangang_merge_report dmr', 'd.relation_code = dmr.report_code')
            ->whereIn('d.user_id', $userIds)
            ->where('dmr.processes_code', $processesCode)
            ->where('dmr.class', $class)
            ->where('dmr.report_date', $reportDate)
            ->where('dmr.is_delete', 0)
            ->whereRaw('(d.is_delete IS NULL OR d.is_delete = 0)');
        if ($taskCode !== '') {
            $q->join('duangang_merge_member dmm', 'dmm.merge_group_id = dmr.merge_group_id')
                ->where('dmm.task_code', $taskCode)
                ->where('dmm.is_delete', 0);
        }

        return $q->field('d.id,d.user_id,d.wage')->select()->toArray();
    }

    public static function resolveTaskRowForReportPublic(string $taskCodeOrPlan, $preferredMaterialId = null): array
    {
        return self::resolveTaskRowForReport($taskCodeOrPlan, $preferredMaterialId);
    }

    public static function taskDerivedReportFieldsPublic(array $taskRow): array
    {
        return self::taskDerivedReportFields($taskRow);
    }

    private static function findSpheroidizingProcessWageForUser(ProcessWageModel $m, string $processCode, int $userId): ?array
    {
        $row = $m->where('processes_code', $processCode)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->find();
        return empty($row) ? null : $row->toArray();
    }

    private static function findAcidWashLeaderWageRow(ProcessWageModel $m, string $processCode): ?array
    {
        $row = $m->where('processes_code', $processCode)
            ->where('status', 1)
            ->whereIn('post_name', ['生产班长', '班长'])
            ->order('id', 'desc')
            ->find();
        return empty($row) ? null : $row->toArray();
    }

    private static function findAcidWashWaterTreatmentWageRow(ProcessWageModel $m, string $processCode, int $userId): ?array
    {
        $rows = $m->where('processes_code', $processCode)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->select()
            ->toArray();
        foreach ($rows as $r) {
            $pn = (string) ($r['post_name'] ?? '');
            if ($pn === '水处理工' || str_contains($pn, '水处理')) {
                return $r;
            }
        }
        return null;
    }

    private static function userIsProductionLeaderSafe(int $userId): bool
    {
        try {
            return PublicService::is_class($userId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 酸洗：班长（生产班长职位）或水处理岗位 → 固定工资行；否则参与产量分配
     */
    private static function resolveAcidWashFixedWageRow(ProcessWageModel $m, string $processCode, int $userId): ?array
    {
        if (self::userIsProductionLeaderSafe($userId)) {
            $leader = self::findAcidWashLeaderWageRow($m, $processCode);
            if (!empty($leader)) {
                return $leader;
            }
        }
        return self::findAcidWashWaterTreatmentWageRow($m, $processCode, $userId);
    }

    /**
     * 断钢计件：ya_wage.material_spec 为「断钢」+ 段（优先取任务原料圆钢型号 y_material_model，空则回退 y_material_spec）。
     * 任务上可能带「溯源(邯钢60)」或「邯钢60」，而计价表常只维护「断钢60」。先精确匹配，再按段末尾数字回退（如 断钢邯钢60 → 断钢60）。
     *
     * @return array{0: array|\think\Model|null, 1: string} [工资行, 实际命中的计价键]
     */
    private static function findDuanGangWageRow(WageModel $wage_model, string $segment): array
    {
        $segment = trim($segment);
        if ($segment === '') {
            return [null, ''];
        }
        if (preg_match('/^溯源\s*\((.+)\)\s*$/u', $segment, $m)) {
            $segment = trim($m[1]);
        }
        $tryKeys = [];
        $primary = '断钢' . $segment;
        $tryKeys[] = $primary;
        if (preg_match('/(\d+)$/', $segment, $m) && $m[1] !== '') {
            $alt = '断钢' . $m[1];
            if ($alt !== $primary) {
                $tryKeys[] = $alt;
            }
        }
        foreach ($tryKeys as $specKey) {
            $work = $wage_model->where('material_spec', $specKey)->find();
            if (!empty($work)) {
                return [$work, $specKey];
            }
        }
        return [null, $tryKeys[0]];
    }

    /**
     * 圆钢计件段：型号优先，依次回退材质和成品规格。
     *
     * @param array<string,mixed> $taskData
     * @return string
     */
    public static function resolveRoundSteelWageSegment(array $taskData): string
    {
        foreach (['y_material_model', 'y_material_spec', 'c_material_spec'] as $field) {
            $value = trim((string) ($taskData[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * 根据工序计算工资
     * @param $param
     * @param $process
     * @param $english_material
     * @return true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function ProcessWage($param, $process, $english_material, $is_replace = 0)
    {
        if (self::SKIP_ALL_WAGE_CALCULATION_FOR_REPORT) {
            return true;
        }

        $task = new ProductTaskModel();
        $task_data =  $task->where('task_code', $english_material['task_code'])->find();
        /*这是查找对应生产任务的数据*/
        $data = ProductTaskService::info($task_data['id']);
        $processName = (string)($process['processes_name'] ?? '');
        $isDuanGang = $processName === '断钢' || $processName === '锻钢';
        if (!empty($data)) {
            if ($isDuanGang) {
                if (!empty($param['wage_material_spec'])) {
                    $param['material_spec'] = trim((string) $param['wage_material_spec']);
                } else {
                    /* [AI-2026-08] 保持型号→材质→成品规格回退链 */
                    $param['material_spec'] = self::resolveRoundSteelWageSegment($data);
                }
            } elseif ($processName === '穿孔') {
                /* [AI-2026-08] 穿孔保持与断钢相同回退链 */
                $param['material_spec'] = self::resolveRoundSteelWageSegment($data);
            } else {
                $param['material_spec'] = $data['c_material_spec'] ?? '';
            }
        }
        /*取出对应的长度 - 防止 null 值导致错误*/
        if (empty($param['material_spec'])) {
            if ($isDuanGang) {
                \exception('断钢工序：圆钢型号与规格均为空，无法匹配计件单价');
            }
            if ($processName === '穿孔') {
                \exception('穿孔工序：圆钢型号与规格均为空，无法匹配计件单价');
            }
            \exception('物料规格不能为空');
        }
        /*取出对应的长度*/
        $param['material_spec'] = explode('*', $param['material_spec'])[0];
        /*报工数量*/
        $report_num = (float)$param['report_num'];
        /*查询成品物料*/


        /*根据报工工序计算员工工资（工序名历史上可能为「锻钢」，与「断钢」等同，单价键统一为「断钢」+圆钢型号段，空时兼容规格段）*/
        if ($isDuanGang) {
            $pc = (string) ($process['processes_code'] ?? '');
            $cl = (int) ($param['class'] ?? 0);
            $wageDate = self::wageEffectiveDateFromParam($param);
            $reporterId = (int) user_id();
            self::revokePriorWageDetailsForShift([$reporterId], $wageDate, $pc, $cl, (string)($param['relation_code'] ?? ''));

            $globalRow = self::findGlobalFixedWageRowForUser($reporterId);
            $wageAmount = 0.0;
            if (!empty($globalRow)) {
                $nick = (string) user_name($reporterId);
                $hours = self::resolveReportWorkHours($param, $nick);
                $wageAmount = (float) self::fixedWageByProcessRowAndHours($globalRow, $hours);
            } else {
                $wage_model = new WageModel();
                if (isset($param['material_spec'])) {
                    [$work, $specKey] = self::findDuanGangWageRow($wage_model, (string) $param['material_spec']);
                } else {
                    $work = null;
                    $specKey = '';
                }
                if (empty($work)) {
                    if (self::ALLOW_REPORT_WITHOUT_PIECE_QUOTA) {
                        return true;
                    }
                    $hint = $specKey !== '' ? $specKey : (isset($param['material_spec']) ? ('断钢' . $param['material_spec']) : '');
                    \exception('未找到断钢工序的单价配置：' . $hint);
                }
                $wageAmount = (float) number_format((float) $work['wage'] * (float) $report_num, 2, '.', '');
            }
            $add_field = [
                [
                    'user_id' => $reporterId,
                    'relation_code' => $param['report_code'],
                    'material_id' => $english_material['english_material'],
                    'wage' => $wageAmount,
                    'report_num' => $report_num,
                    'wage_sum' => $wageAmount,
                    'create_time' => datetime(),
                    'create_uid' => user_id(),
                    'device_code' => $param['device_code'],
                ]
            ];
            return self::addWage($add_field);
        } elseif ($process['processes_name'] == '穿孔') {
            $pc = (string) ($process['processes_code'] ?? '');
            $cl = (int) ($param['class'] ?? 0);
            $wageDate = self::wageEffectiveDateFromParam($param);
            $participants = self::collectReportParticipantUserIds($param);
            self::revokePriorWageDetailsForShift($participants, $wageDate, $pc, $cl, (string)($param['relation_code'] ?? ''));

            $device_name = '';
            if (!empty($param['device_code'])) {
                $device = new DeviceModel();
                $device_data = $device->where('device_code', $param['device_code'])->find();
                $device_name = $device_data['device_name'] ?? '';
            }

            $wage_model = new WageModel();
            $wage = 0.0;
            if (empty($param['material_spec'])) {
                \exception('穿孔工序：计价段（圆钢型号/规格）为空');
            }
            $search_spec = trim($device_name . ' ' . $param['material_spec']);
            if ($search_spec === '') {
                \exception('穿孔工序缺少设备名称或圆钢型号段，无法匹配单价');
            }
            $work = $wage_model->where('material_spec', '=', $search_spec)->find();
            if (empty($work)) {
                if (self::ALLOW_REPORT_WITHOUT_PIECE_QUOTA) {
                    return true;
                }
                \exception('未找到穿孔工序的单价配置：' . $search_spec);
            }
            $wage = round((float) $work['wage'] * (float) $report_num, 2);

            $user = new UserModel();
            $default_work_hours = 12;
            $person_list = [];
            if (!empty($param['product_user']) && is_array($param['product_user'])) {
                foreach ($param['product_user'] as $nickname) {
                    $person_list[] = [
                        'nickname' => $nickname,
                        'type' => 'product',
                        'hours' => $default_work_hours,
                    ];
                }
            }
            if (!empty($param['card_user']) && is_array($param['card_user'])) {
                foreach ($param['card_user'] as $nickname) {
                    $person_list[] = [
                        'nickname' => $nickname,
                        'type' => 'card',
                        'hours' => $default_work_hours,
                    ];
                }
            }
            if (empty($person_list)) {
                \exception('穿孔工序必须指定生产员工或开车人员');
            }
            if (!empty($param['work_hours']) && is_array($param['work_hours'])) {
                foreach ($person_list as &$person) {
                    if (isset($param['work_hours'][$person['nickname']])) {
                        $person['hours'] = min((float) $param['work_hours'][$person['nickname']], $default_work_hours);
                    }
                }
                unset($person);
            }

            $nicknames = array_column($person_list, 'nickname');
            $users = $user->whereIn('nickname', $nicknames)->column('*', 'nickname');
            foreach ($person_list as $person) {
                if (!isset($users[$person['nickname']])) {
                    \exception('找不到姓名为：' . $person['nickname'] . ' 的员工信息');
                }
            }

            // 6. 计算开车人员动态系数（固定额外池0.2代表2辆车，多人均摊）
            $product_coefficient = 1.0;
            $card_extra_pool = 0.2;
            $card_count = 0;
            foreach ($person_list as $person) {
                if ($person['type'] === 'card') {
                    $card_count++;
                }
            }
            $card_coefficient = $card_count > 0
                ? $product_coefficient + ($card_extra_pool / $card_count)
                : $product_coefficient;

            $coefficient_map = ['product' => $product_coefficient, 'card' => $card_coefficient];
            $items = [];
            foreach ($person_list as $person) {
                $user_info = $users[$person['nickname']];
                $uid = (int) $user_info['user_id'];
                $coefficient = $coefficient_map[$person['type']];
                $hours = (float) $person['hours'];
                $items[] = [
                    'user_info' => $user_info,
                    'nickname' => $person['nickname'],
                    'coefficient' => $coefficient,
                    'hours' => $hours,
                    'global_row' => self::findGlobalFixedWageRowForUser($uid),
                ];
            }

            $total_piece_weighted = 0.0;
            foreach ($items as $it) {
                if (empty($it['global_row'])) {
                    $total_piece_weighted += $it['hours'] * $it['coefficient'];
                }
            }
            if ($wage > 0 && $total_piece_weighted <= 0) {
                \exception('穿孔计件总工资(' . (string) $wage . '元)需至少一名未配置固定工资的人员参与分配，或请检查报工工时');
            }

            $wage_list = [];
            $class_bonus_total = 200;
            $reporterId = (int) user_id();
            $pieceCount = 0;
            foreach ($items as $it) {
                if (empty($it['global_row'])) {
                    $pieceCount++;
                }
            }
            $allocRunning = 0.0;
            $seenPiece = 0;

            foreach ($items as $it) {
                $user_info = $it['user_info'];
                $coefficient = $it['coefficient'];
                $hours = $it['hours'];
                $uid = (int) $user_info['user_id'];

                if (!empty($it['global_row'])) {
                    $person_wage = self::fixedWageByProcessRowAndHours($it['global_row'], $hours);
                } else {
                    if ($wage <= 0) {
                        $person_wage = 0.0;
                    } elseif ($total_piece_weighted <= 0) {
                        $person_wage = 0.0;
                    } else {
                        $seenPiece++;
                        if ($seenPiece === $pieceCount) {
                            $person_wage = round($wage - $allocRunning, 2);
                        } else {
                            $part = round($wage * ($hours * $coefficient) / $total_piece_weighted, 2);
                            $person_wage = $part;
                            $allocRunning += $part;
                        }
                    }
                }

                if ($uid === $reporterId) {
                    $bonus = round($class_bonus_total * ($hours / $default_work_hours), 2);
                    $person_wage += $bonus;
                }

                $person_wage = round((float) $person_wage, 2);
                $hourly_wage = $hours > 0 ? round($person_wage / $hours, 2) : 0;

                $wage_list[] = [
                    'user_id' => $uid,
                    'relation_code' => $param['report_code'],
                    'material_id' => $english_material['english_material'],
                    'wage' => $person_wage,
                    'report_num' => $report_num,
                    'wage_sum' => $wage,
                    'create_time' => datetime(),
                    'create_uid' => user_id(),
                    'device_code' => $param['device_code'] ?? '',
                    'work_hours' => $hours,
                    'hourly_wage' => $hourly_wage,
                    'coefficient' => $coefficient,
                ];
            }

            self::addWage($wage_list);
        } elseif (strpos($processName, '球化') !== false) {
            $pc = (string) ($process['processes_code'] ?? '');
            $cl = (int) ($param['class'] ?? 0);
            $wageDate = self::wageEffectiveDateFromParam($param);
            if ($pc === '') {
                \exception('球化工序缺少工序编码');
            }
            $user = new UserModel();
            if (!empty($param['product_user']) && is_array($param['product_user'])) {
                $uids = [];
                foreach ($param['product_user'] as $nickname) {
                    $nickname = (string) $nickname;
                    $u = $user->where('nickname', $nickname)->find();
                    if (empty($u)) {
                        \exception('找不到姓名为:' . $nickname . '的员工信息');
                    }
                    $uids[] = (int) $u['user_id'];
                }
                self::revokePriorWageDetailsForShift($uids, $wageDate, $pc, $cl, (string)($param['relation_code'] ?? ''));

                $wageList = [];
                foreach ($param['product_user'] as $nickname) {
                    $nickname = (string) $nickname;
                    $u = $user->where('nickname', $nickname)->find();
                    $uid = (int) $u['user_id'];
                    $row = self::findGlobalFixedWageRowForUser($uid);
                    if (empty($row)) {
                        if (self::ALLOW_REPORT_WITHOUT_PROCESS_WAGE_ROW) {
                            continue;
                        }
                        \exception('球化工序未配置该员工的固定工资（固定工资表）：' . $nickname);
                    }
                    $hours = self::resolveReportWorkHours($param, $nickname);
                    $dayWage = self::fixedWageByProcessRowAndHours($row, $hours);
                    $wageList[] = [
                        'user_id' => $uid,
                        'relation_code' => $param['report_code'],
                        'material_id' => $english_material['english_material'],
                        'wage' => $dayWage,
                        'report_num' => $report_num,
                        'wage_sum' => $dayWage,
                        'create_time' => datetime(),
                        'create_uid' => user_id(),
                        'device_code' => $param['device_code'] ?? '',
                    ];
                }
                if (!empty($wageList)) {
                    self::addWage($wageList);
                }
            }
        } elseif ($process['processes_name'] == '矫直') {
            $pc = (string) ($process['processes_code'] ?? '');
            $cl = (int) ($param['class'] ?? 0);
            $wageDate = self::wageEffectiveDateFromParam($param);
            $reporterId = (int) user_id();
            self::revokePriorWageDetailsForShift([$reporterId], $wageDate, $pc, $cl, (string)($param['relation_code'] ?? ''));

            $full = self::DEFAULT_WORK_HOURS;
            $reporterNickname = (string) user_name($reporterId);
            $hours = self::resolveReportWorkHours($param, $reporterNickname, $full);
            $globalRow = self::findGlobalFixedWageRowForUser($reporterId);
            if (!empty($globalRow)) {
                $wage = round(self::fixedWageByProcessRowAndHours($globalRow, $hours), 2);
            } else {
                $ratio = $full > 0 ? ($hours / $full) : 1.0;
                $piece = 5.0 * (float) $report_num * $ratio;
                $leaderAllowance = 0.0;
                if (PublicService::is_class($reporterId) || (int) $is_replace === 1) {
                    $leaderAllowance = 200.0 * $ratio;
                }
                $wage = round($piece + $leaderAllowance, 2);
            }
            $add_field = [
                [
                    'user_id' => $reporterId,
                    'relation_code' => $param['report_code'],
                    'material_id' => $english_material['english_material'],
                    'wage' => $wage,
                    'report_num' => $report_num,
                    'wage_sum' => $wage,
                    'create_time' => datetime(),
                    'create_uid' => user_id(),
                    'device_code' => $param['device_code'],
                ]
            ];
            self::addWage($add_field);
        } elseif (strpos($processName, '酸洗') !== false) {
            /* 酸洗：固定工资表按人全局一行；有固定则按日/月薪折当日工时；无固定则参与 7元/吨 产量池按工时分配 */
            if (empty($param['device_code'])) {
                $param['device_code'] = 'device-2025102915';
            }
            $user = new UserModel();
            $processCode = (string) ($process['processes_code'] ?? '');
            if ($processCode === '') {
                \exception('酸洗工序缺少工序编码');
            }
            if (empty($param['product_user']) || !is_array($param['product_user'])) {
                return true;
            }
            $cl = (int) ($param['class'] ?? 0);
            $wageDate = self::wageEffectiveDateFromParam($param);
            $uidsForRevoke = [];
            foreach ($param['product_user'] as $nn) {
                $nn = (string) $nn;
                $uu = $user->where('nickname', $nn)->find();
                if (!empty($uu)) {
                    $uidsForRevoke[] = (int) $uu['user_id'];
                }
            }
            self::revokePriorWageDetailsForShift($uidsForRevoke, $wageDate, $processCode, $cl, (string)($param['relation_code'] ?? ''));

            $tonTotal = round(self::ACID_WASH_WAGE_PER_TON * (float) $report_num, 2);
            $participants = [];
            foreach ($param['product_user'] as $nickname) {
                $nickname = (string) $nickname;
                $u = $user->where('nickname', $nickname)->find();
                if (empty($u)) {
                    \exception('找不到姓名为:' . $nickname . '的员工信息');
                }
                $uid = (int) $u['user_id'];
                $hours = self::resolveReportWorkHours($param, $nickname);
                $fixedRow = self::findGlobalFixedWageRowForUser($uid);
                $participants[] = [
                    'nickname' => $nickname,
                    'user_id' => $uid,
                    'hours' => $hours,
                    'fixed_row' => $fixedRow,
                ];
            }
            $pieceWeighted = 0.0;
            foreach ($participants as $p) {
                if (empty($p['fixed_row'])) {
                    $pieceWeighted += (float) $p['hours'];
                }
            }
            if ($tonTotal > 0 && $pieceWeighted <= 0) {
                \exception('酸洗产量工资(' . (string) $tonTotal . '元)需至少一名未配置固定工资的人员参与分配，或请检查报工工时');
            }
            $pieceList = [];
            foreach ($participants as $p) {
                if (empty($p['fixed_row'])) {
                    $pieceList[] = $p;
                }
            }
            if ($tonTotal > 0 && empty($pieceList)) {
                \exception('酸洗产量工资(' . (string) $tonTotal . '元)需至少一名未配置固定工资的人员参与分配');
            }
            $pieceWageByUserId = [];
            $np = count($pieceList);
            $allocRunning = 0.0;
            foreach ($pieceList as $j => $pp) {
                if ($tonTotal <= 0) {
                    $pieceWageByUserId[$pp['user_id']] = 0.0;
                    continue;
                }
                if ($j === $np - 1) {
                    $pieceWageByUserId[$pp['user_id']] = round($tonTotal - $allocRunning, 2);
                } else {
                    $wPart = round($tonTotal * (float) $pp['hours'] / $pieceWeighted, 2);
                    $pieceWageByUserId[$pp['user_id']] = $wPart;
                    $allocRunning += $wPart;
                }
            }
            $wageList = [];
            foreach ($participants as $p) {
                if (!empty($p['fixed_row'])) {
                    $w = self::fixedWageByProcessRowAndHours($p['fixed_row'], (float) $p['hours']);
                } else {
                    $w = $pieceWageByUserId[$p['user_id']] ?? 0.0;
                }
                $wageList[] = [
                    'user_id' => $p['user_id'],
                    'relation_code' => $param['report_code'],
                    'material_id' => $english_material['english_material'],
                    'wage' => $w,
                    'report_num' => $report_num,
                    'wage_sum' => $w,
                    'create_time' => datetime(),
                    'create_uid' => user_id(),
                    'device_code' => $param['device_code'] ?? '',
                ];
            }
            if (!empty($wageList)) {
                self::addWage($wageList);
            }
        }
        return true;
    }
    public static function getPower($device_name)
    {
        try {
            $device_url = 'https://gaochidevice.xnssoft.com/analysis/api/getDeviceList/';

            $device_list = @PublicService::Get($device_url);

            if (empty($device_list) || !is_array($device_list)) {
                throw new \Exception('设备API服务暂时不可用，请使用手动报工方式');
            }

            $device_id = 0;
            foreach ($device_list as $item) {
                if ($device_name == $item['name']) {
                    $device_id = $item['id'];
                    break;
                }
            }

            if ($device_id == 0) {
                return 0;
            }

            $now = new \DateTime('now', new \DateTimeZone('Asia/Shanghai'));
            $currentHour = (int)$now->format('H');
            $today = $now->format('Y-m-d');

            if ($currentHour >= 7 && $currentHour < 19) {
                $currentClass = '白班';
                $startTime = new \DateTime('today 07:00:00', new \DateTimeZone('Asia/Shanghai'));
                $previousClass = '夜班';
            } else {
                $currentClass = '夜班';
                if ($currentHour >= 19) {
                    $startTime = new \DateTime('today 19:00:00', new \DateTimeZone('Asia/Shanghai'));
                    $previousClass = '白班';
                } else {
                    $startTime = new \DateTime('yesterday 19:00:00', new \DateTimeZone('Asia/Shanghai'));
                    $previousClass = '白班';
                }
            }

            $model = new PowerModel();

            $todayRecord = $model->where('device_name', $device_name)
                ->where('date', $today)
                ->where('class', $currentClass)
                ->find();

            if ($todayRecord && !empty($todayRecord['create_time'])) {
                $previousRecord = $model->where('device_name', $device_name)
                    ->where(function ($query) use ($currentClass, $today) {
                        if ($currentClass == '白班') {
                            $query->where('class', '夜班')
                                ->where('date', '<', $today);
                        } else {
                            $query->where('class', '白班')
                                ->where('date', $today);
                        }
                    })
                    ->order('create_time', 'desc')
                    ->find();

                if ($previousRecord && !empty($previousRecord['create_time'])) {
                    $queryStart = new \DateTime($previousRecord['create_time'], new \DateTimeZone('Asia/Shanghai'));
                } else {
                    $queryStart = clone $startTime;
                }
            } else {
                $lastRecord = $model->where('device_name', $device_name)
                    ->order('create_time', 'desc')
                    ->find();

                if ($lastRecord && !empty($lastRecord['create_time'])) {
                    $queryStart = new \DateTime($lastRecord['create_time'], new \DateTimeZone('Asia/Shanghai'));
                } else {
                    $queryStart = clone $startTime;
                }
            }

            if ($queryStart > $now) {
                $queryStart = clone $now;
            }

            $queryStartUTC = clone $queryStart;
            $queryStartUTC->setTimezone(new \DateTimeZone('UTC'));
            $nowUTC = clone $now;
            $nowUTC->setTimezone(new \DateTimeZone('UTC'));

            $dateRange = $queryStartUTC->format('Y-m-d\TH:i:s.v\Z') . ',' . $nowUTC->format('Y-m-d\TH:i:s.v\Z');
            $url = 'https://gaochidevice.xnssoft.com/analysis/api/getGasConsumption?actionTime=' . $dateRange;

            $result = @PublicService::Get($url);

            if (empty($result) || !is_array($result)) {
                throw new \Exception('能耗数据API服务暂时不可用');
            }

            $consumption = 0;
            foreach ($result as $item) {
                if ($device_id == $item['device_id']) {
                    $consumption += $item['consumption'];
                }
            }

            $historySum = $model->where('device_name', $device_name)
                ->where('date', '<', $today)
                ->sum('power') ?: 0;

            $todayOtherSum = 0;
            if ($todayRecord) {
                $todayOtherSum = $model->where('device_name', $device_name)
                    ->where('date', $today)
                    ->where('id', '<>', $todayRecord['id'])
                    ->sum('power') ?: 0;
            } else {
                $todayOtherSum = $model->where('device_name', $device_name)
                    ->where('date', $today)
                    ->sum('power') ?: 0;
            }

            $recordData = [
                'device_name' => $device_name,
                'power' => $consumption,
                'date' => $today,
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => user_id(),
                'class' => $currentClass,
            ];
            if (empty($todayRecord)) {
                $model->insert($recordData);
            } else {
                $recordData['update_time'] = date('Y-m-d H:i:s');
                $recordData['update_uid'] = user_id();
                $model->where('id', $todayRecord['id'])
                    ->update($recordData);
            }

            $total = $historySum + $todayOtherSum + $consumption;
            return number_format($total, 2, '.', '');
        } catch (\Exception $e) {
            error_log('获取设备能耗失败 [' . $device_name . ']: ' . $e->getMessage());

            throw new \Exception('自动获取能耗数据失败：' . $e->getMessage() . '。建议使用手动报工方式（reportpower接口）');
        }
    }

    /**
     * 统计指定日期范围内的能耗吨数（与抄表列表同一套日期、工序筛选逻辑）
     *
     * @param string $startDate     起始日期（为空则不限）
     * @param string $endDate       结束日期（为空则不限）
     * @param string $processesName 工序名称关键字（如「酸洗」），与 powerlist 中按工序筛设备一致；空则不限设备
     *
     * @return array
     */
    public static function getPowerConsumptionStats($startDate = '', $endDate = '', $processesName = '')
    {
        $wheres = ['is_delete = 0'];
        $bindings = [];

        if (!empty($startDate)) {
            $wheres[] = 'create_time >= ?';
            $bindings[] = $startDate . ' 00:00:00';
        }
        if (!empty($endDate)) {
            $wheres[] = 'create_time <= ?';
            $bindings[] = $endDate . ' 23:59:59';
        }

        if (!empty($processesName)) {
            $processModel = new ProcessesModel();
            $processCodes = $processModel
                ->where('processes_name', 'like', '%' . $processesName . '%')
                ->column('processes_code');
            if (empty($processCodes)) {
                return [
                    'phosphating_total' => 0,
                    'saponification_total' => 0,
                    'alkali_total' => 0,
                    'date_range' => [
                        'start_date' => $startDate ?: '不限',
                        'end_date' => $endDate ?: '不限',
                    ],
                ];
            }
            $deviceModel = new DeviceModel();
            $deviceCodes = $deviceModel
                ->whereIn('processes_code', $processCodes)
                ->where('is_delete', 0)
                ->column('device_code');
            if (empty($deviceCodes)) {
                return [
                    'phosphating_total' => 0,
                    'saponification_total' => 0,
                    'alkali_total' => 0,
                    'date_range' => [
                        'start_date' => $startDate ?: '不限',
                        'end_date' => $endDate ?: '不限',
                    ],
                ];
            }
            $placeholders = implode(',', array_fill(0, count($deviceCodes), '?'));
            $wheres[] = 'device_code IN (' . $placeholders . ')';
            $bindings = array_merge($bindings, $deviceCodes);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $wheres);

        $sql = "SELECT 
                    COALESCE(SUM(phosphating), 0) as phosphating_total,
                    COALESCE(SUM(saponification), 0) as saponification_total,
                    COALESCE(SUM(alkali), 0) as alkali_total
                FROM ya_product_power
                {$whereSql}";

        $stats = Db::query($sql, $bindings);

        if (!empty($stats) && isset($stats[0])) {
            $result = [
                'phosphating_total' => round((float) $stats[0]['phosphating_total'], 3),
                'saponification_total' => round((float) $stats[0]['saponification_total'], 3),
                'alkali_total' => round((float) $stats[0]['alkali_total'], 3),
            ];
        } else {
            $result = [
                'phosphating_total' => 0,
                'saponification_total' => 0,
                'alkali_total' => 0,
            ];
        }

        $result['date_range'] = [
            'start_date' => $startDate ?: '不限',
            'end_date' => $endDate ?: '不限',
        ];

        return $result;
    }

    /**
     * 按未删除的报工记录重算生产任务上的工序累加（report JSON）、当前工序数量与状态。
     * 与 {@see report()} 写入逻辑一致：report 按 processes_code 汇总 report_num / defective_num。
     */
    public static function rebuildTaskReportRollup(string $taskCode, $materialId): void
    {
        if ($taskCode === '' || $materialId === '') {
            return;
        }
        $taskModel = new ProductTaskModel();
        $taskRow = $taskModel->where('task_code', $taskCode)->where('material_id', $materialId)->find();
        if (empty($taskRow)) {
            return;
        }
        $taskArr = is_array($taskRow) ? $taskRow : $taskRow->toArray();

        $agg = Db::name('product_report')
            ->where('relation_code', $taskCode)
            ->where('material_id', $materialId)
            ->where('is_delete', 0)
            ->field('processes_code, SUM(report_num) AS report_num, SUM(defective_num) AS defective_num')
            ->group('processes_code')
            ->select();
        if (!is_array($agg)) {
            $agg = [];
        }

        $report = [];
        foreach ($agg as $row) {
            $pc = (string)($row['processes_code'] ?? '');
            if ($pc === '') {
                continue;
            }
            $report[$pc] = [
                'report_num' => round((float)($row['report_num'] ?? 0), 3),
                'defective_num' => round((float)($row['defective_num'] ?? 0), 3),
            ];
        }

        $last = Db::name('product_report')
            ->where('relation_code', $taskCode)
            ->where('material_id', $materialId)
            ->where('is_delete', 0)
            ->order('id', 'desc')
            ->find();

        $reportPayload = empty($report) ? new \stdClass() : $report;
        if (!empty($last)) {
            $upd = [
                'report' => json_encode($reportPayload, JSON_UNESCAPED_UNICODE),
                'report_num' => (float)($last['report_num'] ?? 0),
                'defective_num' => (float)($last['defective_num'] ?? 0),
                'process_code' => (string)($last['processes_code'] ?? ''),
            ];
        } else {
            $upd = [
                'report' => json_encode($reportPayload, JSON_UNESCAPED_UNICODE),
                'report_num' => 0,
                'defective_num' => 0,
                'process_code' => '',
            ];
        }

        $taskModel->where('task_code', $taskCode)->where('material_id', $materialId)->update($upd);

        $latest = $taskModel->where('task_code', $taskCode)->where('material_id', $materialId)->find();
        $latestArr = empty($latest) ? $taskArr : (is_array($latest) ? $latest : $latest->toArray());
        $newStatus = self::calcTaskStatus($latestArr, $taskCode);
        $taskModel->where('task_code', $taskCode)->where('material_id', $materialId)->update(['status' => $newStatus]);
    }

    /**
     * 计算工单状态：穿孔/球化/矫直/酸洗报工均 >= 计划×97% 且 <= 计划则自动完成(2)，否则生产中(1)。
     * [AI-2026-08] 断钢不参与完成门槛（合并报工后不宜按单卡断钢）；与盘点折算无关。
     */
    private static function calcTaskStatus(array $taskData, string $taskCode): int
    {
        $taskNum = (float)($taskData['task_num'] ?? 0);
        if ($taskNum <= 0) {
            return 1;
        }
        $threshold = round($taskNum * ProductionConfig::PLAN_COMPLETE_RATIO, 3);
        $routingCode = $taskData['routing_code'] ?? '';
        if (empty($routingCode)) {
            return 1;
        }
        $detailModel = new RoutingDetailModel();
        $routeProcessCodes = $detailModel->where('routing_code', $routingCode)
            ->where('is_delete', 0)
            ->column('process_code');
        if (empty($routeProcessCodes)) {
            return 1;
        }
        $routeSet = [];
        foreach ($routeProcessCodes as $code) {
            $routeSet[(string) $code] = true;
        }
        $requiredCodes = [];
        foreach (ProductionConfig::planCompleteProcessNames() as $processName) {
            $code = RoutingProcessHelper::getProcessCodeByName($processName);
            if ($code !== '' && isset($routeSet[$code])) {
                $requiredCodes[] = $code;
            }
        }
        if ($requiredCodes === []) {
            return 1;
        }
        $taskModel = new ProductTaskModel();
        $latestTask = $taskModel->where('task_code', $taskCode)
            ->where('material_id', $taskData['material_id'] ?? '')
            ->find();
        if (empty($latestTask) || empty($latestTask['report'])) {
            return 1;
        }
        $report = json_decode($latestTask['report'], true);
        if (!is_array($report)) {
            return 1;
        }
        foreach ($requiredCodes as $pc) {
            $row = isset($report[$pc]) && is_array($report[$pc]) ? $report[$pc] : [];
            $rn = (float)($row['report_num'] ?? 0);
            if ($rn < $threshold || $rn > $taskNum) {
                return 1;
            }
        }
        return 2;
    }

    /**
     * 报工/抄表归属日期（12:00 为界，与夜班白班统计一致）：
     * 例如 6月5日 11:00 提交 → 归属 6月4日；6月5日 13:00 提交 → 归属 6月5日。
     * 对应区间：昨日 12:00 ～ 今日 12:00 的录入 → 归属「昨天」那一天。
     */
    public static function calcBusinessReportDate(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();

        return date('Y-m-d', $ts - 12 * 3600);
    }

    /**
     * 大屏「昨日」能耗：中午 12 点前仍看当前归属日，12 点后看上一归属日
     */
    public static function calcCockpitYesterdayReportDate(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $businessToday = self::calcBusinessReportDate($ts);
        if ((int) date('G', $ts) < 12) {
            return $businessToday;
        }

        return date('Y-m-d', strtotime($businessToday . ' -1 day'));
    }

    private static function calcReportDate(): string
    {
        return self::calcBusinessReportDate();
    }
}
