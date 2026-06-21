<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\admin\controller\material\UserSettlement;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProductionDetailModel;
use app\common\model\admin\ProductionModel;
use app\common\model\admin\ProductTaskModel;
use app\common\model\admin\RoutingDetailModel;
use app\common\model\admin\WmsMaterialModel;
use app\common\model\admin\StockModel;
use app\common\model\wechat\UserModel;
use app\common\service\utils\MaterialSearchHelper;
use app\common\service\wechat\Exception;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class ProductionService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'production_plan_name' => '', //调试前物料编号
        'production_plan_code' => '', //
        'routing_code' => '',
        'english_material' => '',
        'materials' => '',
        'punch_devices/a' => [],
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'production_plan_name' => '', //调试前物料编号
        'production_plan_code' => '', //
        'username' => '',
        'reference' => '',
        'yesterday_money' => '',
        'month_money' => '',
        'create_start_date' => '',
        'create_end_date' => '',
        'material_spec' => '',      // 物料规格
        'material_model' => '',     // 物料型号
        'y_mateial_spec' => '',      // 原物料规格（历史拼写，保留兼容）
        'y_mateial_model' => '',     // 原物料型号（历史拼写，保留兼容）
        'y_material_spec' => '',     // 圆钢规格（标准命名）
        'y_material_model' => '',    // 圆钢型号（标准命名）
        'plan_finish' => ''         // 计划是否已全部完工：0 进行中 1 已完成（与小程序工单完成逻辑一致）
    ];

    /**
     * 列表
     *
     * @param array  $where 条件
     * @param int    $page  页数
     * @param int    $limit 数量
     * @param array  $order 排序
     * @param string $field 字段
     * @param array  $listOptions 列表展示选项（yMaterialSpec/yMaterialModel：仅展示命中检索的圆钢）
     *
     * @return array
     */
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [], array $listOptions = [])
    {
        $model = new ProductionModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }
        if ($page == 0 || $limit == 0) {
            return $model->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $yFilterSpec = (string) ($listOptions['yMaterialSpec'] ?? '');
        $yFilterModel = (string) ($listOptions['yMaterialModel'] ?? '');
        $list = $model->with(['getMaterial'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
        $punchDeviceMap = ProductionPunchDeviceService::listByPlanCodes(array_column($list, 'production_plan_code'));
        foreach ($list as $k => &$v) {
            $v['plan_num'] = $model->getPlanWithJoinStats($v['production_plan_code']);
            $engId = (int) ($v['english_material'] ?? 0);
            /*增加详情物料的*/
            $content = Db::name('production_detail')
                ->alias('a')
                ->where('production_plan_code', $v['production_plan_code'])
                ->where('a.is_delete', '=', '0')
                ->leftJoin('material b', 'b.id=a.material_id')
                ->select()
                ->toArray();
            /*循环取出规格和型号来（排除成品；有检索时仅展示命中的圆钢行）*/
            $specParts = [];
            $modelParts = [];
            foreach ($content as $k2 => $v2) {
                $mid = (int) ($v2['material_id'] ?? 0);
                if ($engId > 0 && $mid === $engId) {
                    continue;
                }
                $rowSpec = (string) ($v2['material_spec'] ?? '');
                $rowModel = (string) ($v2['material_model'] ?? '');
                if (
                    ($yFilterSpec !== '' || $yFilterModel !== '')
                    && !MaterialSearchHelper::materialMatchesSpecModel($rowSpec, $rowModel, $yFilterSpec, $yFilterModel)
                ) {
                    continue;
                }
                if ($rowSpec !== '') {
                    $specParts[] = $rowSpec;
                }
                if ($rowModel !== '') {
                    $modelParts[] = $rowModel;
                }
            }
            $v['y_mateial_spec'] = implode(',', $specParts);
            $v['y_mateial_model'] = implode(',', $modelParts);
            /* [AI-2026-06] 列表展示穿孔设备 */
            $punchDevices = $punchDeviceMap[(string) ($v['production_plan_code'] ?? '')] ?? [];
            $v['punch_devices'] = $punchDevices;
            $nameParts = [];
            foreach ($punchDevices as $devRow) {
                $devName = trim((string) ($devRow['device_name'] ?? ''));
                $devCode = trim((string) ($devRow['device_code'] ?? ''));
                if ($devName !== '') {
                    $nameParts[] = $devName;
                } elseif ($devCode !== '') {
                    $nameParts[] = $devCode;
                }
            }
            $v['punch_device_names'] = implode('、', $nameParts);
        }
        /*为列表添加采购前的数据*/
        return compact('count', 'pages', 'page', 'limit', 'list');
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
            $model = new ProductionModel();
            $info = $model->with(['getMaterial'])->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的信息不存在');
                }
                return [];
            }
            /*查询生产计划的详情*/
            $content = Db::name('production_detail')
                ->alias('a')
                ->where('production_plan_code', $info['production_plan_code'])
                ->leftJoin('material b', 'b.id=a.material_id')
                ->select()
                ->toArray();
            $info['content'] = $content;
            $info['punch_devices'] = ProductionPunchDeviceService::listByPlanCode($info['production_plan_code']);
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
            /*测试数据*/
//            $param['content'] = [
//                [
//                'material_id'=>861,
//                'plan_num'=>1,
//                'remark'=>'测试',
//            ],[
//                'material_id'=>860,
//                    'plan_num'=>1,
//                    'remark'=>'<UNK>',
//                ]
//            ];

            /*选择成品物料*/
            /*创建生产计划*/
            $model = new ProductionModel();
            /*生成计划编号*/
            $param['production_plan_code'] = PublicService::getCode(3)['code'];
            $pk = $model->getPk();
            /*创建生产计划详情*/
            $arr = [];
            foreach ($param['content'] as $key => &$value) {
                $value['production_plan_code'] = $param['production_plan_code'];
                if ($value['plan_num'] != 0) {
                    $value['create_time'] = datetime();
                    $value['create_uid'] = user_id();
                    $value['task_num'] = $value['plan_num'];
                    $value['push_num'] = 0;
                    Db::name('production_detail')->save($value);
                }
                /*将需要的字段取出来 创建生产任务*/
                array_push($arr, $value);
            }
            /*创建生产计划*/
            $add_field = [
                'production_plan_code' => $param['production_plan_code'],
                'create_time' => date('Y-m-d H:i:s'),
                'create_uid' => user_id(),
                'production_plan_name' => $param['production_plan_name'],
                'routing_code' => $param['routing_code'], //生产工艺编码
                'english_material' => $param['english_material'],
            ];
            /*保存生产计划*/
            $id = $model->save($add_field);
            $param[$pk] = $id;

            /* [AI-2026-06] 穿孔设备（1~2台，建计划时指定） */
            $punchDevices = ProductionPunchDeviceService::validateDevices(
                (string) $param['routing_code'],
                $param['punch_devices'] ?? []
            );
            ProductionPunchDeviceService::saveForPlan($param['production_plan_code'], $punchDevices);

            /*自动下推生产计划*/
            $push_field = [
                'production_plan_code' => $param['production_plan_code'],
                'routing_code' => $param['routing_code'],
                'content' => $arr,
            ];
            /* [AI-2026-06] 外层事务内下推，合并池重建延后到 commit 后 */
            self::push($push_field, false);
            Db::commit();
            /* [AI-2026-06] 合并池全量重建耗时大，响应返回后再执行 */
            DuangangMergeService::scheduleRebuildAll();
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
//        $param['materials'] = [
//            [
//                'material_id'=>864,
//                'plan_num'=>71,
//            ]
//        ];

//        return $param;
        $model = new ProductionModel();
        $pk = $model->getPk();

        unset($param[$pk], $param['ids']);

        $materials = isset($param['materials']) && is_array($param['materials']) ? $param['materials'] : null;
        unset($param['materials']);

        $punchDevicesParam = null;
        if (array_key_exists('punch_devices', $param)) {
            $punchDevicesParam = $param['punch_devices'];
            unset($param['punch_devices']);
        }

        $param['update_uid']  = user_id();
        $param['update_time'] = datetime();

        if ($model->where($pk, 'in', $ids)->count() < 1) {
            exception('生产计划记录不存在');
        }
        $res = $model->where($pk, 'in', $ids)->update($param);

        $planCode = $param['production_plan_code'] ?? '';
        if ($materials !== null && $planCode !== '') {
            self::syncProductionPlanMaterials($planCode, $materials);
        }

        $task_model = new ProductTaskModel();
        if (!empty($param['routing_code'])) {
            $task_model->where('relation_code', $param['production_plan_code'])->update(['routing_code' => $param['routing_code']]);
        }
        if ($res === false) {
            exception('更新生产计划失败');
        }

        if ($punchDevicesParam !== null) {
            $planRow = $model->where($pk, 'in', $ids)->find();
            $planCode = (string) ($param['production_plan_code'] ?? ($planRow['production_plan_code'] ?? ''));
            $routingCode = (string) ($param['routing_code'] ?? ($planRow['routing_code'] ?? ''));
            if ($planCode !== '') {
                $devices = ProductionPunchDeviceService::validateDevices($routingCode, is_array($punchDevicesParam) ? $punchDevicesParam : []);
                ProductionPunchDeviceService::saveForPlan($planCode, $devices);
                ProductionPunchDeviceService::syncToTasks($planCode);
                // 穿孔设备变更不影响断钢合并池，勿在此处 rebuildAll（远程库会超时拖垮本地 PHP）
            }
        }

        $param['ids'] = $ids;


        return $param;
    }

    /**
     * 同步生产计划圆钢/原料明细：支持更换 material_id、数量、备注；已下推行不删除，仅改料号时同步生产任务 material_id
     *
     * @param string $planCode
     * @param array  $materials
     */
    private static function syncProductionPlanMaterials(string $planCode, array $materials): void
    {
        $detail = new ProductionDetailModel();
        $taskModel = new ProductTaskModel();

        $submitted = [];
        foreach ($materials as $value) {
            $mid = (string)($value['material_id'] ?? '');
            if ($mid === '') {
                continue;
            }
            $submitted[] = [
                'material_id' => $mid,
                'plan_num' => intval($value['plan_num'] ?? 0),
                'remark' => $value['remark'] ?? '',
            ];
        }
        if (empty($submitted)) {
            return;
        }

        $submittedIds = array_column($submitted, 'material_id');

        foreach ($submitted as $item) {
            $mid = $item['material_id'];
            $row = $detail->where('production_plan_code', $planCode)
                ->where('material_id', $mid)
                ->find();

            if (!empty($row)) {
                $detail->where('id', $row['id'])->update([
                    'plan_num' => $item['plan_num'],
                    'task_num' => $item['plan_num'],
                    'remark' => $item['remark'],
                    'update_time' => datetime(),
                    'update_uid' => user_id(),
                ]);
                // 同步更新生产工单的计划数量
                $taskModel->where('relation_code', $planCode)
                    ->where('material_id', $mid)
                    ->update(['task_num' => $item['plan_num']]);
                continue;
            }

            $orphan = $detail->where('production_plan_code', $planCode)
                ->whereNotIn('material_id', $submittedIds)
                ->order('id', 'asc')
                ->find();

            if (!empty($orphan)) {
                $oldMid = (string)($orphan['material_id'] ?? '');
                if ($oldMid !== '' && $oldMid !== $mid) {
                    $taskModel->where('relation_code', $planCode)
                        ->where('material_id', $oldMid)
                        ->update(['material_id' => $mid, 'task_num' => $item['plan_num']]);
                } else {
                    $taskModel->where('relation_code', $planCode)
                        ->where('material_id', $mid)
                        ->update(['task_num' => $item['plan_num']]);
                }
                $detail->where('id', $orphan['id'])->update([
                    'material_id' => $mid,
                    'plan_num' => $item['plan_num'],
                    'task_num' => $item['plan_num'],
                    'remark' => $item['remark'],
                    'update_time' => datetime(),
                    'update_uid' => user_id(),
                ]);
                continue;
            }

            Db::name('production_detail')->insert([
                'production_plan_code' => $planCode,
                'material_id' => $mid,
                'is_delete' => 0,
                'plan_num' => $item['plan_num'],
                'remark' => $item['remark'],
                'push_num' => 0,
                'create_uid' => user_id(),
                'create_time' => datetime(),
            ]);
        }

        $detail->where('production_plan_code', $planCode)
            ->whereNotIn('material_id', $submittedIds)
            ->where('push_num', 0)
            ->delete();
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
        $model = new ProductionModel();
        $pk = $model->getPk();
        if ($real) {
            /*查询当前数据*/
            $result = $model->where($pk, 'in', $ids)->select()->toArray();
            if (empty($result)) {
                \exception('当前计划数据为空');
            }
            $res = $model->where($pk, 'in', $ids)->delete();
            /*将生产工单同步删除*/
            $task_model = new ProductTaskModel();
            foreach ($result as $item) {
                $task_model->where('relation_code', '=', $item['production_plan_code'])->delete();

                /*删除关联的领料单*/
                $receiving_model = new \app\common\model\admin\ReceivingModel();
                $receiving_model->where('relation_code', '=', $item['production_plan_code'])->delete();
            }
        } else {
            $update = delete_update();
            $res = $model->where($pk, 'in', $ids)->update($update);
        }
        if (empty($res)) {
            exception();
        }
        if ($real) {
            return ['ids' => $ids];
        } else {
            $update['ids'] = $ids;
            return $update;
        }
    }
    /**
     * 下推生产计划
     */
    public static function push($param, bool $rebuildMergePool = true)
    {
        $startedHere = !Db::connect()->getPdo()->inTransaction();
        if ($startedHere) {
            Db::startTrans();
        }
        try {
            $model = new RoutingDetailModel();
            $production_model = new ProductionModel();

            /*对数据进行校验*/
            $materials = [];

            /*根据生产计划获取工艺编码*/
            $production_data = $production_model->where('production_plan_code', $param['production_plan_code'])->find();
            if (empty($production_data)) {
                \exception('生产计划不存在');
            }
            $param['routing_code'] = $production_data['routing_code'];

            /*校验工艺编码是否存在*/
            if (empty($param['routing_code'])) {
                \exception("请填写工艺编码");
            }

            foreach ($param['content'] as $value) {
                /*下推数量为0直接跳过*/
                if (intval($value['task_num']) == 0) {
                    continue;
                }

                /*从数据库查询最新的计划数量和已下推数量*/
                $detail = Db::name('production_detail')
                    ->where('production_plan_code', $value['production_plan_code'])
                    ->where('material_id', $value['material_id'])
                    ->find();

                if (empty($detail)) {
                    \exception('物料详情不存在');
                }

                /*校验下推数量是否超过计划数量*/
                if (intval($value['task_num']) + intval($detail['push_num']) > intval($detail['plan_num'])) {
                    \exception('下推数量已经超过计划数量');
                }

                /*根据工艺编码获取第一道工序编码*/
                $processes = $model->where('routing_code', $param['routing_code'])
                    ->where('sort', 1)
                    ->find();
                if (empty($processes)) {
                    \exception("工艺信息不存在");
                }

                /**
                 * 生产任务---直接派发到工序中
                 * 选择工艺----按照顺序进行派工 第一道工序---第二道工序--第三道工序
                 * 创建生产任务时要记录工序编码，工艺编码  ---报工----流转到同工艺下一道工序
                 *
                 */
                $content = [
                    'material_id' => $value['material_id'],
                    'task_num' => $value['task_num'],
                    'process_code' => $processes['process_code'],
                ];
                array_push($materials, $content);

                /*修改物料已经下推的数量 - 使用数据库增量更新*/
                Db::name('production_detail')
                    ->where('production_plan_code', $value['production_plan_code'])
                    ->where('material_id', $value['material_id'])
                    ->inc('push_num', intval($value['task_num']))
                    ->update();
            }

            /*校验是否有可下推的物料*/
            if (empty($materials)) {
                \exception('没有可下推的物料，请检查下推数量是否大于0');
            }

            // 第317-324行附近
            /*创建生产任务---同步创建物料出库单*/
            $add_field = [
                'routing_code' => $param['routing_code'],
                'relation_code' => $param['production_plan_code'],
                'task_code' => '',
                'content' => $materials
            ];
            $result = ProductTaskService::add($add_field);

            // ⭐ 新增：自动生成领料单
            self::createMaterialReceiving($param['production_plan_code'], $materials);


            /*修改生产计划的下推状态*/
            $production_model->where('production_plan_code', $param['production_plan_code'])->update(['status' => 1]);
            if ($startedHere) {
                Db::commit();
                if ($rebuildMergePool) {
                    DuangangMergeService::scheduleRebuildAll();
                }
            }
        } catch (\Exception $e) {
            if ($startedHere) {
                Db::rollback();
            }
            exception('下推计划失败:' . $e->getMessage());
        }

        return $param;
    }

    /*获取某个计划的生产进度*/
    public static function getProcess($id)
    {
        $model = new ProductionModel();
        /*获取生产编号*/
        $data = $model->find($id);
        if (empty($data)) {
            exception('生产计划不存在');
        }

        /*统计关联单号的生产数据*/
        $task_model = new ProductTaskModel();
        $task_data = $task_model->with(['getYMaterial', 'getGyName'])->where('relation_code', $data['production_plan_code'])->select()->toArray();

        /*计算当前最后一道工序的值*/
        $sum = 0;
        $process = new ProcessesModel();
        foreach ($task_data as &$value) {
            /*判断工序是否最后一道工序*/
            if (!empty($value['process_code'])) {
                $process_data = $process->where('processes_code', $value['process_code'])->find();
                if (!empty($process_data) && $process_data['is_finnally'] == 1) {
                    $sum += $value['report_num'];
                }
            }
            $value['routing_code'] = $data['routing_code'];
        }

        /*将数据转换为数组*/
        $result = $data->toArray();

        if (!empty($task_data)) {
            $task_data[0]['report_num'] = $sum;
            /*确保 task 是索引数组，JSON 序列化时为 []*/
            $result['task'] = array_values([$task_data[0]]);
        } else {
            /*确保返回空数组而不是空对象*/
            $result['task'] = [];
        }

        /*查询报工记录*/
        return $result;
    }

    /**
     * 自动生成领料申请单
     * @param string $production_plan_code 生产计划编码
     * @param array $materials 物料清单
     */
    private static function createMaterialReceiving($production_plan_code, $materials)
    {
        $receivingModel = new \app\common\model\admin\ReceivingModel();

        foreach ($materials as $material) {
            $receivingData = [
                'receiving_code' => \app\common\service\admin\PublicService::getCode(9)['code'],
                'relation_code' => $production_plan_code,
                'device_code' => '',
                'material_id' => $material['material_id'],
                'material_num' => $material['task_num'],
                'status' => 0,
                'create_time' => datetime(),
                'create_uid' => user_id(),
                'remark' => '生产计划领料-' . $production_plan_code
            ];
            $receivingModel->insert($receivingData);
        }
    }

    /**
     * 工资按「工资明细日期 + 设备台账工序」汇总（与生产日报 getWageAmount 口径一致），
     * 用于报工未写 relation_code=report_code 或历史数据无报工关联时的兜底。
     *
     * @return array<string, array{total_wage: float, worker_count: int}>
     */
    private static function wageStatsByDeviceDateRange(string $startDate, string $endDate): array
    {
        $q = Db::name('user_wage_details')
            ->alias('a')
            ->leftJoin('device d', 'a.device_code = d.device_code')
            ->whereRaw('(a.is_delete IS NULL OR a.is_delete = 0)')
            ->where('d.is_delete', 0)
            ->where('a.device_code', '<>', '')
            ->whereNotNull('d.processes_code')
            ->where('d.processes_code', '<>', '')
            ->field('d.processes_code, SUM(a.wage) AS total_wage, COUNT(DISTINCT a.user_id) AS worker_count')
            ->group('d.processes_code');

        if ($startDate !== '' && $endDate !== '') {
            $q->whereRaw(
                'COALESCE(NULLIF(TRIM(a.date), \'\'), DATE(a.create_time)) BETWEEN ? AND ?',
                [$startDate, $endDate]
            );
        } elseif ($startDate !== '') {
            $q->whereRaw(
                'COALESCE(NULLIF(TRIM(a.date), \'\'), DATE(a.create_time)) >= ?',
                [$startDate]
            );
        } elseif ($endDate !== '') {
            $q->whereRaw(
                'COALESCE(NULLIF(TRIM(a.date), \'\'), DATE(a.create_time)) <= ?',
                [$endDate]
            );
        }

        try {
            $rows = $q->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $code = (string) ($row['processes_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = [
                'total_wage' => (float) ($row['total_wage'] ?? 0),
                'worker_count' => (int) ($row['worker_count'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 统计指定日期范围内的能耗吨数
     *
     * @param string $startDate 起始日期（为空则统计所有）
     * @param string $endDate 结束日期（为空则统计所有）
     * @return array
     */
    public static function getPowerConsumptionStats($startDate = '', $endDate = '')
    {
        $model = new \app\common\model\admin\ProductReportModel();

        // 构建查询条件
        $query = $model->alias('a')
            ->leftJoin('ya_processes b', 'a.processes_code = b.processes_code')
            ->leftJoin('ya_device c', 'JSON_UNQUOTE(JSON_EXTRACT(a.data, "$.device_name")) = c.device_name')
            ->field('
                b.processes_name,
                b.processes_code,
                SUM(a.report_num) as total_tonnage,
                SUM(a.defective_num) as total_defective,
                COUNT(DISTINCT a.relation_code) as task_count
            ')
            ->group('b.processes_code, b.processes_name')
            ->order('b.processes_code');

        // 添加日期范围条件
        if (!empty($startDate)) {
            $query->where('a.create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $query->where('a.create_time', '<=', $endDate . ' 23:59:59');
        }
        $query->where('a.is_delete', 0);

        $processStats = $query->select()->toArray();

        /*
         * 工序工资：按报工单 processes_code 汇总（与产量统计同一日期口径：报工 create_time）。
         * 不再依赖 ya_processes 二次关联，避免工序主数据缺失或编码不一致时整段工资被 whereNotNull 滤掉。
         * user_wage_details.relation_code 对应 product_report.report_code（见 ProductReportService::addWage）。
         */
        $wageModel = new \app\common\model\admin\UserWageDetailModel();
        $wageQuery = $wageModel->alias('a')
            ->join('ya_product_report b', 'a.relation_code = b.report_code')
            ->where('b.is_delete', 0)
            ->whereRaw('(a.is_delete IS NULL OR a.is_delete = 0)')
            ->field('
                b.processes_code,
                SUM(a.wage) as total_wage,
                COUNT(DISTINCT a.user_id) as worker_count
            ')
            ->where('b.processes_code', '<>', '')
            ->whereNotNull('b.processes_code')
            ->group('b.processes_code');

        if (!empty($startDate)) {
            $wageQuery->where('b.create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $wageQuery->where('b.create_time', '<=', $endDate . ' 23:59:59');
        }

        $wageStats = $wageQuery->select()->toArray();
        $wageByReport = [];
        foreach ($wageStats as $item) {
            $code = (string) ($item['processes_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $wageByReport[$code] = [
                'total_wage' => (float) ($item['total_wage'] ?? 0),
                'worker_count' => (int) ($item['worker_count'] ?? 0),
            ];
        }

        $wageByDevice = self::wageStatsByDeviceDateRange((string) $startDate, (string) $endDate);

        $wageMap = [];
        foreach ($processStats as $stat) {
            $code = (string) ($stat['processes_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $wr = $wageByReport[$code] ?? ['total_wage' => 0.0, 'worker_count' => 0];
            $wd = $wageByDevice[$code] ?? ['total_wage' => 0.0, 'worker_count' => 0];
            $preferReport = ($wr['total_wage'] > 0.000001 || $wr['worker_count'] > 0);
            $wageMap[$code] = $preferReport ? $wr : $wd;
        }

        // 获取每个工序的配件成本（从领料单统计）
        $receivingModel = new \app\common\model\admin\ReceivingModel();
        $accessoryQuery = $receivingModel->alias('a')
            ->leftJoin('ya_product_task b', 'a.relation_code = b.task_code')
            ->leftJoin('ya_routing_detail d', 'b.process_code = d.process_code')
            ->leftJoin('ya_processes c', 'd.process_code = c.processes_code')
            ->leftJoin('ya_material e', 'a.material_id = e.id')
            ->leftJoin('ya_wms_out f', 'f.relation_code = a.receiving_code AND f.status = 1')
            ->leftJoin('ya_wms_in g', 'a.material_id = g.material_id AND g.material_batch = f.material_batch')
            ->field('
                c.processes_code,
                SUM(a.material_num) as accessory_quantity,
                SUM(a.material_num * COALESCE(g.money, 0)) as accessory_cost
            ')
            ->where('e.material_name', 'like', '%配件%')
            ->group('c.processes_code');

        if (!empty($startDate)) {
            $accessoryQuery->where('a.create_time', '>=', $startDate . ' 00:00:00');
        }
        if (!empty($endDate)) {
            $accessoryQuery->where('a.create_time', '<=', $endDate . ' 23:59:59');
        }

        $accessoryStats = $accessoryQuery->select()->toArray();
        $accessoryMap = [];
        foreach ($accessoryStats as $item) {
            if (empty($item['processes_code'])) {
                continue;
            }
            $accessoryMap[$item['processes_code']] = [
                'accessory_quantity' => (float) ($item['accessory_quantity'] ?? 0),
                'accessory_cost' => (float) ($item['accessory_cost'] ?? 0),
            ];
        }

        // 合并数据
        $result = [];
        $rawTotalWage = 0;  // 原始工资总额（用于汇总）
        $rawTotalAccessoryCost = 0;  // 原始配件成本总额

        foreach ($processStats as $stat) {
            $processCode = $stat['processes_code'];
            $wageInfo = $wageMap[$processCode] ?? ['total_wage' => 0.0, 'worker_count' => 0];
            $accessoryInfo = $accessoryMap[$processCode] ?? ['accessory_quantity' => 0.0, 'accessory_cost' => 0.0];

            $totalTonnage = (float) $stat['total_tonnage'];
            $totalWage = (float) ($wageInfo['total_wage'] ?? 0);
            $accessoryCost = (float) ($accessoryInfo['accessory_cost'] ?? 0);

            // 累加原始值用于汇总
            $rawTotalWage += $totalWage;
            $rawTotalAccessoryCost += $accessoryCost;

            // 计算单位成本
            $wagePerTon = $totalTonnage > 0 ? number_format($totalWage / $totalTonnage, 2) : '0.00';
            $accessoryPerTon = $totalTonnage > 0 ? number_format($accessoryCost / $totalTonnage, 2) : '0.00';
            $totalCostPerTon = $totalTonnage > 0 ? number_format(($totalWage + $accessoryCost) / $totalTonnage, 2) : '0.00';

            $result[] = [
                'processes_code' => $processCode,
                'processes_name' => $stat['processes_name'] ?? '未知工序',
                'total_tonnage' => number_format($totalTonnage, 2),
                'total_defective' => number_format((float)$stat['total_defective'], 2),
                'task_count' => $stat['task_count'],
                'total_wage' => number_format($totalWage, 2),
                'worker_count' => (int) ($wageInfo['worker_count'] ?? 0),
                'wage_per_ton' => $wagePerTon,
                'accessory_quantity' => number_format((float) ($accessoryInfo['accessory_quantity'] ?? 0), 2),
                'accessory_cost' => number_format($accessoryCost, 2),
                'accessory_per_ton' => $accessoryPerTon,
                'total_cost' => number_format($totalWage + $accessoryCost, 2),
                'total_cost_per_ton' => $totalCostPerTon,
            ];
        }

        return [
            'start_date' => $startDate ?: '全部',
            'end_date' => $endDate ?: '全部',
            'statistics' => $result,
            'summary' => [
                'total_tonnage' => number_format(array_sum(array_column($result, 'total_tonnage')), 2),
                'total_wage' => number_format($rawTotalWage, 2),
                'total_accessory_cost' => number_format($rawTotalAccessoryCost, 2),
                'total_cost' => number_format($rawTotalWage + $rawTotalAccessoryCost, 2),
            ]
        ];
    }

    /**
     * 生成各工序成本日报表
     * @param string $startDate 起始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public static function getProcessCostDailyReport($startDate = '', $endDate = '')
    {
        // 默认统计当天数据
        if (empty($startDate)) {
            $startDate = date('Y-m-d');
        }
        if (empty($endDate)) {
            $endDate = date('Y-m-d');
        }

        return self::getPowerConsumptionStats($startDate, $endDate);
    }

    /**
     * 导出工序成本日报表为Excel
     * @param string $startDate 起始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public static function exportProcessCostDailyReport($startDate = '', $endDate = '')
    {
        // 默认统计当天数据
        if (empty($startDate)) {
            $startDate = date('Y-m-d');
        }
        if (empty($endDate)) {
            $endDate = date('Y-m-d');
        }

        // 获取报表数据
        $reportData = self::getProcessCostDailyReport($startDate, $endDate);
        $statistics = $reportData['statistics'];
        $summary = $reportData['summary'];

        // 创建 Spreadsheet 对象
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('工序成本日报表');

        // 设置标题
        $title = '工序成本日报表 (' . $reportData['start_date'] . ' 至 ' . $reportData['end_date'] . ')';
        $sheet->mergeCells('A1:O1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // 定义表头
        $headers = [
            'A2' => '工序编码',
            'B2' => '工序名称',
            'C2' => '报工总吨位',
            'D2' => '不良品数量',
            'E2' => '工单数量',
            'F2' => '工人工资总额(元)',
            'G2' => '工人数量',
            'H2' => '吨位工资成本(元/吨)',
            'I2' => '配件数量',
            'J2' => '配件成本(元)',
            'K2' => '吨位配件成本(元/吨)',
            'L2' => '总成本(元)',
            'M2' => '吨位总成本(元/吨)',
        ];

        // 填充表头
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        // 设置表头样式
        $sheet->getStyle('A2:M2')->getFont()->setBold(true);
        $sheet->getStyle('A2:M2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD700');
        $sheet->getStyle('A2:M2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // 填充数据行
        $row = 3;
        foreach ($statistics as $item) {
            $sheet->setCellValue('A' . $row, $item['processes_code']);
            $sheet->setCellValue('B' . $row, $item['processes_name']);
            $sheet->setCellValue('C' . $row, $item['total_tonnage']);
            $sheet->setCellValue('D' . $row, $item['total_defective']);
            $sheet->setCellValue('E' . $row, $item['task_count']);
            $sheet->setCellValue('F' . $row, $item['total_wage']);
            $sheet->setCellValue('G' . $row, $item['worker_count']);
            $sheet->setCellValue('H' . $row, $item['wage_per_ton']);
            $sheet->setCellValue('I' . $row, $item['accessory_quantity']);
            $sheet->setCellValue('J' . $row, $item['accessory_cost']);
            $sheet->setCellValue('K' . $row, $item['accessory_per_ton']);
            $sheet->setCellValue('L' . $row, $item['total_cost']);
            $sheet->setCellValue('M' . $row, $item['total_cost_per_ton']);
            $row++;
        }

        // 添加汇总行
        $summaryRow = $row + 1;
        $sheet->mergeCells('A' . $summaryRow . ':B' . $summaryRow);
        $sheet->setCellValue('A' . $summaryRow, '汇总');
        $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

        $sheet->setCellValue('C' . $summaryRow, $summary['total_tonnage']);
        $sheet->setCellValue('F' . $summaryRow, $summary['total_wage']);
        $sheet->setCellValue('J' . $summaryRow, $summary['total_accessory_cost']);
        $sheet->setCellValue('L' . $summaryRow, $summary['total_cost']);

        $sheet->getStyle('A' . $summaryRow . ':M' . $summaryRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $summaryRow . ':M' . $summaryRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE4B5');

        // 自动调整列宽
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // 设置边框
        $styleArray = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ];
        $sheet->getStyle('A2:M' . ($summaryRow))->applyFromArray($styleArray);

        // 定义保存路径
        $savePath = app()->getRootPath() . 'public/uploads/';
        $filename = '工序成本日报表_' . date('Ymd') . '.xlsx';
        $filePath = $savePath . $filename;

        // 创建目录（如果不存在）
        if (!is_dir($savePath)) {
            mkdir($savePath, 0755, true);
        }

        // 保存文件
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        // 返回文件信息
        return [
            'code' => 1,
            'msg' => '导出成功',
            'data' => [
                'filename' => $filename,
                'filepath' => $filePath,
                'url' => request()->domain() . '/uploads/' . $filename,
                'size' => filesize($filePath),
                'time' => date('Y-m-d H:i:s')
            ]
        ];
    }
}
