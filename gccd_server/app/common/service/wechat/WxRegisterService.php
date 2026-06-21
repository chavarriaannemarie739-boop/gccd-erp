<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\wechat;

use app\common\model\admin\DeviceModel;
use app\common\model\admin\InStockModel;
use app\common\model\admin\ProductionModel;
use app\common\model\admin\ProductReportModel;
use app\common\model\admin\RepairModel;
use app\common\model\wechat\WxUserModel;
use app\common\config\DailyReportConfig;
use app\common\service\admin\DeviceService;
use app\common\service\admin\ProductReportService;
use app\common\service\admin\ProductionService;
use app\common\service\admin\PublicService;
use think\console\command\optimize\Schema;
use think\facade\App;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class WxRegisterService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'username' => '', //调试前物料编号
        'password' => '', //
        'public_name' => '',
        'wechat_name' => '',
        'track_id' => '',
        'img' => '',
        'wx_appid' => '',
        'reference' => ''
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'username' => '', //
        'password' => '', //
        'public_name' => '',
        'wechat_name' => '',
        'track_name' => '',
        'img' => '',
        'wx_appid' => '',
        'reference' => ''
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
    public static function list($where = [], $page = 1, $limit = 10, $order = [], $field = [])
    {
        $model = new WxUserModel();
        $pk = $model->getPk();
        if (empty($order)) {
            $order = ['create_time' => 'desc'];
        }

        if ($page == 0 || $limit == 0) {
            return $model->with(['getTrack'])->where($where)->order($order)->select()->toArray();
        }
        $count = $model->where($where)->count();
        $pages = ceil($count / $limit);
        $list = $model->with(['getTrack'])->where($where)->page($page)->limit($limit)->order($order)->select()->toArray();
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
            $model = new WxUserModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的申请信息不存在');
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
            /*还要获取微信用户的openid*/
            $openId = PublicService::getOpenID($param['wx_appid']);
            $param['wx_appid'] = $openId;
//            /*获取文件上传*/
            $model = new WxUserModel();
            $data = $model->where('wx_appid', $openId)->select()->toArray();
            if (!empty($data)) {
                exception('只能申请一次账号');
            }
            $data = $model->where('username', $param['username'])->select()->toArray();
            if (!empty($data)) {
                exception('用户名不能重复');
            }
            $pk = $model->getPk();
            $param['create_time'] = datetime();
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
        $model = new WxUserModel();
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
        $model = new WxUserModel();
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

    /*审核用户申请*/
    public static function updatestatus($param)
    {
        $model = new WxUserModel();
        $data = $model->find($param['id']);
        if (empty($data)) {
            exception('找不到对应信息');
        }
        $data->check_status = $param['status'];
        $data->update_uid = user_id();
        $data->update_time = datetime();
        $data->refuse_reason = $param['refuse_reason'];
        /*保存方法*/
        $data->save();

        $data = $data->toArray();
        /*审核通过创建用户*/
        if ($param['status'] == 1) {
            /*取出id字段*/
            unset($data['id']);
            unset($data['check_status']);
            unset($data['create_time']);
            $data['create_time'] = datetime();
            $data['create_uid'] = user_id();

            WeChatUserService::adduser($data);
        }
        return $data;
    }

    /**
     * 大屏数据暂行：
     * 1.计划列表：单号、型号等参数、数量、状态（未开始、生产中、已完成）；根据所占屏幕大小看放多少条，可滚动。
     * 2.生产进度：按工序展示状态即可，单号：工序①已完成 工序②生产中 工序③未开始 工序④/⑤。。。。
     * 3.设备状态：设备总数X；工作中X；未开机X；故障X；（系统中有的数据从系统中关联，没有得先写死数）
     * 4.不良品/废品：近5日数量（折线图）
     * 5.能耗：近5日用电/气量（柱状图）
     * 6.入库数量：近5日成品入库数量（折线图）
     *
     */
    public static function index()
    {
        /*生产进度*/
        $plan_list = ProductionService::list();
        /*获取设备信息*/
        $device = new DeviceModel();
        $device_list = $device->select()->toArray();
        $device_count = $device->select()->count();
        $normal = $device->where('status', 0)->count();
        $question = $device->where('status', 1)->count();

        /*查询能耗*/
        $report = new ProductReportModel();
        /*统计所有工序的计算数量*/
        $endDate = date('Y-m-d H:i:s');
        $startDate = date('Y-m-d 00:00:00', strtotime('-5 days'));
        $report_gas = $report
                ->whereBetween('create_time', [$startDate, $endDate])
                ->field("DATE(create_time) as date, SUM(gas_consume) as total_gas, SUM(power_consume) as total_power")
                ->group('DATE(create_time)')
                ->order('create_time', 'desc')
                ->select();
       // 补全5天数据
        $completeResult = [];
        for ($i = 4; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $gasAmount = 0;
            $powerAmount = 0;

            // 查找对应日期的数据
            foreach ($report_gas as $item) {
                if ($item['date'] == $date) {
                    $gasAmount = floatval($item['total_gas']);
                    $powerAmount = floatval($item['total_power']);
                    break;
                }
            }
            $completeResult[] = [
                'date' => $date,
                'total_gas' => $gasAmount,
                'total_power' => $powerAmount
            ];
        }
        $report_power = $report->field('power_num')->order('create_time')->limit(5)->select()->toArray();
//        $report_gas = PublicService::handlearray('gas_num',$report_gas);
        $report_power = PublicService::handlearray('power_num', $report_power);

        /*近五日入库数量*/
        $in_model = new InStockModel();
        $in_num = $in_data = $in_model
            ->field("DATE(create_time) as date, SUM(in_num) as daily_total")
            ->whereTime('create_time', '>=', '-5 days')
            ->group('DATE(create_time)')
            ->order('date', 'desc')
            ->select();
        // 1. 生成近五天的日期数组（包含今天）
        $recentDates = [];
        for ($i = 4; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $recentDates[] = $date;
        }
        // 2. 查询数据库中的每日入库统计
        $dbData = $in_model
            ->field("DATE(create_time) as date, SUM(in_num) as daily_total")
            ->whereTime('create_time', '>=', date('Y-m-d', strtotime('-5 days')) . ' 00:00:00')
            ->group('DATE(create_time)')
            ->select()
            ->toArray(); // 转换为数组

        // 3. 将数据库结果转换为以日期为键的映射数组
        $dbDataMap = [];
        foreach ($dbData as $item) {
            $dbDataMap[$item['date']] = (int)$item['daily_total'];
        }

        // 4. 构建完整的结果数组，确保每天都有数据
        $result = [];
        foreach ($recentDates as $date) {
            $result[] = [
                'date' => $date,
                'daily_total' => $dbDataMap[$date] ?? 0, // 当天没数据则为0
                'weekday' => '周' . ['日', '一', '二', '三', '四', '五', '六'][date('w', strtotime($date))],
                'format_date' => date('m月d日', strtotime($date)) // 格式化显示
            ];
        }
        /*根据设备列表查询当期那的数据信息*/
        /*判断是否存在工作的机械*/
        $result = [
            'device_status' => [
                'device_count' => $device_count,
                'normal' => $normal,
                'work' => $normal,  //正在运行
                'no_start' => $question,
                'question' => $question
            ],
            'report_gas' => $completeResult,
            'report_power' => $completeResult,
            'in_count' => $result,
            'device_list' => $device_list
        ];
        /*数据大屏*/
        return $result;
    }

    public static function getDevice($startDate = null, $endDate = null)
    {
        $device = new DeviceModel();

        $needEnergyDevices = [
            '穿孔',
            '球化',
            '酸洗'
        ];

        $allDevices = $device
            ->where('is_delete', 0)
            ->field(['id', 'device_code', 'device_name', 'status', 'processes_code'])
            ->select()
            ->toArray();

        $deviceList = array_map(function ($dev) {
            return [
                'device_name' => $dev['device_name'],
                'device_code' => $dev['device_code'],
                'device_status' => $dev['status'] == 0 ? '正常运行' : '维修中',
                'status_code' => $dev['status']
            ];
        }, $allDevices);

        $energyDevices = array_filter($allDevices, function ($dev) use ($needEnergyDevices) {
            foreach ($needEnergyDevices as $keyword) {
                if (strpos($dev['device_name'], $keyword) !== false) {
                    return true;
                }
            }
            return false;
        });

        $energyDeviceCodes = array_column($energyDevices, 'device_code');

        $powerModel = new \app\common\model\admin\ProductPowerModel();
        $reportModel = new ProductReportModel();
        $todayStart = date('Y-m-d') . ' 00:00:00';
        $todayEnd = date('Y-m-d') . ' 23:59:59';

        $energyData = [];
        if (!empty($energyDeviceCodes)) {
            $todayRecords = $powerModel
                ->whereIn('device_code', $energyDeviceCodes)
                ->whereBetween('create_time', [$todayStart, $todayEnd])
                ->order('create_time', 'asc')
                ->select()
                ->toArray();

            $mergedIds = [];
            $deviceEnergyMap = [];

            foreach ($todayRecords as $record) {
                if (in_array($record['id'], $mergedIds)) {
                    continue;
                }

                $deviceCode = $record['device_code'];
                $gasConsume = floatval($record['gas_consume']);
                $powerConsume = floatval($record['power_consume']);

                if (!empty($record['direction']) && in_array($record['direction'], ['北', '南'])) {
                    $oppositeDirection = $record['direction'] == '北' ? '南' : '北';

                    $currentTime = strtotime($record['create_time']);
                    $startTime = date('Y-m-d H:i:s', $currentTime - 30);
                    $endTime = date('Y-m-d H:i:s', $currentTime + 30);

                    $mergeRecord = $powerModel
                        ->where('device_code', $deviceCode)
                        ->where('direction', $oppositeDirection)
                        ->whereBetween('create_time', [$startTime, $endTime])
                        ->where('id', '<>', $record['id'])
                        ->find();

                    if ($mergeRecord) {
                        $gasConsume += floatval($mergeRecord['gas_consume']);
                        $powerConsume += floatval($mergeRecord['power_consume']);
                        $mergedIds[] = $mergeRecord['id'];
                    }
                }

                if (!isset($deviceEnergyMap[$deviceCode])) {
                    $deviceEnergyMap[$deviceCode] = [
                        'gas_consume' => 0,
                        'power_consume' => 0
                    ];
                }

                $deviceEnergyMap[$deviceCode]['gas_consume'] += $gasConsume;
                $deviceEnergyMap[$deviceCode]['power_consume'] += $powerConsume;
            }

            $deviceDefectiveMap = [];
            try {
                $todayReports = $reportModel
                    ->whereIn('device_code', $energyDeviceCodes)
                    ->whereBetween('create_time', [$todayStart, $todayEnd])
                    ->field('device_code, defective')
                    ->select()
                    ->toArray();

                foreach ($todayReports as $report) {
                    if (empty($report['device_code'])) {
                        continue;
                    }

                    $deviceCode = $report['device_code'];
                    $defectiveTotal = 0;

                    if (!empty($report['defective'])) {
                        $defectiveList = json_decode($report['defective'], true);
                        if (is_array($defectiveList)) {
                            foreach ($defectiveList as $item) {
                                if (isset($item['num'])) {
                                    $defectiveTotal += floatval($item['num']);
                                }
                            }
                        }
                    }

                    if (!isset($deviceDefectiveMap[$deviceCode])) {
                        $deviceDefectiveMap[$deviceCode] = 0;
                    }
                    $deviceDefectiveMap[$deviceCode] += $defectiveTotal;
                }
            } catch (\Exception $e) {
                $deviceDefectiveMap = [];
            }

            foreach ($energyDevices as $dev) {
                $deviceCode = $dev['device_code'];

                $energyInfo = isset($deviceEnergyMap[$deviceCode]) ? $deviceEnergyMap[$deviceCode] : ['gas_consume' => 0, 'power_consume' => 0];
                $defectiveNum = isset($deviceDefectiveMap[$deviceCode]) ? $deviceDefectiveMap[$deviceCode] : 0;

                $energyData[] = [
                    'device_code' => $deviceCode,
                    'device_name' => $dev['device_name'],
                    'processes_code' => $dev['processes_code'],
                    'gas_consume' => round($energyInfo['gas_consume'], 2),
                    'power_consume' => round($energyInfo['power_consume'], 2),
                    'defective_num' => round($defectiveNum, 2)
                ];
            }
        }

        $result = [
            'device_list' => $deviceList,
            'total_devices' => count($deviceList),
            'energy_data' => $energyData
        ];

        return $result;
    }

    public static function getProcessDailyReport($startDate = null, $endDate = null)
    {
        if (empty($startDate)) {
            $startDate = date('Y-m-d') . ' 00:00:00';
        } else {
            $startDate = $startDate . ' 00:00:00';
        }

        if (empty($endDate)) {
            $endDate = date('Y-m-d') . ' 23:59:59';
        } else {
            $endDate = $endDate . ' 23:59:59';
        }

        $reportModel = new ProductReportModel();
        $inStockModel = new InStockModel();
        $deviceModel = new DeviceModel();

        $outStockModel = new \app\common\model\admin\OutStockModel();

        $reports = $reportModel
            ->whereBetween('create_time', [$startDate, $endDate])
            ->field('processes_code, 
                     DATE(create_time) as report_date,
                     material_id,
                     data,
                     SUM(report_num) as total_report_num,
                     SUM(defective_num) as total_defective_num')
            ->group('processes_code, DATE(create_time), material_id')
            ->order('report_date desc, processes_code asc')
            ->select()
            ->toArray();

        $processMap = [];
        foreach ($reports as $report) {
            $processCode = $report['processes_code'];
            $reportDate = $report['report_date'];
            $materialId = $report['material_id'];

            $key = $processCode . '_' . $reportDate;

            if (!isset($processMap[$key])) {
                $processMap[$key] = [
                    'processes_code' => $processCode,
                    'report_date' => $reportDate,
                    'format_date' => date('Y年m月d日', strtotime($reportDate)),
                    'weekday' => '周' . ['日', '一', '二', '三', '四', '五', '六'][date('w', strtotime($reportDate))],
                    'devices' => [],
                    'total_report_num' => 0,
                    'total_defective_num' => 0,
                    'materials' => []
                ];
            }

            $deviceInfoList = [];
            if (!empty($report['data'])) {
                $dataJson = json_decode($report['data'], true);
                if (is_array($dataJson)) {
                    if (isset($dataJson['device_code']) && !empty($dataJson['device_code'])) {
                        $deviceCode = $dataJson['device_code'];
                        $deviceName = isset($dataJson['device_name']) ? $dataJson['device_name'] : '';

                        if (empty($deviceName)) {
                            $deviceRecord = $deviceModel->where('device_code', $deviceCode)->find();
                            if ($deviceRecord) {
                                $deviceName = $deviceRecord['device_name'];
                            }
                        }

                        $deviceInfoList[] = [
                            'device_code' => $deviceCode,
                            'device_name' => $deviceName
                        ];
                    }
                }
            }

            if (empty($deviceInfoList)) {
                $deviceInfoList[] = [
                    'device_code' => '',
                    'device_name' => '未指定设备'
                ];
            }

            foreach ($deviceInfoList as $deviceInfo) {
                $processMap[$key]['devices'][] = [
                    'device_code' => $deviceInfo['device_code'],
                    'device_name' => $deviceInfo['device_name'],
                    'report_num' => floatval($report['total_report_num']),
                    'defective_num' => floatval($report['total_defective_num'])
                ];
            }

            $processMap[$key]['total_report_num'] += floatval($report['total_report_num']);
            $processMap[$key]['total_defective_num'] += floatval($report['total_defective_num']);

            if (!isset($processMap[$key]['materials'][$materialId])) {
                $processMap[$key]['materials'][$materialId] = [
                    'material_id' => $materialId,
                    'used_quantity' => 0,
                    'stock_in_num' => 0
                ];
            }
            $processMap[$key]['materials'][$materialId]['used_quantity'] += floatval($report['total_report_num']);
        }

        $outStocks = $outStockModel
            ->whereBetween('create_time', [$startDate, $endDate])
            ->field('material_id, 
                     DATE(create_time) as out_date,
                     SUM(out_num) as total_out_num')
            ->group('material_id, DATE(create_time)')
            ->select()
            ->toArray();

        $outStockMap = [];
        foreach ($outStocks as $outStock) {
            $outDate = $outStock['out_date'];
            $materialId = $outStock['material_id'];

            if (!isset($outStockMap[$outDate])) {
                $outStockMap[$outDate] = [];
            }

            if (!isset($outStockMap[$outDate][$materialId])) {
                $outStockMap[$outDate][$materialId] = 0;
            }
            $outStockMap[$outDate][$materialId] += floatval($outStock['total_out_num']);
        }

        $inStocks = $inStockModel
            ->whereBetween('create_time', [$startDate, $endDate])
            ->field('material_id, 
                     DATE(create_time) as stock_date,
                     SUM(in_num) as total_in_num')
            ->group('material_id, DATE(create_time)')
            ->select()
            ->toArray();

        $stockMap = [];
        foreach ($inStocks as $stock) {
            $stockDate = $stock['stock_date'];
            $materialId = $stock['material_id'];

            if (!isset($stockMap[$stockDate])) {
                $stockMap[$stockDate] = [];
            }

            $stockMap[$stockDate][$materialId] = floatval($stock['total_in_num']);
        }

        foreach ($processMap as &$item) {
            $reportDate = $item['report_date'];

            if (isset($outStockMap[$reportDate])) {
                foreach ($item['materials'] as $materialId => &$material) {
                    if (isset($outStockMap[$reportDate][$materialId])) {
                        $material['used_quantity'] = $outStockMap[$reportDate][$materialId];
                    }

                    if (isset($stockMap[$reportDate][$materialId])) {
                        $material['stock_in_num'] = $stockMap[$reportDate][$materialId];
                    } else {
                        $material['stock_in_num'] = 0;
                    }
                }
            } else {
                foreach ($item['materials'] as &$material) {
                    $material['used_quantity'] = 0;
                    $material['stock_in_num'] = 0;
                }
            }

            $item['materials'] = array_values($item['materials']);
        }
        unset($item);

        $result = array_values($processMap);

        usort($result, function ($a, $b) {
            if ($a['report_date'] == $b['report_date']) {
                return strcmp($a['processes_code'], $b['processes_code']);
            }
            return strcmp($b['report_date'], $a['report_date']);
        });

        self::roundProcessDailyReportQuantities($result);

        return [
            'list' => $result,
            'total' => count($result),
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'summary' => self::getReportSummary($result)
        ];
    }

    /** 工序每日生产报表：数量类字段统一保留两位小数 */
    private static function roundProcessDailyReportQuantities(array &$rows): void
    {
        foreach ($rows as &$row) {
            $row['total_report_num'] = round((float) ($row['total_report_num'] ?? 0), 2);
            $row['total_defective_num'] = round((float) ($row['total_defective_num'] ?? 0), 2);
            if (!empty($row['devices']) && is_array($row['devices'])) {
                foreach ($row['devices'] as &$d) {
                    $d['report_num'] = round((float) ($d['report_num'] ?? 0), 2);
                    $d['defective_num'] = round((float) ($d['defective_num'] ?? 0), 2);
                }
                unset($d);
            }
            if (!empty($row['materials']) && is_array($row['materials'])) {
                foreach ($row['materials'] as &$m) {
                    $m['used_quantity'] = round((float) ($m['used_quantity'] ?? 0), 2);
                    $m['stock_in_num'] = round((float) ($m['stock_in_num'] ?? 0), 2);
                }
                unset($m);
            }
        }
        unset($row);
    }

    public static function exportProcessDailyReport($startDate = null, $endDate = null)
    {
        if (empty($startDate)) {
            $startDate = date('Y-m-d') . ' 00:00:00';
        } else {
            $startDate = $startDate . ' 00:00:00';
        }

        if (empty($endDate)) {
            $endDate = date('Y-m-d') . ' 23:59:59';
        } else {
            $endDate = $endDate . ' 23:59:59';
        }

        $reportData = self::getProcessDailyReport($startDate, $endDate);
        $list = $reportData['list'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', '工序每日生产报表');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $dateRangeText = "统计期间：" . date('Y年m月d日', strtotime($startDate)) . " 至 " . date('Y年m月d日', strtotime($endDate));
        $sheet->setCellValue('A2', $dateRangeText);
        $sheet->mergeCells('A2:H2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A4', '日期');
        $sheet->setCellValue('B4', '星期');
        $sheet->setCellValue('C4', '工序编码');
        $sheet->setCellValue('D4', '设备名称');
        $sheet->setCellValue('E4', '生产数量');
        $sheet->setCellValue('F4', '不良品数量');
        $sheet->setCellValue('G4', '物料ID');
        $sheet->setCellValue('H4', '用料数量');

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9E2F3']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ]
        ];
        $sheet->getStyle('A4:H4')->applyFromArray($headerStyle);

        $row = 5;
        foreach ($list as $item) {
            $reportDate = $item['format_date'];
            $weekday = $item['weekday'];
            $processCode = $item['processes_code'];

            $deviceCount = count($item['devices']);
            $materialCount = count($item['materials']);
            $maxRows = max($deviceCount, $materialCount, 1);

            for ($i = 0; $i < $maxRows; $i++) {
                if ($i == 0) {
                    $sheet->setCellValue('A' . $row, $reportDate);
                    $sheet->setCellValue('B' . $row, $weekday);
                    $sheet->setCellValue('C' . $row, $processCode);
                } else {
                    $sheet->setCellValue('A' . $row, '');
                    $sheet->setCellValue('B' . $row, '');
                    $sheet->setCellValue('C' . $row, '');
                }

                if (isset($item['devices'][$i])) {
                    $device = $item['devices'][$i];
                    $sheet->setCellValue('D' . $row, $device['device_name']);
                    $sheet->setCellValue('E' . $row, $device['report_num']);
                    $sheet->setCellValue('F' . $row, $device['defective_num']);
                } else {
                    $sheet->setCellValue('D' . $row, '');
                    $sheet->setCellValue('E' . $row, '');
                    $sheet->setCellValue('F' . $row, '');
                }

                if (isset($item['materials'][$i])) {
                    $material = $item['materials'][$i];
                    $sheet->setCellValue('G' . $row, $material['material_id']);
                    $sheet->setCellValue('H' . $row, $material['used_quantity']);
                } else {
                    $sheet->setCellValue('G' . $row, '');
                    $sheet->setCellValue('H' . $row, '');
                }

                $row++;
            }

            $sheet->setCellValue('A' . $row, '小计');
            $sheet->mergeCells('A' . $row . ':D' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->setCellValue('E' . $row, $item['total_report_num']);
            $sheet->setCellValue('F' . $row, $item['total_defective_num']);
            $sheet->getStyle('E' . $row . ':F' . $row)->getFont()->setBold(true);

            $row += 2;
        }

        $summaryRow = $row;
        $sheet->setCellValue('A' . $summaryRow, '汇总统计');
        $sheet->mergeCells('A' . $summaryRow . ':H' . $summaryRow);
        $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $summaryRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $summaryRow++;
        $sheet->setCellValue('A' . $summaryRow, '总生产数量：');
        $sheet->setCellValue('B' . $summaryRow, $reportData['summary']['total_report_num']);
        $sheet->setCellValue('C' . $summaryRow, '总不良品数量：');
        $sheet->setCellValue('D' . $summaryRow, $reportData['summary']['total_defective_num']);
        $sheet->setCellValue('E' . $summaryRow, '统计天数：');
        $sheet->setCellValue('F' . $summaryRow, $reportData['summary']['total_days']);
        $sheet->setCellValue('G' . $summaryRow, '日均产量：');
        $sheet->setCellValue('H' . $summaryRow, $reportData['summary']['daily_average']);
        $sheet->getStyle('A' . $summaryRow . ':H' . $summaryRow)->getFont()->setBold(true);

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];
        $sheet->getStyle('A4:H' . ($summaryRow))->applyFromArray($borderStyle);

        $fileName = '工序生产报表_' . date('YmdHis') . '.xlsx';
        $uploadPath = public_path() . 'uploads/excel/';
        $filePath = $uploadPath . $fileName;

        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        return [
            'file_name' => $fileName,
            'file_path' => '/uploads/excel/' . $fileName,
            'download_url' => request()->domain() . '/uploads/excel/' . $fileName
        ];
    }

    private static function getReportSummary($list)
    {
        $summary = [
            'total_report_num' => 0,
            'total_defective_num' => 0,
            'total_days' => 0,
            'total_processes' => 0,
            'processes_list' => [],
            'daily_average' => 0
        ];

        $dateSet = [];
        $processSet = [];

        foreach ($list as $item) {
            $summary['total_report_num'] += $item['total_report_num'];
            $summary['total_defective_num'] += $item['total_defective_num'];

            $dateSet[$item['report_date']] = true;
            $processSet[$item['processes_code']] = true;

            if (!isset($summary['processes_list'][$item['processes_code']])) {
                $summary['processes_list'][$item['processes_code']] = [
                    'processes_code' => $item['processes_code'],
                    'total_report_num' => 0,
                    'total_defective_num' => 0
                ];
            }
            $summary['processes_list'][$item['processes_code']]['total_report_num'] += $item['total_report_num'];
            $summary['processes_list'][$item['processes_code']]['total_defective_num'] += $item['total_defective_num'];
        }

        $summary['total_days'] = count($dateSet);
        $summary['total_processes'] = count($processSet);
        $summary['processes_list'] = array_values($summary['processes_list']);

        if ($summary['total_days'] > 0) {
            $summary['daily_average'] = round($summary['total_report_num'] / $summary['total_days'], 2);
        }

        $summary['total_report_num'] = round((float) $summary['total_report_num'], 2);
        $summary['total_defective_num'] = round((float) $summary['total_defective_num'], 2);
        $summary['daily_average'] = round((float) $summary['daily_average'], 2);
        foreach ($summary['processes_list'] as &$pl) {
            $pl['total_report_num'] = round((float) ($pl['total_report_num'] ?? 0), 2);
            $pl['total_defective_num'] = round((float) ($pl['total_defective_num'] ?? 0), 2);
        }
        unset($pl);

        return $summary;
    }

    /**
     * 计划下圆钢（计划明细中排除成品物料）：名称/规格/型号，多条用「；」分隔。
     * 成品：主表 english_material 对应物料的 规格/型号（用 / 连接）。
     *
     * @return array{steel_display: string, product_display: string}
     */
    private static function planMaterialDisplays(string $planCode): array
    {
        $empty = [
            'steel_display' => '',
            'product_display' => '',
            'steel_spec' => '',
            'steel_model' => '',
            'product_spec' => '',
            'product_model' => '',
        ];
        if ($planCode === '') {
            return $empty;
        }
        try {
            $prod = Db::name('production')
                ->where('production_plan_code', $planCode)
                ->where('is_delete', 0)
                ->field('english_material')
                ->find();
        } catch (\Throwable $e) {
            return $empty;
        }
        if (empty($prod)) {
            return $empty;
        }
        $engId = (int) ($prod['english_material'] ?? 0);

        $productDisplay = '';
        $productSpec = '';
        $productModel = '';
        if ($engId > 0) {
            try {
                $fm = Db::name('material')->where('id', $engId)->field('material_spec,material_model')->find();
                if (!empty($fm)) {
                    $ps = trim((string) ($fm['material_spec'] ?? ''));
                    $pm = trim((string) ($fm['material_model'] ?? ''));
                    $productSpec = $ps;
                    $productModel = $pm;
                    $parts = array_filter([$ps, $pm], static function ($x) {
                        return $x !== '';
                    });
                    $productDisplay = implode('/', $parts);
                }
            } catch (\Throwable $e) {
                $productDisplay = '';
            }
        }

        $steelParts = [];
        $steelSpec = '';
        $steelModel = '';
        try {
            $details = Db::name('production_detail')
                ->alias('d')
                ->leftJoin('material m', 'm.id = d.material_id')
                ->where('d.production_plan_code', $planCode)
                ->field('m.id as mid, m.material_name, m.material_spec, m.material_model')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $details = [];
        }
        foreach ($details as $d) {
            $mid = (int) ($d['mid'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            if ($engId > 0 && $mid === $engId) {
                continue;
            }
            $n = trim((string) ($d['material_name'] ?? ''));
            $s = trim((string) ($d['material_spec'] ?? ''));
            $m = trim((string) ($d['material_model'] ?? ''));
            if ($steelSpec === '' && $s !== '') {
                $steelSpec = $s;
            }
            if ($steelModel === '' && $m !== '') {
                $steelModel = $m;
            }
            $seg = implode('/', array_filter([$n, $s, $m], static function ($x) {
                return $x !== '';
            }));
            if ($seg !== '') {
                $steelParts[] = $seg;
            }
        }
        if ($steelParts === [] && $engId <= 0) {
            foreach ($details as $d) {
                $mid = (int) ($d['mid'] ?? 0);
                if ($mid <= 0) {
                    continue;
                }
                $n = trim((string) ($d['material_name'] ?? ''));
                $s = trim((string) ($d['material_spec'] ?? ''));
                $m = trim((string) ($d['material_model'] ?? ''));
                if ($steelSpec === '' && $s !== '') {
                    $steelSpec = $s;
                }
                if ($steelModel === '' && $m !== '') {
                    $steelModel = $m;
                }
                $seg = implode('/', array_filter([$n, $s, $m], static function ($x) {
                    return $x !== '';
                }));
                if ($seg !== '') {
                    $steelParts[] = $seg;
                }
            }
        }
        $steelDisplay = implode('；', array_values(array_unique($steelParts)));

        return [
            'steel_display' => $steelDisplay,
            'product_display' => $productDisplay,
            'steel_spec' => $steelSpec,
            'steel_model' => $steelModel,
            'product_spec' => $productSpec,
            'product_model' => $productModel,
        ];
    }

    /**
     * @param array{done: float|int, percent: float|int} $prog
     */
    private static function buildPlanCockpitRow(
        string $planCode,
        int $st,
        string $statusLabel,
        string $planName,
        float $planQty,
        array $prog
    ): array {
        $labels = self::planMaterialDisplays($planCode);

        return array_merge([
            'production_plan_code' => $planCode,
            'production_plan_name' => $planName,
            'status' => $st,
            'status_label' => $statusLabel,
            'plan_qty' => $planQty,
            'done_qty' => $prog['done'],
            'progress_percent' => $prog['percent'],
        ], $labels);
    }

    /**
     * 当月报工 defective JSON 按不良项名称汇总，供驾驶舱饼图。
     *
     * @return array<int, array{name: string, value: float}>
     */
    private static function dashboardReportDefectsByCategory(string $monthStart, string $monthEnd): array
    {
        $agg = [];
        try {
            $jsons = Db::name('product_report')
                ->where('is_delete', 0)
                ->whereBetween('create_time', [$monthStart, $monthEnd])
                ->whereNotNull('defective')
                ->where('defective', '<>', '')
                ->column('defective');
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($jsons as $json) {
            $arr = json_decode((string) $json, true);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $one) {
                if (!is_array($one)) {
                    continue;
                }
                $name = trim((string) ($one['name'] ?? ''));
                if ($name === '') {
                    $name = '未分类';
                }
                $num = (float) ($one['num'] ?? 0);
                if (!isset($agg[$name])) {
                    $agg[$name] = 0.0;
                }
                $agg[$name] += $num;
            }
        }
        $out = [];
        foreach ($agg as $name => $val) {
            if ($val > 0) {
                $out[] = ['name' => $name, 'value' => round($val, 3)];
            }
        }
        usort($out, static function ($a, $b) {
            return ($b['value'] <=> $a['value']);
        });

        return $out;
    }

    /**
     * 当月质检不良按 defect_text / defect_type 汇总。
     *
     * @return array<int, array{name: string, value: float}>
     */
    private static function dashboardQcDefectsByCategory(string $monthFirstDay, string $monthLastDay): array
    {
        $agg = [];
        try {
            $rows = Db::name('quality_check_defect')
                ->alias('d')
                ->leftJoin('quality_check_item i', 'i.id = d.item_id')
                ->leftJoin('quality_check_record r', 'r.id = i.record_id')
                ->whereBetween('r.check_date', [$monthFirstDay, $monthLastDay])
                ->field('d.defect_type, d.defect_text, d.day_qty, d.night_qty')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as $row) {
            $txt = trim((string) ($row['defect_text'] ?? ''));
            $typ = trim((string) ($row['defect_type'] ?? ''));
            $cat = $txt !== '' ? $txt : ($typ !== '' ? $typ : '未分类');
            $qty = (float) ($row['day_qty'] ?? 0) + (float) ($row['night_qty'] ?? 0);
            if (!isset($agg[$cat])) {
                $agg[$cat] = 0.0;
            }
            $agg[$cat] += $qty;
        }
        $out = [];
        foreach ($agg as $name => $val) {
            if ($val > 0) {
                $out[] = ['name' => $name, 'value' => round($val, 3)];
            }
        }
        usort($out, static function ($a, $b) {
            return ($b['value'] <=> $a['value']);
        });

        return $out;
    }

    /**
     * 首页驾驶舱：设备、工序能耗、报工/质检不良占比、计划与进度、工序实时产量
     */
    /**
     * 首页/大屏驾驶舱聚合数据
     *
     * 聚合较重（计划进度 N+1、设备、能耗、质量等），故缓存约 60 秒，
     * 与前端轮询同频；网页/App/多台电视共享同一份缓存，避免每次重算。
     *
     * @param bool $useCache 是否使用缓存（默认 true）
     *
     * @return array
     */
    public static function dashboardBoard(bool $useCache = true): array
    {
        $cacheKey = 'cockpit_dashboard_board';
        // TTL 略大于前端 60 秒轮询，保证轮询稳定命中缓存
        $cacheTtl = 90;

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $board = self::buildDashboardBoard();

        try {
            Cache::set($cacheKey, $board, $cacheTtl);
        } catch (\Throwable $e) {
            // 缓存写入失败不影响返回（如目录不可写），仅记录
            \think\facade\Log::warning('驾驶舱缓存写入失败：' . $e->getMessage());
        }

        return $board;
    }

    /**
     * 实际构建驾驶舱聚合数据（无缓存，重计算）
     *
     * @return array
     */
    private static function buildDashboardBoard(): array
    {
        $gd = self::getDevice();
        $deviceRows = [];
        foreach ($gd['device_list'] ?? [] as $d) {
            $deviceRows[] = [
                'device_name' => $d['device_name'] ?? '',
                'device_code' => $d['device_code'] ?? '',
                'status_label' => $d['device_status'] ?? '—',
                'status_code' => (int) ($d['status_code'] ?? 0),
            ];
        }
        /* 台账接口异常或列表为空时，直接从设备表兜底（与台账字段一致） */
        if ($deviceRows === []) {
            try {
                $rows = Db::name('device')
                    ->where('is_delete', 0)
                    ->order('id', 'asc')
                    ->limit(80)
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $st = (int) ($row['status'] ?? 0);
                    $deviceRows[] = [
                        'device_name' => $row['device_name'] ?? '',
                        'device_code' => $row['device_code'] ?? '',
                        'status_label' => $st === 0 ? '正常运行' : '维修中',
                        'status_code' => $st,
                    ];
                }
            } catch (\Throwable $e) {
                $deviceRows = [];
            }
        }
        /* 台账仍为空：从近期报工中出现的设备编码兜底（常见于设备主数据未维护但产线已在报工） */
        if ($deviceRows === []) {
            try {
                $since = date('Y-m-d 00:00:00', strtotime('-120 day'));
                $until = date('Y-m-d 23:59:59');
                $codes = Db::name('product_report')
                    ->where('is_delete', 0)
                    ->where('device_code', '<>', '')
                    ->whereBetween('create_time', [$since, $until])
                    ->group('device_code')
                    ->order('device_code', 'asc')
                    ->column('device_code');
                $codes = array_values(array_filter(array_unique($codes)));
                $codes = array_slice($codes, 0, 80);
                foreach ($codes as $code) {
                    if ($code === '' || $code === null) {
                        continue;
                    }
                    $row = Db::name('device')
                        ->where('is_delete', 0)
                        ->where('device_code', $code)
                        ->find();
                    if (!empty($row)) {
                        $st = (int) ($row['status'] ?? 0);
                        $deviceRows[] = [
                            'device_name' => $row['device_name'] ?? $code,
                            'device_code' => $code,
                            'status_label' => $st === 0 ? '正常运行' : '维修中',
                            'status_code' => $st,
                        ];
                    } else {
                        $deviceRows[] = [
                            'device_name' => (string) $code,
                            'device_code' => (string) $code,
                            'status_label' => '报工关联（未建档）',
                            'status_code' => 0,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // 忽略兜底失败
            }
        }

        /* 抄表/报工归属日：12:00 为界（与录入时 report_date 一致） */
        $businessToday = ProductReportService::calcBusinessReportDate();
        $cockpitYesterday = ProductReportService::calcCockpitYesterdayReportDate();
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');

        /* [AI-2026-06] 工序能耗：抄表列表同源，按 report_date 汇总穿孔/球化/酸洗 */
        $energyYesterday = self::energyCockpitFromMeterReading($cockpitYesterday, $cockpitYesterday);
        $energyMonth = self::energyCockpitFromMeterReading($monthStart, $businessToday);

        /* 检测 daily_summary 是否有日合计行（不良占比等仍用日报汇总） */
        $hasDailySummary = Db::name('daily_summary')
            ->whereBetween('report_date', [$monthStart, $today])
            ->where('processes_name', '日合计')
            ->count() > 0;

        if ($hasDailySummary) {
            $reportDefectMonth = 0.0;
            $qcDefectMonth = 0.0;
            $defectReportCatAgg = [];
            $defectQcCatAgg = [];

            $dailyTotalRows = Db::name('daily_summary')
                ->whereBetween('report_date', [$monthStart, $today])
                ->where('processes_name', '日合计')
                ->select()
                ->toArray();

            foreach ($dailyTotalRows as $row) {
                $reportDefectMonth += (float) ($row['defective_report_qty'] ?? 0);
                $qcDefectMonth += (float) ($row['defective_qc_qty'] ?? 0);

                $catJson = $row['report_defect_category_json'] ?? '';
                if ($catJson) {
                    $catArr = json_decode($catJson, true);
                    if (is_array($catArr)) {
                        foreach ($catArr as $name => $val) {
                            $defectReportCatAgg[$name] = ($defectReportCatAgg[$name] ?? 0.0) + (float) $val;
                        }
                    }
                }

                $qcCatJson = $row['qc_defect_category_json'] ?? '';
                if ($qcCatJson) {
                    $qcCatArr = json_decode($qcCatJson, true);
                    if (is_array($qcCatArr)) {
                        foreach ($qcCatArr as $name => $val) {
                            $defectQcCatAgg[$name] = ($defectQcCatAgg[$name] ?? 0.0) + (float) $val;
                        }
                    }
                }
            }

            $defectReportPieByCat = [];
            foreach ($defectReportCatAgg as $name => $val) {
                $defectReportPieByCat[] = ['name' => $name, 'value' => round($val, 3)];
            }
            usort($defectReportPieByCat, static function ($a, $b) {
                return $b['value'] <=> $a['value'];
            });

            $defectQcPieByCat = [];
            foreach ($defectQcCatAgg as $name => $val) {
                $defectQcPieByCat[] = ['name' => $name, 'value' => round($val, 3)];
            }
            usort($defectQcPieByCat, static function ($a, $b) {
                return $b['value'] <=> $a['value'];
            });
        } else {
            /* 兜底：daily_summary 无数据时走实时查询 */
            $monthStartDT = $monthStart . ' 00:00:00';
            $monthEndDT = $today . ' 23:59:59';

            $reportDefectMonth = (float) Db::name('product_report')
                ->where('is_delete', 0)
                ->whereBetween('create_time', [$monthStartDT, $monthEndDT])
                ->sum('defective_num');

            $qcDefectMonth = 0.0;
            try {
                $qcRow = Db::name('quality_check_defect')
                    ->alias('d')
                    ->leftJoin('quality_check_item i', 'i.id = d.item_id')
                    ->leftJoin('quality_check_record r', 'r.id = i.record_id')
                    ->whereBetween('r.check_date', [$monthStart, $today])
                    ->fieldRaw('SUM(d.day_qty + d.night_qty) AS t')
                    ->find();
                if (!empty($qcRow['t'])) {
                    $qcDefectMonth = (float) $qcRow['t'];
                }
            } catch (\Throwable $e) {
                $qcDefectMonth = 0.0;
            }

            $defectReportPieByCat = self::dashboardReportDefectsByCategory($monthStartDT, $monthEndDT);
            $defectQcPieByCat = self::dashboardQcDefectsByCategory($monthStart, $today);
        }

        $defectPie = [
            ['name' => '报工登记不良', 'value' => round($reportDefectMonth, 3)],
            ['name' => '质检提报不良', 'value' => round($qcDefectMonth, 3)],
        ];

        $productionModel = new ProductionModel();
        $planList = Db::name('production')
            ->where('is_delete', 0)
            ->order('create_time', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        $plans = [];
        foreach ($planList as $p) {
            try {
                $code = $p['production_plan_code'] ?? '';
                $planQty = (float) $productionModel->getPlanWithJoinStats($code);
                $prog = self::planProgressMetrics($code, $planQty);
                $st = (int) ($p['status'] ?? 0);
                $statusLabel = $st === 0 ? '未开始' : ($st === 1 ? '生产中' : '已完成');
                $plans[] = self::buildPlanCockpitRow(
                    $code,
                    $st,
                    $statusLabel,
                    (string) ($p['production_plan_name'] ?? ''),
                    $planQty,
                    $prog
                );
            } catch (\Throwable $e) {
                continue;
            }
        }
        /* production 表无有效计划时，用工单上的计划单号兜底（常见于历史库或计划未建档但有工单） */
        if ($plans === []) {
            try {
                $codes = Db::name('product_task')
                    ->where('is_delete', 0)
                    ->where('relation_code', '<>', '')
                    ->order('id', 'desc')
                    ->column('relation_code');
                $codes = array_values(array_unique(array_filter($codes)));
                $codes = array_slice($codes, 0, 30);
                foreach ($codes as $code) {
                    try {
                        $row = Db::name('production')
                            ->where('production_plan_code', $code)
                            ->where('is_delete', 0)
                            ->find();
                        $planQty = (float) $productionModel->getPlanWithJoinStats($code);
                        $prog = self::planProgressMetrics($code, $planQty);
                        if ($row) {
                            $st = (int) ($row['status'] ?? 0);
                            $statusLabel = $st === 0 ? '未开始' : ($st === 1 ? '生产中' : '已完成');
                            $plans[] = self::buildPlanCockpitRow(
                                $code,
                                $st,
                                $statusLabel,
                                (string) ($row['production_plan_name'] ?? ''),
                                $planQty,
                                $prog
                            );
                        } else {
                            $plans[] = self::buildPlanCockpitRow(
                                $code,
                                1,
                                '生产中',
                                '生产中（工单）',
                                $planQty,
                                $prog
                            );
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // 忽略兜底失败
            }
        }
        /* production / 工单均无计划编码时，用报工里的 plan_code 再兜底 */
        if ($plans === []) {
            try {
                $since = date('Y-m-d 00:00:00', strtotime('-180 day'));
                $until = date('Y-m-d 23:59:59');
                $codeRows = Db::name('product_report')
                    ->where('is_delete', 0)
                    ->where('plan_code', '<>', '')
                    ->whereBetween('create_time', [$since, $until])
                    ->order('id', 'desc')
                    ->limit(400)
                    ->column('plan_code');
                $seen = [];
                $codes = [];
                foreach ($codeRows as $c) {
                    if ($c === '' || $c === null || isset($seen[$c])) {
                        continue;
                    }
                    $seen[$c] = true;
                    $codes[] = $c;
                    if (count($codes) >= 30) {
                        break;
                    }
                }
                foreach ($codes as $code) {
                    try {
                        $row = Db::name('production')
                            ->where('production_plan_code', $code)
                            ->where('is_delete', 0)
                            ->find();
                        $planQty = (float) $productionModel->getPlanWithJoinStats($code);
                        $prog = self::planProgressMetrics($code, $planQty);
                        if ($row) {
                            $st = (int) ($row['status'] ?? 0);
                            $statusLabel = $st === 0 ? '未开始' : ($st === 1 ? '生产中' : '已完成');
                            $plans[] = self::buildPlanCockpitRow(
                                $code,
                                $st,
                                $statusLabel,
                                (string) ($row['production_plan_name'] ?? ''),
                                $planQty,
                                $prog
                            );
                        } else {
                            $plans[] = self::buildPlanCockpitRow(
                                $code,
                                1,
                                '生产中',
                                '生产中（报工）',
                                $planQty,
                                $prog
                            );
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // 忽略兜底失败
            }
        }

        $realtimeOutputs = self::topProcessOutputToday(4);
        $yesterdayOutputs = self::topProcessOutputYesterday(5);

        return [
            'updated_at' => date('Y-m-d H:i:s'),
            'devices' => $deviceRows,
            'energy' => [
                'yesterday_label' => $cockpitYesterday,
                'month_label' => date('Y年m月'),
                'yesterday' => $energyYesterday,
                'month' => $energyMonth,
            ],
            'defect_pie_month' => $defectPie,
            'defect_report_pie_by_category' => $defectReportPieByCat,
            'defect_qc_pie_by_category' => $defectQcPieByCat,
            'defect_month_meta' => [
                'report_defective_sum' => round($reportDefectMonth, 3),
                'qc_defective_sum' => round($qcDefectMonth, 3),
            ],
            'plans' => $plans,
            'realtime_outputs' => $realtimeOutputs,
            'yesterday_outputs' => $yesterdayOutputs,
        ];
    }

    /**
     * 驾驶舱「工序能耗」：直接汇总能耗抄表（report_date，12:00 分界与报工/抄表录入一致）
     *
     * @param string $startDate 起始日期 Y-m-d
     * @param string $endDate   结束日期 Y-m-d
     */
    private static function energyCockpitFromMeterReading(string $startDate, string $endDate): array
    {
        $acc = self::emptyCockpitEnergyBuckets();

        try {
            $records = Db::name('product_power')
                ->alias('p')
                ->leftJoin('device d', 'd.device_code = p.device_code AND d.is_delete = 0')
                ->leftJoin(
                    'processes pr',
                    'pr.processes_code = COALESCE(NULLIF(p.processes_code, ""), d.processes_code) AND pr.is_delete = 0'
                )
                ->where('p.is_delete', 0)
                ->whereRaw(
                    'COALESCE(p.report_date, DATE(p.create_time)) BETWEEN ? AND ?',
                    [$startDate, $endDate]
                )
                ->field('p.gas_consume, p.power_consume, d.device_name, pr.processes_name')
                ->select()
                ->toArray();

            foreach ($records as $rec) {
                $bucket = self::resolveCockpitEnergyBucket(
                    (string) ($rec['device_name'] ?? ''),
                    (string) ($rec['processes_name'] ?? '')
                );
                if ($bucket === null) {
                    continue;
                }
                $acc[$bucket]['gas'] += (float) ($rec['gas_consume'] ?? 0);
                if ($bucket !== '酸洗') {
                    $acc[$bucket]['power'] += (float) ($rec['power_consume'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            // 抄表表不可用时返回三工序零值，避免整屏报错
        }

        return self::formatCockpitEnergyBuckets($acc);
    }

    /**
     * @return array<string, array{power: float, gas: float, show_power: bool}>
     */
    private static function emptyCockpitEnergyBuckets(): array
    {
        return [
            '穿孔' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => true],
            '球化' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => true],
            '酸洗' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => false],
        ];
    }

    /**
     * 抄表记录归入大屏三工序：穿孔 / 球化 / 酸洗
     */
    private static function resolveCockpitEnergyBucket(string $deviceName, string $processesName): ?string
    {
        $cat = DailyReportConfig::inferCategory($deviceName);
        if (in_array($cat, ['穿孔', '球化', '酸洗'], true)) {
            return $cat;
        }
        foreach (['酸洗', '球化', '穿孔'] as $label) {
            if (
                ($processesName !== '' && strpos($processesName, $label) !== false)
                || ($deviceName !== '' && strpos($deviceName, $label) !== false)
            ) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{power: float, gas: float, show_power: bool}> $acc
     */
    private static function formatCockpitEnergyBuckets(array $acc): array
    {
        $order = ['穿孔', '球化', '酸洗'];
        $out = [];
        foreach ($order as $label) {
            $b = $acc[$label];
            $out[] = [
                'processes_code' => '',
                'processes_name' => $label,
                'power_kwh' => $b['show_power'] ? round($b['power'], 2) : null,
                'gas' => round($b['gas'], 2),
                'show_power' => $b['show_power'],
            ];
        }

        return $out;
    }

    /**
     * @deprecated 驾驶舱已改用 energyCockpitFromMeterReading
     */
    private static function energyFromDailySummary(string $startDate, string $endDate): array
    {
        $acc = [
            '穿孔' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => true],
            '球化' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => true],
            '酸洗' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => false],
        ];

        $rows = Db::name('daily_summary')
            ->whereBetween('report_date', [$startDate, $endDate])
            ->where('processes_name', '<>', '日合计')
            ->field('processes_name, SUM(gas_consume) AS gas, SUM(power_consume) AS power')
            ->group('processes_name')
            ->select()
            ->toArray();

        foreach ($rows as $r) {
            $name = $r['processes_name'] ?? '';
            $pw = (float) ($r['power'] ?? 0);
            $gs = (float) ($r['gas'] ?? 0);

            if (strpos($name, '酸洗') !== false) {
                $acc['酸洗']['gas'] += $gs;
                continue;
            }
            if (strpos($name, '球化') !== false) {
                $acc['球化']['power'] += $pw;
                $acc['球化']['gas'] += $gs;
                continue;
            }
            if (strpos($name, '穿孔') !== false) {
                $acc['穿孔']['power'] += $pw;
                $acc['穿孔']['gas'] += $gs;
            }
        }

        $order = ['穿孔', '球化', '酸洗'];
        $out = [];
        foreach ($order as $label) {
            $b = $acc[$label];
            $out[] = [
                'processes_code' => '',
                'processes_name' => $label,
                'power_kwh' => $b['show_power'] ? round($b['power'], 2) : null,
                'gas' => round($b['gas'], 2),
                'show_power' => $b['show_power'],
            ];
        }
        return $out;
    }

    /**
     * 参观大屏：穿孔/球化（电+气）、酸洗（仅气）——按设备名称关键字汇总抄表
     */
    private static function energyKeyProcessRange(string $start, string $end): array
    {
        $acc = [
            '穿孔' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => true],
            '球化' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => true],
            '酸洗' => ['power' => 0.0, 'gas' => 0.0, 'show_power' => false],
        ];

        $records = Db::name('product_power')
            ->alias('p')
            ->leftJoin('device d', 'd.device_code = p.device_code')
            ->where('p.is_delete', 0)
            ->whereBetween('p.create_time', [$start, $end])
            ->field('p.power_consume, p.gas_consume, d.device_name')
            ->select()
            ->toArray();

        foreach ($records as $rec) {
            $deviceName = (string) ($rec['device_name'] ?? '');
            $pw = (float) ($rec['power_consume'] ?? 0);
            $gs = (float) ($rec['gas_consume'] ?? 0);

            if (strpos($deviceName, '酸洗') !== false) {
                $acc['酸洗']['gas'] += $gs;
                continue;
            }
            if (strpos($deviceName, '球化') !== false) {
                $acc['球化']['power'] += $pw;
                $acc['球化']['gas'] += $gs;
                continue;
            }
            if (strpos($deviceName, '穿孔') !== false) {
                $acc['穿孔']['power'] += $pw;
                $acc['穿孔']['gas'] += $gs;
            }
        }

        $order = ['穿孔', '球化', '酸洗'];
        $out = [];
        foreach ($order as $label) {
            $b = $acc[$label];
            $out[] = [
                'processes_code' => '',
                'processes_name' => $label,
                'power_kwh' => $b['show_power'] ? round($b['power'], 2) : null,
                'gas' => round($b['gas'], 2),
                'show_power' => $b['show_power'],
            ];
        }

        return $out;
    }

    private static function energyByProcessRange(string $start, string $end): array
    {
        $rows = Db::name('product_power')
            ->alias('p')
            ->leftJoin('processes pr', 'pr.processes_code = p.processes_code')
            ->where('p.is_delete', 0)
            ->whereNotNull('p.processes_code')
            ->where('p.processes_code', '<>', '')
            ->whereBetween('p.create_time', [$start, $end])
            ->field('p.processes_code, pr.processes_name, SUM(p.power_consume) AS power_sum, SUM(p.gas_consume) AS gas_sum')
            ->group('p.processes_code, pr.processes_name')
            ->order('power_sum', 'desc')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $r) {
            $code = $r['processes_code'] ?? '';
            $name = $r['processes_name'] ?? $code;
            $out[] = [
                'processes_code' => $code,
                'processes_name' => $name ?: $code,
                'power_kwh' => round((float) ($r['power_sum'] ?? 0), 2),
                'gas' => round((float) ($r['gas_sum'] ?? 0), 2),
            ];
        }

        return $out;
    }

    private static function planProgressMetrics(string $planCode, float $planTotal): array
    {
        if ($planCode === '') {
            return ['percent' => 0, 'done' => 0.0];
        }
        $tasks = Db::name('product_task')
            ->where('relation_code', $planCode)
            ->where('is_delete', 0)
            ->select()
            ->toArray();

        $finallyCodes = Db::name('processes')->where('is_finnally', 1)->column('processes_code');
        $maxDone = 0.0;
        foreach ($tasks as $t) {
            $report = json_decode($t['report'] ?? '', true);
            if (!is_array($report)) {
                continue;
            }
            foreach ($finallyCodes as $fc) {
                if (isset($report[$fc]['report_num'])) {
                    $v = (float) $report[$fc]['report_num'];
                    $maxDone = max($maxDone, $v);
                }
            }
        }

        $total = max(0.0, $planTotal);
        if ($total > 0) {
            $percent = min(100.0, round($maxDone / $total * 100, 1));
        } else {
            $percent = $maxDone > 0 ? 100.0 : 0.0;
        }

        return ['percent' => $percent, 'done' => round($maxDone, 2)];
    }

    private static function topProcessOutputToday(int $limit): array
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $rows = Db::name('product_report')
            ->alias('r')
            ->leftJoin('processes pr', 'pr.processes_code = r.processes_code')
            ->where('r.is_delete', 0)
            ->whereBetween('r.create_time', [$todayStart, $todayEnd])
            ->whereNotNull('r.processes_code')
            ->where('r.processes_code', '<>', '')
            ->field('r.processes_code, pr.processes_name, SUM(r.report_num) AS qty')
            ->group('r.processes_code, pr.processes_name')
            ->order('qty', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'processes_code' => $r['processes_code'] ?? '',
                'processes_name' => $r['processes_name'] ?? ($r['processes_code'] ?? ''),
                'qty' => round((float) ($r['qty'] ?? 0), 2),
            ];
        }

        while (count($out) < $limit) {
            $out[] = [
                'processes_code' => '',
                'processes_name' => '—',
                'qty' => 0,
            ];
        }

        return $out;
    }

    private static function topProcessOutputYesterday(int $limit): array
    {
        $yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $yesterdayEnd = date('Y-m-d 23:59:59', strtotime('-1 day'));
        $rows = Db::name('product_report')
            ->alias('r')
            ->leftJoin('processes pr', 'pr.processes_code = r.processes_code')
            ->where('r.is_delete', 0)
            ->whereBetween('r.create_time', [$yesterdayStart, $yesterdayEnd])
            ->whereNotNull('r.processes_code')
            ->where('r.processes_code', '<>', '')
            ->field('r.processes_code, pr.processes_name, SUM(r.report_num) AS qty')
            ->group('r.processes_code, pr.processes_name')
            ->order('qty', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r['qty'] ?? 0);
        }

        $out = ['total' => round($total, 2), 'items' => []];
        foreach ($rows as $r) {
            $out['items'][] = [
                'processes_code' => $r['processes_code'] ?? '',
                'processes_name' => $r['processes_name'] ?? ($r['processes_code'] ?? ''),
                'qty' => round((float) ($r['qty'] ?? 0), 2),
            ];
        }

        if (empty($out['items'])) {
            $out['items'][] = ['processes_code' => '', 'processes_name' => '暂无数据', 'qty' => 0];
        }

        return $out;
    }
}
