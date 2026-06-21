<?php

namespace app\common\service\admin;

use app\common\model\admin\DailySummaryModel;
use app\common\model\admin\ReportCoefficientModel;
use think\facade\Db;

class DailySummaryService
{
    private static function ensureTable()
    {
        DatabaseInitService::ensure();
    }

    public static function refresh($reportDate, $processesName = '')
    {
        self::ensureTable();
        $now = date('Y-m-d H:i:s');
        $configPath = app()->getBasePath() . 'common/config/DailyReportConfig.php';
        if (!file_exists($configPath)) {
            return;
        }
        $processes = \app\common\config\DailyReportConfig::getAllProcesses();

        foreach ($processes as $procName) {
            if (in_array($procName, ['损耗', '合计', '日合计'])) {
                continue;
            }
            if ($processesName && $processesName !== $procName) {
                continue;
            }

            $deviceInfo = \app\common\config\DailyReportConfig::getDeviceInfo($procName);
            if (\app\common\config\DailyReportConfig::isStatisticsProcess($procName)) {
                continue;
            }

            foreach ([0, 1] as $class) {
                $productionQty = self::calcProductionQty($reportDate, $procName, $deviceInfo, $class);
                $defectiveReportQty = self::calcDefectiveReportQty($reportDate, $deviceInfo, $class);
                $energy = self::calcEnergy($reportDate, $procName, $deviceInfo, $class);
                $accessory = self::calcAccessory($reportDate, $procName, $deviceInfo);
                $guide = self::calcGuide($reportDate, $procName, $deviceInfo);
                $wage = self::calcWage($reportDate, $procName, $deviceInfo, $class);

                $gasPrice = ReportCoefficientModel::getCoefficientByDate('gas', $reportDate) ?? 3.98;
                $powerPrice = ReportCoefficientModel::getCoefficientByDate('power', $reportDate) ?? 0.88;

                $row = [
                    'production_qty'       => round($productionQty, 3),
                    'defective_report_qty' => round($defectiveReportQty, 3),
                    'gas_consume'          => round($energy['gas_num'], 3),
                    'power_consume'        => round($energy['power_num'], 3),
                    'gas_amount'           => round($energy['gas_num'] * $gasPrice, 2),
                    'power_amount'         => round($energy['power_num'] * $powerPrice, 2),
                    'accessory_amount'     => round($accessory, 2),
                    'guide_amount'         => round($guide, 2),
                    'wage_amount'          => round($wage, 2),
                    'update_time'          => $now,
                ];

                $exist = Db::name('daily_summary')
                    ->where('report_date', $reportDate)
                    ->where('processes_name', $procName)
                    ->where('class', $class)
                    ->find();

                if ($exist) {
                    Db::name('daily_summary')->where('id', $exist['id'])->update($row);
                } else {
                    $row['report_date'] = $reportDate;
                    $row['processes_name'] = $procName;
                    $row['class'] = $class;
                    $row['create_time'] = $now;
                    Db::name('daily_summary')->insert($row);
                }
            }
        }
        /* 写入日合计（含不良分类明细） */
        self::saveDailyTotalRow($reportDate, $now);
    }

    public static function getSummary($reportDate, $processesName = '')
    {
        self::ensureTable();
        $query = Db::name('daily_summary')->where('report_date', $reportDate);
        if ($processesName) {
            $query->where('processes_name', $processesName);
        }
        return $query->select()->toArray();
    }

    public static function hasSummary($reportDate)
    {
        self::ensureTable();
        return Db::name('daily_summary')->where('report_date', $reportDate)->count() > 0;
    }

    public static function refreshHistory($startDate, $endDate)
    {
        $current = $startDate;
        $count = 0;
        while (strtotime($current) <= strtotime($endDate)) {
            self::refresh($current);
            $count++;
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }
        return "已回填 {$count} 天的汇总数据";
    }

    private static function calcProductionQty($reportDate, $processName, $deviceInfo, $class)
    {
        $query = Db::name('product_report')
            ->where('report_date', $reportDate)
            ->where('is_delete', 0)
            ->where('class', $class);

        self::applyDeviceCondition($query, $deviceInfo);
        return floatval($query->sum('report_num'));
    }

    private static function calcEnergy($reportDate, $processName, $deviceInfo, $class)
    {
        $result = ['gas_num' => 0, 'power_num' => 0];
        $query = Db::name('product_power')
            ->where('report_date', $reportDate)
            ->where('class', $class);

        self::applyDeviceCondition($query, $deviceInfo, true);

        $records = $query->select()->toArray();
        foreach ($records as $record) {
            $result['gas_num'] += floatval($record['gas_consume'] ?? 0);
            $result['power_num'] += floatval($record['power_consume'] ?? 0);
        }
        return $result;
    }

    private static function calcAccessory($reportDate, $processName, $deviceInfo)
    {
        $query = Db::name('receiving')
            ->alias('r')
            ->leftJoin('material m', 'r.material_id = m.id')
            ->leftJoin('wms_out o', 'o.relation_code = r.receiving_code AND o.status = 1')
            ->leftJoin('wms_in i', 'i.material_id = r.material_id AND i.material_batch = o.material_batch')
            ->where('r.create_time', '>=', $reportDate . ' 00:00:00')
            ->where('r.create_time', '<=', $reportDate . ' 23:59:59')
            ->where('r.is_delete', 0)
            ->where('r.status', 1)
            ->where(function ($q) {
                $q->whereNull('m.material_name')
                  ->whereOr(function ($subQ) {
                      $subQ->whereNotLike('m.material_name', '%顶头%')
                           ->whereNotLike('m.material_name', '%导板%');
                  });
            });

        self::applyReceivingDeviceCondition($query, $deviceInfo);

        $data = $query->field('r.material_num, i.money as in_price')->select()->toArray();
        $total = 0;
        foreach ($data as $item) {
            $total += floatval($item['material_num'] ?? 0) * floatval($item['in_price'] ?? 0);
        }
        return $total;
    }

    private static function calcGuide($reportDate, $processName, $deviceInfo)
    {
        $query = Db::name('receiving')
            ->alias('r')
            ->leftJoin('material m', 'r.material_id = m.id')
            ->leftJoin('wms_out o', 'o.relation_code = r.receiving_code AND o.status = 1')
            ->leftJoin('wms_in i', 'i.material_id = r.material_id AND i.material_batch = o.material_batch')
            ->where('r.create_time', '>=', $reportDate . ' 00:00:00')
            ->where('r.create_time', '<=', $reportDate . ' 23:59:59')
            ->where('r.is_delete', 0)
            ->where('r.status', 1)
            ->where(function ($q) {
                $q->whereLike('m.material_name', '%顶头%')
                  ->whereOr('m.material_name', '%导板%');
            });

        self::applyReceivingDeviceCondition($query, $deviceInfo);

        $data = $query->field('r.material_num, i.money as in_price')->select()->toArray();
        $total = 0;
        foreach ($data as $item) {
            $total += floatval($item['material_num'] ?? 0) * floatval($item['in_price'] ?? 0);
        }
        return $total;
    }

    private static function calcWage($reportDate, $processName, $deviceInfo, $class)
    {
        $query = Db::name('user_wage_details')
            ->where('date', $reportDate)
            ->where('is_delete', 0)
            ->where('class', $class);

        self::applyDeviceCondition($query, $deviceInfo, true);
        return floatval($query->sum('wage_sum'));
    }

    private static function applyDeviceCondition($query, $deviceInfo, $isEnergyTable = false)
    {
        $deviceNameMap = [
            '球化南1' => '球化南', '酸洗车间' => '酸洗车间',
            '穿孔40机' => '穿孔40机', '穿孔50机' => '穿孔50机', '球化北' => '球化北',
        ];

        // [AI-2026-05] 兼容没有 device_code 字段的旧表数据（如 ya_product_report），回退到数据 JSON 模糊匹配
        if ($deviceInfo['type'] === 'multiple') {
            $deviceNames = $deviceInfo['devices'];
            $deviceCodes = Db::name('device')
                ->whereIn('device_name', $deviceNames)
                ->where('is_delete', 0)
                ->column('device_code');
            
            if (!$isEnergyTable) {
                $hasDeviceCode = false;
                try {
                    $row = Db::query("SHOW COLUMNS FROM ya_product_report LIKE 'device_code'");
                    $hasDeviceCode = !empty($row);
                } catch (\Throwable $e) {}
                
                $query->where(function ($q) use ($deviceInfo, $deviceCodes, $hasDeviceCode) {
                    if ($hasDeviceCode && !empty($deviceCodes)) {
                        $q->whereIn('device_code', $deviceCodes);
                    }
                    foreach ($deviceCodes as $dc) {
                        $q->whereOr('data', 'like', '%"device_code":"' . $dc . '"%');
                    }
                    foreach (($deviceInfo['devices'] ?? []) as $deviceName) {
                        $jsonName = trim(json_encode($deviceName), '"');
                        $q->whereOr('data', 'like', '%"device_name":"' . $deviceName . '"%')
                          ->whereOr('data', 'like', '%"device_name":"' . $jsonName . '"%');
                    }
                });
            } else {
                if (!empty($deviceCodes)) {
                    $query->whereIn('device_code', $deviceCodes);
                }
            }
        } elseif ($deviceInfo['type'] === 'single') {
            $deviceName = $deviceInfo['device'];
            $mapped = $deviceNameMap[$deviceName] ?? $deviceName;
            $deviceCode = Db::name('device')
                ->where('device_name', $mapped)
                ->where('is_delete', 0)
                ->value('device_code');
            
            if (!$isEnergyTable) {
                $jsonName = trim(json_encode($deviceName), '"');
                $query->where(function ($q) use ($deviceName, $jsonName, $deviceCode) {
                    $q->where('data', 'like', '%"device_name":"' . $deviceName . '"%')
                      ->whereOr('data', 'like', '%"device_name":"' . $jsonName . '"%');
                    if (!empty($deviceCode)) {
                        $q->whereOr('data', 'like', '%"device_code":"' . $deviceCode . '"%');
                    }
                });
                if (isset($deviceInfo['shift']) && $deviceInfo['shift'] !== 'all') {
                    $shiftType = $deviceInfo['shift'] === '白班' ? 0 : 1;
                    $query->where('class', $shiftType);
                }
            } else {
                if ($deviceCode) {
                    $query->where('device_code', $deviceCode);
                } else {
                    $query->where('device_code', $mapped);
                }
            }
        }
    }

    private static function applyReceivingDeviceCondition($query, $deviceInfo)
    {
        $deviceNameMap = [
            '球化南1' => '球化南', '酸洗车间' => '酸洗车间',
            '穿孔40机' => '穿孔40机', '穿孔50机' => '穿孔50机', '球化北' => '球化北',
        ];

        if ($deviceInfo['type'] === 'multiple') {
            $deviceNames = $deviceInfo['devices'];
            $deviceCodes = Db::name('device')
                ->whereIn('device_name', $deviceNames)
                ->where('is_delete', 0)
                ->column('device_code');
            if (!empty($deviceCodes)) {
                $query->whereIn('r.relation_code', $deviceCodes);
            }
        } elseif ($deviceInfo['type'] === 'single') {
            $deviceName = $deviceInfo['device'];
            $mapped = $deviceNameMap[$deviceName] ?? $deviceName;
            $deviceCode = Db::name('device')
                ->where('device_name', $mapped)
                ->where('is_delete', 0)
                ->value('device_code');
            if ($deviceCode) {
                $query->where('r.relation_code', $deviceCode);
            } else {
                $query->where('r.relation_code', $mapped);
            }
        }
    }

    /**
     * 计算报工不良数（每工序每班次）
     */
    private static function calcDefectiveReportQty($reportDate, $deviceInfo, $class)
    {
        $query = Db::name('product_report')
            ->where('report_date', $reportDate)
            ->where('is_delete', 0)
            ->where('class', $class);

        self::applyDeviceCondition($query, $deviceInfo);
        return floatval($query->sum('defective_num'));
    }

    /**
     * 写入日合计行（含不良分类明细JSON）
     */
    private static function saveDailyTotalRow($reportDate, $now)
    {
        $rows = Db::name('daily_summary')
            ->where('report_date', $reportDate)
            ->where('processes_name', '<>', '日合计')
            ->select()
            ->toArray();

        $total = [
            'production_qty'       => 0.0,
            'defective_report_qty' => 0.0,
            'gas_consume'          => 0.0,
            'power_consume'        => 0.0,
            'gas_amount'           => 0.0,
            'power_amount'         => 0.0,
            'accessory_amount'     => 0.0,
            'guide_amount'         => 0.0,
            'wage_amount'          => 0.0,
        ];

        foreach ($rows as $r) {
            foreach ($total as $k => &$v) {
                $v += floatval($r[$k] ?? 0);
            }
            unset($v);
        }

        /* 计算日期级别的质检不良数 */
        $qcDefectTotal = 0.0;
        try {
            $qcRow = Db::name('quality_check_defect')
                ->alias('d')
                ->leftJoin('quality_check_item i', 'i.id = d.item_id')
                ->leftJoin('quality_check_record r', 'r.id = i.record_id')
                ->where('r.check_date', $reportDate)
                ->fieldRaw('SUM(d.day_qty + d.night_qty) AS t')
                ->find();
            if (!empty($qcRow['t'])) {
                $qcDefectTotal = (float) $qcRow['t'];
            }
        } catch (\Throwable $e) {
            $qcDefectTotal = 0.0;
        }

        /* 报工不良分类明细（按天的JSON） */
        $reportCategoryAgg = [];
        try {
            $jsons = Db::name('product_report')
                ->where('is_delete', 0)
                ->where('report_date', $reportDate)
                ->whereNotNull('defective')
                ->where('defective', '<>', '')
                ->column('defective');
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
                    $reportCategoryAgg[$name] = ($reportCategoryAgg[$name] ?? 0.0) + $num;
                }
            }
        } catch (\Throwable $e) {
        }

        /* 质检不良分类明细（按天的JSON） */
        $qcCategoryAgg = [];
        try {
            $rows = Db::name('quality_check_defect')
                ->alias('d')
                ->leftJoin('quality_check_item i', 'i.id = d.item_id')
                ->leftJoin('quality_check_record r', 'r.id = i.record_id')
                ->where('r.check_date', $reportDate)
                ->field('d.defect_type, d.defect_text, d.day_qty, d.night_qty')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $txt = trim((string) ($row['defect_text'] ?? ''));
                $typ = trim((string) ($row['defect_type'] ?? ''));
                $cat = $txt !== '' ? $txt : ($typ !== '' ? $typ : '未分类');
                $qty = (float) ($row['day_qty'] ?? 0) + (float) ($row['night_qty'] ?? 0);
                $qcCategoryAgg[$cat] = ($qcCategoryAgg[$cat] ?? 0.0) + $qty;
            }
        } catch (\Throwable $e) {
        }

        $dailyRow = [
            'production_qty'             => round($total['production_qty'], 3),
            'defective_report_qty'       => round($total['defective_report_qty'], 3),
            'defective_qc_qty'           => round($qcDefectTotal, 3),
            'gas_consume'                => round($total['gas_consume'], 3),
            'power_consume'              => round($total['power_consume'], 3),
            'gas_amount'                 => round($total['gas_amount'], 2),
            'power_amount'               => round($total['power_amount'], 2),
            'accessory_amount'           => round($total['accessory_amount'], 2),
            'guide_amount'               => round($total['guide_amount'], 2),
            'wage_amount'                => round($total['wage_amount'], 2),
            'report_defect_category_json' => !empty($reportCategoryAgg) ? json_encode($reportCategoryAgg, JSON_UNESCAPED_UNICODE) : null,
            'qc_defect_category_json'    => !empty($qcCategoryAgg) ? json_encode($qcCategoryAgg, JSON_UNESCAPED_UNICODE) : null,
            'update_time'                => $now,
        ];

        $exist = Db::name('daily_summary')
            ->where('report_date', $reportDate)
            ->where('processes_name', '日合计')
            ->where('class', 0)
            ->find();

        if ($exist) {
            Db::name('daily_summary')->where('id', $exist['id'])->update($dailyRow);
        } else {
            $dailyRow['report_date'] = $reportDate;
            $dailyRow['processes_name'] = '日合计';
            $dailyRow['class'] = 0;
            $dailyRow['create_time'] = $now;
            Db::name('daily_summary')->insert($dailyRow);
        }
    }
}
