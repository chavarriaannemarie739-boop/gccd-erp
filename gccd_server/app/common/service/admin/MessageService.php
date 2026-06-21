<?php

// +----------------------------------------------------------------------
// | yylAdmin 前后分离，简单轻量，免费开源，开箱即用，极简后台管理系统
// +----------------------------------------------------------------------
// | Copyright https://gitee.com/skyselang All rights reserved
// +----------------------------------------------------------------------
// | Gitee: https://gitee.com/skyselang/yylAdmin
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\DeviceModel;
use app\common\model\admin\InStockModel;
use app\common\model\admin\PowerModel;
use app\common\model\admin\ProcessesMessageModel;
use app\common\model\admin\MessageModel;
use app\common\model\admin\ProcessesModel;
use app\common\model\admin\ProductPowerModel;
use app\common\model\admin\ProductReportModel;
use app\common\model\admin\ProductTaskModel;
use app\common\model\admin\ReceivingModel;
use app\common\model\admin\UserWageDetailModel;
use app\common\model\admin\WageModel;
use app\common\service\wechat\Exception;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use think\console\output\Ask;
use think\facade\App;
use think\facade\Db;
use think\facade\Filesystem;

/**
 *
 */
class MessageService
{
    /**
     * 添加、修改字段
     * @var array
     */
    public static $edit_field = [
        'id' => '',
        'device_name' => '',
        'device_code' => '',
        'device_project' => '',//
        'reason' => '',
        'user_id' => '',
    ];

    /*搜索的字段内容获取*/
    public static $search_field = [
        'device_name' => '',
        'device_code' => '',
        'device_project' => '',//
        'reason' => '',
        'material_code' => '',
        'create_start_date' => '',
        'create_end_date' => '',
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
        $model = new MessageModel();
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
            $model = new MessageModel();
            $info = $model->find($id);
            if (empty($info)) {
                if ($exce) {
                    exception('当前id为:' . $id . '的工序信息不存在');
                }
                return [];
            }
            /*同步修改通知已读状态*/
            $model->where('id', $id)->update(['is_watch' => 1]);
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
            $model = new MessageModel();
            $pk = $model->getPk();
            /*自动生产编码*/
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
        $model = new MessageModel();
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
        $model = new MessageModel();
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
    /*添加工序检验*/
    public static function addcheck($param)
    {
        Db::startTrans();
        try {
            $model = new ProcessesMessageModel();
            $pk = $model->getPk();
            /*自动生产编码*/
            $data = $model
                ->where('check_code', $param['check_code'])
                ->where('process_code', $param['process_code'])
                ->find();
            if (!empty($data)) {
                \exception('同一道工序不允许创建相同的检验项');
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
     * 导出pdf文件
     * @return
     */
    public static function export()
    {
        /*获取设备列表*/
        $date = "2026-01-06";
        $device_model = new DeviceModel();
        $device_data = $device_model->select()->toArray();

        $report_model = new ProductReportModel();
        $power_model = new ProductPowerModel();
        $receive_model = new ReceivingModel();
        $wage_model = new UserWageDetailModel();
        $task_model = new ProductTaskModel();

// 提取所有设备编码和工序编码
        $deviceCodes = array_column($device_data, 'device_code');
        $processesCodes = array_column($device_data, 'processes_code');

// 1. 批量查询报工数据 - 修改这里
        $allReports = $report_model
            ->whereIn('processes_code', $processesCodes)
            ->whereTime('create_time', 'between', [$date . ' 00:00:00', $date . ' 23:59:59'])
            ->select()
            ->toArray();

// 2. 批量查询任务型号
        $taskCodes = array_unique(array_column($allReports, 'relation_code'));
        $taskData = [];
        if (!empty($taskCodes)) {
            $taskList = $task_model
                ->alias('a')
                ->whereIn('a.task_code', $taskCodes)
                ->leftJoin('ya_production b', 'b.production_plan_code = a.relation_code')
                ->leftJoin('ya_material c', 'c.id = b.english_material')
                ->field('a.task_code, c.material_model')
                ->select()
                ->toArray();

            foreach ($taskList as $item) {
                $taskData[$item['task_code']] = $item['material_model'] ?? '';
            }
        }

// 3. 按设备名称组织报工数据
        $reportGroupByDevice = [];
        foreach ($allReports as $report) {
            $data = json_decode($report['data'], true);
            if (!is_array($data) || !isset($data['device_name'])) {
                continue;
            }

            $deviceName = $data['device_name'];
            $reportGroupByDevice[$deviceName][] = [
                'report_num' => (float)$report['report_num'],
                'task_code' => $report['relation_code'],
                'material_model' => $taskData[$report['relation_code']] ?? ''
            ];
        }

// 4. 批量查询领料统计 - 修改这里
        $receiveStats = $receive_model->alias('a')
            ->whereTime('a.create_time', 'between', [$date . ' 00:00:00', $date . ' 23:59:59'])
            ->whereIn('a.relation_code', $deviceCodes)
            ->leftJoin('ya_wms_out d', 'd.relation_code = a.receiving_code')
            ->where('d.status', 1)
            ->leftJoin('ya_material b', 'a.material_id = b.id')
            ->leftJoin('ya_wms_in c', 'a.material_id = c.material_id and c.material_batch = d.material_batch')
            ->group('a.relation_code, b.material_name')
            ->field('
        a.relation_code,
        b.material_name,
        SUM(a.material_num) as total_num,
        SUM(c.money * a.material_num) as total_money
    ')
            ->select()
            ->toArray();

// 整理领料数据
        $receiveGroupByDevice = [];
        foreach ($receiveStats as $stat) {
            $code = $stat['relation_code'];
            $materialName = $stat['material_name'];

            if (!isset($receiveGroupByDevice[$code])) {
                $receiveGroupByDevice[$code] = [
                    'normal_num' => 0,
                    'normal_money' => 0,
                    'special_num' => 0,
                    'special_money' => 0
                ];
            }

            $isSpecial = in_array($materialName, ['顶头', '导板']);

            if ($isSpecial) {
                $receiveGroupByDevice[$code]['special_num'] += $stat['total_num'];
                $receiveGroupByDevice[$code]['special_money'] += $stat['total_money'];
            } else {
                $receiveGroupByDevice[$code]['normal_num'] += $stat['total_num'];
                $receiveGroupByDevice[$code]['normal_money'] += $stat['total_money'];
            }
        }

// 5. 批量查询能源消耗 - 修改这里
        $powerStats = $power_model
            ->whereTime('create_time', 'between', [$date . ' 00:00:00', $date . ' 23:59:59'])
            ->whereIn('device_code', $deviceCodes)
            ->group('device_code')
            ->field('
        device_code,
        SUM(gas_consume) as total_gas,
        SUM(power_consume) as total_power
    ')
            ->select()
            ->toArray();

        $powerGroupByDevice = array_column($powerStats, null, 'device_code');

// 6. 批量查询工资
        $wageStats = $wage_model
            ->whereIn('device_code', $deviceCodes)
            ->group('device_code')
            ->field('device_code, SUM(wage) as total_wage')
            ->select()
            ->toArray();

        $wageGroupByDevice = array_column($wageStats, null, 'device_code');

// 7. 组装最终数据
        foreach ($device_data as &$device) {
            $deviceCode = $device['device_code'];
            $deviceName = $device['device_name'];

            // 产量统计
            $totalReportNum = 0;
            $productModels = [];

            if (isset($reportGroupByDevice[$deviceName])) {
                foreach ($reportGroupByDevice[$deviceName] as $report) {
                    $totalReportNum += $report['report_num'];
                    if (!empty($report['material_model'])) {
                        $productModels[] = $report['material_model'];
                    }
                }
            }

            $device['report_num'] = $totalReportNum;
            $device['product_name'] = !empty($productModels) ? implode(',', array_unique($productModels)) : '';

            // 领料统计
            $receiveStat = $receiveGroupByDevice[$deviceCode] ?? [
                'normal_num' => 0,
                'normal_money' => 0,
                'special_num' => 0,
                'special_money' => 0
            ];

            $device['receive_num'] = $receiveStat['normal_num'];
            $device['total1'] = $receiveStat['normal_money'];
            $device['receive_num2'] = $receiveStat['special_num'];
            $device['total2'] = $receiveStat['special_money'];

            // 能源
            $gasStat = $powerGroupByDevice[$deviceCode] ?? null;
            $gasNum = $gasStat['total_gas'] ?? 0;
            $device['gas_num'] = $gasNum;
            $device['gas_money'] = $gasNum * 3.98;

            $powerNum = $gasStat['total_power'] ?? 0;
            $device['power_num'] = $powerNum;
            $device['power_money'] = $powerNum * 0.8;

            // 工资
            $wageStat = $wageGroupByDevice[$deviceCode] ?? null;
            $device['wage'] = $wageStat['total_wage'] ?? 0;
        }
        unset($device);
//        return $device_data;
//        return $device_data;
        /*导出Excel文件*/
        // 2. 创建 Spreadsheet 对象
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 1. 写死表头
        $headers = ['工序', '产量(吨)', '配件(不含顶头导板)(元/吨)', '顶头/导板（元/吨）', '天然气（元/吨）', '电费（元/吨）',
            '工资(元/吨)',
            '成本（元/吨）',
            '型号',
            '配件',
            '天然气 量',
            '天然气 金额',
            '电字',
            '电费',
            '工资',
            '顶头导板',
            '退火丝（元/吨）',
            '盘条（元/吨）',
            '轧辊（元/吨）',
            '电机',
            '辅助工（元/吨）',
            '损耗',
            '电（元/吨）'
        ];
        // 填充表头
        foreach ($headers as $index => $header) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($columnLetter . '1', $header);
        }
        // 2. 填充数据行（按字段对应）
        $row = 2; // 从第2行开始
        foreach ($device_data as $item) {
            // 方法A：直接按顺序对应字段
            $sheet->setCellValue('A' . $row, $item['device_name'] ?? '');
            $sheet->setCellValue('B' . $row, $item['report_num'] ?? ''); //产量
            $sheet->setCellValue('C' . $row, $item['report_num'] != 0
                ? number_format($item['total1'] / $item['report_num'], 2)
                : '0.00'); //配件
            $sheet->setCellValue('D' . $row, $item['report_num'] != 0
                ? number_format($item['total2'] / $item['report_num'], 2)
                : '0.00'); //顶头导板
            $sheet->setCellValue('E' . $row, $item['report_num'] != 0
                ? number_format($item['gas_money'] / $item['report_num'], 2)
                : '0.00');
            $sheet->setCellValue('F' . $row, $item['report_num'] != 0
                ? number_format($item['power_money'] / $item['report_num'], 2)
                : '0.00'); //电费
            $sheet->setCellValue('G' . $row, $item['report_num'] != 0
                ? number_format($item['wage'] / $item['report_num'], 2)
                : '0.00'); //工资
            $sheet->setCellValue('H' . $row, $item['power_money'] ?? ''); //成本
            $sheet->setCellValue('I' . $row, $item['product_name'] ?? ''); //型号
            $sheet->setCellValue('J' . $row, $item['receive_num'] ?? ''); //配件
            $sheet->setCellValue('k' . $row, $item['gas_num'] ?? ''); //天然气 量
            $sheet->setCellValue('L' . $row, $item['gas_money'] ?? ''); //天然气 价格
            $sheet->setCellValue('M' . $row, $item['power_num'] ?? ''); //电表
            $sheet->setCellValue('N' . $row, $item['power_money'] ?? ''); //电费
            $sheet->setCellValue('O' . $row, $item['wage'] ?? ''); //工资
            $sheet->setCellValue('P' . $row, $item['receive_num2'] ?? ''); //定投导板
            $sheet->setCellValue('Q' . $row, $item['power_money'] ?? ''); // 退火丝
            $row++;
        }
        // 4. 设置表头样式
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        // 5. 自动调整列宽
        foreach (range('A', 'D') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // 6. 定义保存路径
        $savePath = app()->getRootPath() . 'public/uploads/'; // 保存到public/uploads/excel目录
        $filename = '生产报表_' . date('Ymd') . '.xlsx';
        $filePath = $savePath . $filename;

        // 7. 创建目录（如果不存在）
        if (!is_dir($savePath)) {
            mkdir($savePath, 0755, true);
        }
        // 8. 保存文件到本地
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        // 9. 返回文件信息
        return [
            'code' => 1,
            'msg' => '导出成功',
            'data' => [
                'filename' => $filename,
                'filepath' => $filePath,
                'url' =>  request()->domain() . '/uploads/' . $filename, // 可访问的URL路径
                'size' => filesize($filePath), // 文件大小
                'time' => date('Y-m-d H:i:s')
            ]
        ];
//        return $device_data;
    }
}
