<?php

// +----------------------------------------------------------------------
// | 高齿传动生产管理系统
// +----------------------------------------------------------------------
// | 日报表服务类
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use app\common\model\admin\ProductReportModel;
use app\common\model\admin\ReportCoefficientModel;
use app\common\config\DailyReportConfig;
use app\common\service\admin\DailySummaryService;
use think\facade\Db;

/**
 * 日报表服务类
 * 负责查询和计算生产统计日报表数据
 */
class DailyReportService
{
    /** 缓存 product_report 是否存在 device_code 字段 */
    private static $productReportHasDeviceCode = null;

    private function productReportHasDeviceCodeColumn(): bool
    {
        if (self::$productReportHasDeviceCode !== null) {
            return self::$productReportHasDeviceCode;
        }
        try {
            $row = Db::query("SHOW COLUMNS FROM ya_product_report LIKE 'device_code'");
            self::$productReportHasDeviceCode = !empty($row);
        } catch (\Throwable $e) {
            self::$productReportHasDeviceCode = false;
        }
        return self::$productReportHasDeviceCode;
    }

    /**
     * 获取日报表数据
     * @param string $reportDate 报表日期
     * @return array
     */
    public function getDailyReport($reportDate)
    {
        // 优先从汇总表读取；为避免历史缓存导致昨日有报工但展示为 0，先尝试刷新当天汇总
        if (DailySummaryService::hasSummary($reportDate)) {
            try {
                DailySummaryService::refresh($reportDate);
            } catch (\Exception $e) {
            }
            return $this->getDailyReportFromSummary($reportDate);
        }

        // 汇总表无数据，回退到实时计算，并异步写入汇总表
        $reportData = $this->getDailyReportRealtime($reportDate);
        try {
            DailySummaryService::refresh($reportDate);
        } catch (\Exception $e) {
        }
        return $reportData;
    }

    private function getDailyReportFromSummary($reportDate)
    {
        $processes = DailyReportConfig::getAllProcesses();
        $allSummary = DailySummaryService::getSummary($reportDate);
        $summaryMap = [];
        foreach ($allSummary as $row) {
            $key = $row['processes_name'] . '_' . $row['class'];
            $summaryMap[$key] = $row;
        }

        $reportData = [];
        foreach ($processes as $processName) {
            if (in_array($processName, ['损耗', '合计'])) {
                continue;
            }

            $deviceInfo = DailyReportConfig::getDeviceInfo($processName);
            $dayRow = $summaryMap[$processName . '_0'] ?? [];
            $nightRow = $summaryMap[$processName . '_1'] ?? [];

            $productionQty = floatval($dayRow['production_qty'] ?? 0) + floatval($nightRow['production_qty'] ?? 0);
            $manualData = [
                'aux_accessory'    => floatval($dayRow['accessory_amount'] ?? 0) + floatval($nightRow['accessory_amount'] ?? 0),
                'aux_guide'        => floatval($dayRow['guide_amount'] ?? 0) + floatval($nightRow['guide_amount'] ?? 0),
                'aux_gas_num'      => floatval($dayRow['gas_consume'] ?? 0) + floatval($nightRow['gas_consume'] ?? 0),
                'aux_gas_amount'   => floatval($dayRow['gas_amount'] ?? 0) + floatval($nightRow['gas_amount'] ?? 0),
                'aux_power_num'    => floatval($dayRow['power_consume'] ?? 0) + floatval($nightRow['power_consume'] ?? 0),
                'aux_power_amount' => floatval($dayRow['power_amount'] ?? 0) + floatval($nightRow['power_amount'] ?? 0),
                'aux_wage'         => floatval($dayRow['wage_amount'] ?? 0) + floatval($nightRow['wage_amount'] ?? 0),
            ];

            $productionData = ['production_qty' => round($productionQty, 2), 'product_model' => ''];
            $costData = $this->calculateCost($productionData, $manualData, $reportDate, $processName);

            $reportData[] = array_merge([
                'processes_name' => $processName,
                'isTotal' => false
            ], $productionData, $manualData, $costData);
        }

        $reportData[] = $this->getLossRow($reportData);
        $reportData[] = $this->getTotalRow($reportData);
        return $reportData;
    }

    private function getDailyReportRealtime($reportDate)
    {
        // 1. 先扫数据库，找出当天出现的所有设备+班次组合
        $rawDevices = Db::name('product_report')
            ->alias('pr')
            ->field('pr.data, pr.class')
            ->where('pr.create_time', '>=', $reportDate . ' 00:00:00')
            ->where('pr.create_time', '<=', $reportDate . ' 23:59:59')
            ->where('pr.is_delete', 0)
            ->select()
            ->toArray();

        // 从 data JSON 中提取 device_name，构造 discoverNewProcesses 所需格式
        $deviceRows = [];
        foreach ($rawDevices as $row) {
            $dataJson = json_decode($row['data'] ?? '{}', true);
            $devName = $dataJson['device_name'] ?? '';
            if ($devName) {
                $deviceRows[] = [
                    'device_name' => $devName,
                    'class' => intval($row['class'] ?? 0),
                ];
            }
        }

        // 2. 自动发现新工序
        $autoProcesses = DailyReportConfig::discoverNewProcesses($reportDate, $deviceRows);

        // 3. 合并静态工序 + 自动发现的工序
        $staticProcesses = DailyReportConfig::getAllProcesses();
        $mergedProcesses = $staticProcesses;
        foreach ($autoProcesses as $pName => $pInfo) {
            if (!in_array($pName, $mergedProcesses)) {
                $mergedProcesses[] = $pName;
            }
        }

        $reportData = [];
        foreach ($mergedProcesses as $processName) {
            if (in_array($processName, ['损耗', '合计'])) {
                continue;
            }

            // 先从静态配置取，取不到再从自动发现取
            $deviceInfo = DailyReportConfig::getDeviceInfo($processName);
            if (!$deviceInfo && isset($autoProcesses[$processName])) {
                $deviceInfo = $autoProcesses[$processName];
            }

            // 查询产量数据
            $productionData = $this->getProductionData($reportDate, $processName, $deviceInfo);

            // 查询自动数据（配件、能耗、工资）
            $manualData = $this->getManualData($reportDate, $processName);

            // 计算单位成本
            $costData = $this->calculateCost($productionData, $manualData, $reportDate, $processName);

            $reportData[] = array_merge([
                'processes_name' => $processName,
                'isTotal' => false
            ], $productionData, $manualData, $costData);
        }

        // 添加损耗和合计行
        $reportData[] = $this->getLossRow($reportData);
        $reportData[] = $this->getTotalRow($reportData);

        return $reportData;
    }

    /**
     * 根据日期范围查询报表数据
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param string $processesName 工序名称（可选）
     * @return array
     */
    public function getReportByDateRange($startDate, $endDate, $processesName = '')
    {
        // 验证日期范围
        if (strtotime($startDate) > strtotime($endDate)) {
            throw new \Exception('开始日期不能大于结束日期');
        }

        // 循环查询每一天的数据并累加
        $allData = [];
        $currentDate = $startDate;

        while (strtotime($currentDate) <= strtotime($endDate)) {
            // 获取当天的日报数据
            $dayData = $this->getDailyReport($currentDate);

            // 累加到总数据
            foreach ($dayData as $item) {
                $processName = $item['processes_name'];

                // 如果指定了工序名称，只累加该工序
                if ($processesName && $processName !== $processesName && $processName !== '损耗' && $processName !== '合计') {
                    continue;
                }

                if (!isset($allData[$processName])) {
                    $allData[$processName] = $item;
                } else {
                    // 累加数值字段
                    $allData[$processName]['production_qty'] += $item['production_qty'];
                    $allData[$processName]['aux_accessory'] += $item['aux_accessory'];
                    $allData[$processName]['aux_gas_num'] += $item['aux_gas_num'];
                    $allData[$processName]['aux_gas_amount'] += $item['aux_gas_amount'];
                    $allData[$processName]['aux_power_num'] += $item['aux_power_num'];
                    $allData[$processName]['aux_power_amount'] += $item['aux_power_amount'];
                    $allData[$processName]['aux_wage'] += $item['aux_wage'];
                    $allData[$processName]['aux_guide'] += $item['aux_guide'];
                }
            }

            // 下一天
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }

        // 重新计算单位成本
        foreach ($allData as &$item) {
            // 损耗和合计行不需要重新计算
            if ($item['isTotal'] || $item['processes_name'] === '损耗') {
                continue;
            }

            if ($item['production_qty'] > 0) {
                $item['accessory_cost'] = round($item['aux_accessory'] / $item['production_qty'], 2);
                $item['guide_cost'] = round($item['aux_guide'] / $item['production_qty'], 2);
                $item['gas_cost'] = round($item['aux_gas_amount'] / $item['production_qty'], 2);
                $item['electric_cost'] = round($item['aux_power_amount'] / $item['production_qty'], 2);
                $item['wage_cost'] = round($item['aux_wage'] / $item['production_qty'], 2);
                $item['total_cost'] = round(
                    $item['accessory_cost'] + $item['guide_cost'] +
                    $item['gas_cost'] + $item['electric_cost'] + $item['wage_cost'],
                    2
                );
            } else {
                $item['accessory_cost'] = 0;
                $item['guide_cost'] = 0;
                $item['gas_cost'] = 0;
                $item['electric_cost'] = 0;
                $item['wage_cost'] = 0;
                $item['total_cost'] = 0;
            }
        }

        // 重新计算损耗和合计行
        $resultData = array_values($allData);

        // 检查是否已有损耗和合计行
        $hasLoss = false;
        $hasTotal = false;
        foreach ($resultData as $item) {
            if ($item['processes_name'] === '损耗') {
                $hasLoss = true;
            }
            if ($item['processes_name'] === '合计') {
                $hasTotal = true;
            }
        }

        // 如果没有，添加损耗和合计行
        if (!$hasLoss) {
            $resultData[] = $this->getLossRow($resultData);
        }
        if (!$hasTotal) {
            $resultData[] = $this->getTotalRow($resultData);
        }

        return $resultData;
    }

    /**
     * 获取多维度报表数据（日/周/月/季/年）
     * @param string $reportType 报表类型
     * @param array $params 查询参数
     * @return array
     */
    public function getMultiReport($reportType, $params)
    {
        // 计算时间范围
        $dateRange = $this->calculateDateRange($reportType, $params);

        // 查询时间范围内的所有日期
        $dates = $this->getDatesInRange($dateRange['start'], $dateRange['end']);

        // 汇总每天的数据
        $summaryData = [];
        $processes = DailyReportConfig::getAllProcesses();

        // 初始化所有工序
        foreach ($processes as $processName) {
            $summaryData[$processName] = [
                'production_qty' => 0,
                'accessory_cost' => 0,
                'guide_cost' => 0,
                'gas_cost' => 0,
                'electric_cost' => 0,
                'wage_cost' => 0,
                'total_cost' => 0,
                'product_model' => '',
                'aux_accessory' => 0,
                'aux_gas_num' => 0,
                'aux_gas_amount' => 0,
                'aux_power_num' => 0,
                'aux_power_amount' => 0,
                'aux_wage' => 0,
                'aux_guide' => 0
            ];
        }

        // 汇总每天的数据
        foreach ($dates as $date) {
            $dailyData = $this->getDailyReport($date);

            foreach ($dailyData as $row) {
                $processName = $row['processes_name'];
                if (in_array($processName, ['损耗', '合计'])) {
                    continue;
                }

                if (isset($summaryData[$processName])) {
                    $summaryData[$processName]['production_qty'] += floatval($row['production_qty'] ?? 0);
                    $summaryData[$processName]['aux_accessory'] += floatval($row['aux_accessory'] ?? 0);
                    $summaryData[$processName]['aux_gas_num'] += floatval($row['aux_gas_num'] ?? 0);
                    $summaryData[$processName]['aux_gas_amount'] += floatval($row['aux_gas_amount'] ?? 0);
                    $summaryData[$processName]['aux_power_num'] += floatval($row['aux_power_num'] ?? 0);
                    $summaryData[$processName]['aux_power_amount'] += floatval($row['aux_power_amount'] ?? 0);
                    $summaryData[$processName]['aux_wage'] += floatval($row['aux_wage'] ?? 0);
                    $summaryData[$processName]['aux_guide'] += floatval($row['aux_guide'] ?? 0);
                }
            }
        }

        // 重新计算单位成本
        $resultData = [];
        foreach ($processes as $processName) {
            $productionData = [
                'production_qty' => $summaryData[$processName]['production_qty'],
                'product_model' => $summaryData[$processName]['product_model']
            ];
            $manualData = [
                'aux_accessory' => $summaryData[$processName]['aux_accessory'],
                'aux_gas_num' => $summaryData[$processName]['aux_gas_num'],
                'aux_gas_amount' => $summaryData[$processName]['aux_gas_amount'],
                'aux_power_num' => $summaryData[$processName]['aux_power_num'],
                'aux_power_amount' => $summaryData[$processName]['aux_power_amount'],
                'aux_wage' => $summaryData[$processName]['aux_wage'],
                'aux_guide' => $summaryData[$processName]['aux_guide']
            ];

            // 使用最后一天计算成本（或者使用平均系数）
            $lastDate = end($dates);
            $costData = $this->calculateCost($productionData, $manualData, $lastDate, $processName);

            $resultData[] = array_merge([
                'processes_name' => $processName,
                'isTotal' => in_array($processName, ['损耗', '合计'])
            ], $productionData, $manualData, $costData);
        }

        // 添加损耗和合计行
        $resultData[] = $this->getLossRow($resultData);
        $resultData[] = $this->getTotalRow($resultData);

        return $resultData;
    }

    /**
     * 计算时间范围
     * @param string $reportType
     * @param array $params
     * @return array
     */
    private function calculateDateRange($reportType, $params)
    {
        switch ($reportType) {
            case 'day':
                $date = $params['report_date'] ?? date('Y-m-d');
                return ['start' => $date, 'end' => $date];

            case 'week':
                $date = $params['report_week'] ?? date('Y-m-d');
                $timestamp = strtotime($date);
                $start = date('Y-m-d', strtotime('monday this week', $timestamp));
                $end = date('Y-m-d', strtotime('sunday this week', $timestamp));
                return ['start' => $start, 'end' => $end];

            case 'month':
                $date = $params['report_month'] ?? date('Y-m');
                $start = date('Y-m-01', strtotime($date));
                $end = date('Y-m-t', strtotime($date));
                return ['start' => $start, 'end' => $end];

            case 'quarter':
                $quarter = $params['report_quarter'] ?? '';
                list($year, $q) = explode('-Q', $quarter);
                $startMonth = ($q - 1) * 3 + 1;
                $endMonth = $startMonth + 2;
                return [
                    'start' => "{$year}-{$startMonth}-01",
                    'end' => date('Y-m-t', strtotime("{$year}-{$endMonth}-01"))
                ];

            case 'year':
                $year = $params['report_year'] ?? date('Y');
                return [
                    'start' => "{$year}-01-01",
                    'end' => "{$year}-12-31"
                ];

            default:
                $today = date('Y-m-d');
                return ['start' => $today, 'end' => $today];
        }
    }

    /**
     * 获取时间范围内的所有日期
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getDatesInRange($startDate, $endDate)
    {
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }

        return $dates;
    }

    /**
     * 查询产量数据
     * @param string $reportDate
     * @param string $processName
     * @param array $deviceInfo
     * @return array
     */
    private function getProductionData($reportDate, $processName, $deviceInfo)
    {
        // 如果是辅助工序或统计项，返回空数据
        if (DailyReportConfig::isStatisticsProcess($processName)) {
            return [
                'production_qty' => 0,
                'product_model' => ''
            ];
        }

        $query = Db::name('product_report')
            ->alias('pr')
            ->leftJoin('material m', 'pr.material_id = m.id')
            ->field('pr.*, m.material_spec, m.material_name, m.material_model')
            ->where('pr.create_time', '>=', $reportDate . ' 00:00:00')
            ->where('pr.create_time', '<=', $reportDate . ' 23:59:59')
            ->where('pr.is_delete', 0);

        // 根据设备信息构建查询条件
        if ($deviceInfo['type'] === 'multiple') {
            // 多台设备汇总：优先按 device_code 过滤（更稳定），兼容旧数据再按 data.device_name 模糊匹配
            $deviceNames = $deviceInfo['devices'] ?? [];
            $deviceCodes = [];
            if (is_array($deviceNames) && !empty($deviceNames)) {
                $deviceCodes = Db::name('device')
                    ->whereIn('device_name', $deviceNames)
                    ->where('is_delete', 0)
                    ->column('device_code');
            }
            $hasDeviceCodeCol = $this->productReportHasDeviceCodeColumn();
            $query->where(function ($q) use ($deviceInfo, $deviceCodes, $hasDeviceCodeCol) {
                if ($hasDeviceCodeCol && !empty($deviceCodes)) {
                    $q->whereIn('pr.device_code', $deviceCodes);
                }
                foreach ($deviceCodes as $dc) {
                    $q->whereOr('pr.data', 'like', '%"device_code":"' . $dc . '"%');
                }
                foreach (($deviceInfo['devices'] ?? []) as $deviceName) {
                    $jsonName = trim(json_encode($deviceName), '"');
                    $q->whereOr('pr.data', 'like', '%"device_name":"' . $deviceName . '"%')
                      ->whereOr('pr.data', 'like', '%"device_name":"' . $jsonName . '"%');
                }
            });
        } elseif ($deviceInfo['type'] === 'single') {
            // 单台设备；班次为白班/夜班时按 pr.class 过滤，为 all（如酸洗）则汇总全天
            $jsonName = trim(json_encode($deviceInfo['device']), '"');
            $singleDeviceCode = Db::name('device')
                ->where('device_name', $deviceInfo['device'])
                ->where('is_delete', 0)
                ->value('device_code');
            $query->where(function ($q) use ($deviceInfo, $jsonName, $singleDeviceCode) {
                $q->where('pr.data', 'like', '%"device_name":"' . $deviceInfo['device'] . '"%')
                  ->whereOr('pr.data', 'like', '%"device_name":"' . $jsonName . '"%');
                if (!empty($singleDeviceCode)) {
                    $q->whereOr('pr.data', 'like', '%"device_code":"' . $singleDeviceCode . '"%');
                }
            });
            if (isset($deviceInfo['shift']) && $deviceInfo['shift'] !== 'all') {
                $shiftType = $deviceInfo['shift'] === '白班' ? 0 : 1;
                $query->where('pr.class', $shiftType);
            }
        }

        // 查询报工记录
        $reports = $query->select()->toArray();

        // 汇总产量
        $totalQty = 0;
        $models = [];

        foreach ($reports as $report) {
            $totalQty += floatval($report['report_num'] ?? 0);

            // 提取型号
            // 圆钢型号和成品型号：在表中由 material_spec 或 material_model 等提供
            if ($processName === '断钢' || $processName === '锻钢') {
                if (!empty($report['material_spec'])) {
                    $models[] = $report['material_spec'];
                }
            } else {
                // 如果后续有更精确的成品型号列可替换为 product_spec
                if (!empty($report['material_model'])) {
                    $models[] = $report['material_model'];
                } elseif (!empty($report['material_spec'])) {
                    $models[] = $report['material_spec'];
                }
            }
        }

        return [
            'production_qty' => round($totalQty, 2),
            'product_model' => implode('/', array_unique(array_filter($models)))
        ];
    }

    /**
     * 查询手动输入数据（改为自动查询）
     * @param string $reportDate
     * @param string $processName
     * @return array
     */
    private function getManualData($reportDate, $processName)
    {
        DatabaseInitService::ensure();

        $deviceInfo = DailyReportConfig::getDeviceInfo($processName);

        // 查询配件金额（从领料单）
        $accessoryAmount = $this->getAccessoryAmount($reportDate, $processName, $deviceInfo);

        // 查询顶头导板金额（从领料单模糊匹配）
        $guideAmount = $this->getTipAndGuideAmount($reportDate, $processName, $deviceInfo);

        // 查询能耗数据（从能耗报工）
        $energyData = $this->getEnergyData($reportDate, $processName, $deviceInfo);

        // 查询工资数据（从工资明细）
        $wageAmount = $this->getWageAmount($reportDate, $processName, $deviceInfo);

        return [
            'aux_accessory' => round($accessoryAmount, 2),
            'aux_guide' => round($guideAmount, 2),
            'aux_gas_num' => round($energyData['gas_num'], 2),
            'aux_gas_amount' => round($energyData['gas_amount'], 2),
            'aux_power_num' => round($energyData['power_num'], 2),
            'aux_power_amount' => round($energyData['power_amount'], 2),
            'aux_wage' => round($wageAmount, 2)
        ];
    }

    /**
     * 查询配件金额（领料单）
     */
    private function getAccessoryAmount($reportDate, $processName, $deviceInfo)
    {
        // 辅助工序和统计项没有领料数据
        if (DailyReportConfig::isStatisticsProcess($processName)) {
            return 0;
        }

        // 构建领料查询
        $query = Db::name('receiving')
            ->alias('r')
            ->leftJoin('material m', 'r.material_id = m.id')
            ->leftJoin('wms_out o', 'o.relation_code = r.receiving_code AND o.status = 1')
            ->leftJoin('wms_in i', 'i.material_id = r.material_id AND i.material_batch = o.material_batch')
            ->where('r.create_time', '>=', $reportDate . ' 00:00:00')
            ->where('r.create_time', '<=', $reportDate . ' 23:59:59')
            ->where('r.is_delete', 0)
            ->where('r.status', 1); // 只统计已完成的领料

        // 设备条件（relation_code存的是设备编码或设备名称）
        if ($deviceInfo['type'] === 'multiple') {
            // 多台设备：需要查询所有设备的领料
            $deviceNames = $deviceInfo['devices'];
            $deviceCodes = Db::name('device')
                ->whereIn('device_name', $deviceNames)
                ->where('is_delete', 0)
                ->column('device_code');

            if (!empty($deviceCodes)) {
                $query->whereIn('r.relation_code', $deviceCodes);
            } else {
                // 如果找不到设备编码，直接返回0
                return 0;
            }
        } elseif ($deviceInfo['type'] === 'single') {
            // 单台设备：直接使用设备名称查询
            $deviceName = $deviceInfo['device'];

            // 设备名称映射
            $deviceNameMap = [
                '球化南1' => '球化南',
                '酸洗车间' => '酸洗车间',
                '穿孔40机' => '穿孔40机',
                '穿孔50机' => '穿孔50机',
                '球化北' => '球化北'
            ];

            $mappedDeviceName = $deviceNameMap[$deviceName] ?? $deviceName;

            // 先尝试通过设备名称查询device_code
            $deviceCode = Db::name('device')
                ->where('device_name', $mappedDeviceName)
                ->where('is_delete', 0)
                ->value('device_code');

            if ($deviceCode) {
                $query->where('r.relation_code', $deviceCode);
            } else {
                // 如果找不到设备编码，尝试直接使用设备名称
                $query->where('r.relation_code', $mappedDeviceName);
            }
        }

        // 排除顶头导板（单独计算）
        $query->where(function ($q) {
            $q->whereNull('m.material_name')
              ->whereOr(function ($subQ) {
                  $subQ->whereNotLike('m.material_name', '%顶头%')
                       ->whereNotLike('m.material_name', '%导板%');
              });
        });

        // 查询使用单价计算
        $accessoryData = $query->field('r.material_num, i.money as in_price')
            ->select()
            ->toArray();

        $totalAmount = 0;
        foreach ($accessoryData as $item) {
            $num = floatval($item['material_num'] ?? 0);
            $price = floatval($item['in_price'] ?? 0);
            $totalAmount += $num * $price;
        }

        return round($totalAmount, 2);
    }

    /**
     * 查询顶头导板金额（领料单模糊匹配）
     */
    private function getTipAndGuideAmount($reportDate, $processName, $deviceInfo)
    {
        // 辅助工序和统计项没有领料数据
        if (DailyReportConfig::isStatisticsProcess($processName)) {
            return 0;
        }

        // 构建领料查询
        $query = Db::name('receiving')
            ->alias('r')
            ->leftJoin('material m', 'r.material_id = m.id')
            ->leftJoin('wms_out o', 'o.relation_code = r.receiving_code AND o.status = 1')
            ->leftJoin('wms_in i', 'i.material_id = r.material_id AND i.material_batch = o.material_batch')
            ->where('r.create_time', '>=', $reportDate . ' 00:00:00')
            ->where('r.create_time', '<=', $reportDate . ' 23:59:59')
            ->where('r.is_delete', 0)
            ->where('r.status', 1) // 只统计已完成的领料
            ->where(function ($q) {
                $q->whereLike('m.material_name', '%顶头%')
                  ->whereOr('m.material_name', '%导板%');
            });

        // 设备条件（与上面相同）
        if ($deviceInfo['type'] === 'multiple') {
            $deviceNames = $deviceInfo['devices'];
            $deviceCodes = Db::name('device')
                ->whereIn('device_name', $deviceNames)
                ->where('is_delete', 0)
                ->column('device_code');

            if (!empty($deviceCodes)) {
                $query->whereIn('r.relation_code', $deviceCodes);
            } else {
                return 0;
            }
        } elseif ($deviceInfo['type'] === 'single') {
            $deviceName = $deviceInfo['device'];

            $deviceNameMap = [
                '球化南1' => '球化南',
                '酸洗车间' => '酸洗车间',
                '穿孔40机' => '穿孔40机',
                '穿孔50机' => '穿孔50机',
                '球化北' => '球化北'
            ];

            $mappedDeviceName = $deviceNameMap[$deviceName] ?? $deviceName;

            $deviceCode = Db::name('device')
                ->where('device_name', $mappedDeviceName)
                ->where('is_delete', 0)
                ->value('device_code');

            if ($deviceCode) {
                $query->where('r.relation_code', $deviceCode);
            } else {
                $query->where('r.relation_code', $mappedDeviceName);
            }
        }

        // 查询使用单价计算
        $guideData = $query->field('r.material_num, i.money as in_price')
            ->select()
            ->toArray();

        $totalAmount = 0;
        foreach ($guideData as $item) {
            $num = floatval($item['material_num'] ?? 0);
            $price = floatval($item['in_price'] ?? 0);
            $totalAmount += $num * $price;
        }

        return round($totalAmount, 2);
    }

    /**
     * 查询能耗数据
     */
    private function getEnergyData($reportDate, $processName, $deviceInfo)
    {
        $result = [
            'gas_num' => 0,
            'gas_amount' => 0,
            'power_num' => 0,
            'power_amount' => 0
        ];

        // 辅助工序和统计项没有能耗数据
        if (DailyReportConfig::isStatisticsProcess($processName)) {
            return $result;
        }

        // 构建能耗查询条件
        $query = Db::name('product_power')
            ->where('create_time', '>=', $reportDate . ' 00:00:00')
            ->where('create_time', '<=', $reportDate . ' 23:59:59');

        // 设备条件（多台：与 DailySummaryService::calcEnergy / applyDeviceCondition 一致，按 device_code 汇总）
        if ($deviceInfo['type'] === 'multiple') {
            $deviceNames = $deviceInfo['devices'] ?? [];
            if (!is_array($deviceNames) || $deviceNames === []) {
                return $result;
            }
            $deviceCodes = Db::name('device')
                ->whereIn('device_name', $deviceNames)
                ->where('is_delete', 0)
                ->column('device_code');
            if ($deviceCodes === []) {
                return $result;
            }
            $query->whereIn('device_code', $deviceCodes);
            if (isset($deviceInfo['shift']) && $deviceInfo['shift'] !== 'all') {
                $class = $deviceInfo['shift'] === '白班' ? 0 : 1;
                $query->where('class', $class);
            }
        } elseif ($deviceInfo['type'] === 'single') {
            // 单台设备：直接使用设备名称查询
            // 注意：ya_product_power表中的device_code可能存的是设备名称或设备编码
            // 先尝试用设备名称查询
            $deviceName = $deviceInfo['device'];

            // 球化南1在能耗表中可能是"球化南"或"球化南1"
            // 需要做名称映射
            $deviceNameMap = [
                '球化南1' => '球化南',
                '酸洗车间' => '酸洗车间',
                '穿孔40机' => '穿孔40机',
                '穿孔50机' => '穿孔50机',
                '球化北' => '球化北'
            ];

            $mappedDeviceName = $deviceNameMap[$deviceName] ?? $deviceName;

            // 先尝试通过设备名称查询device_code
            $deviceCode = Db::name('device')
                ->where('device_name', $mappedDeviceName)
                ->where('is_delete', 0)
                ->value('device_code');

            if ($deviceCode) {
                $query->where('device_code', $deviceCode);
            } else {
                // 如果找不到设备编码，尝试直接使用设备名称（兼容旧数据）
                $query->where('device_code', $mappedDeviceName);
            }

            // 班次转换（白班=0，夜班=1）
            if (isset($deviceInfo['shift']) && $deviceInfo['shift'] !== 'all') {
                $class = $deviceInfo['shift'] === '白班' ? 0 : 1;
                $query->where('class', $class);
            }
        }

        $energyRecords = $query->select()->toArray();

        // 汇总消耗量
        foreach ($energyRecords as $record) {
            $result['gas_num'] += floatval($record['gas_consume'] ?? 0);
            $result['power_num'] += floatval($record['power_consume'] ?? 0);
        }

        // 获取单价（从系数表）
        $gasPrice = ReportCoefficientModel::getCoefficientByDate('gas', $reportDate) ?? 3.98;
        $powerPrice = ReportCoefficientModel::getCoefficientByDate('power', $reportDate) ?? 0.88;

        // 计算金额
        $result['gas_amount'] = $result['gas_num'] * $gasPrice;
        $result['power_amount'] = $result['power_num'] * $powerPrice;

        return $result;
    }

    /**
     * 查询工资数据
     */
    private function getWageAmount($reportDate, $processName, $deviceInfo)
    {
        // 辅助工序和统计项没有工资数据
        if (DailyReportConfig::isStatisticsProcess($processName)) {
            return 0;
        }

        $query = Db::name('user_wage_details')
            ->where('date', $reportDate)
            ->where('is_delete', 0);

        // 设备条件
        if ($deviceInfo['type'] === 'multiple') {
            // 多台设备：需要查询所有设备的工资
            // 先获取这些设备的device_code
            $deviceNames = $deviceInfo['devices'];
            $deviceCodes = Db::name('device')
                ->whereIn('device_name', $deviceNames)
                ->where('is_delete', 0)
                ->column('device_code');

            if (!empty($deviceCodes)) {
                $query->whereIn('device_code', $deviceCodes);
            } else {
                // 如果找不到设备编码，直接返回0
                return 0;
            }
        } elseif ($deviceInfo['type'] === 'single') {
            // 单台设备：直接使用设备名称查询
            $deviceName = $deviceInfo['device'];

            // 设备名称映射（与能耗表相同）
            $deviceNameMap = [
                '球化南1' => '球化南',
                '酸洗车间' => '酸洗车间',
                '穿孔40机' => '穿孔40机',
                '穿孔50机' => '穿孔50机',
                '球化北' => '球化北'
            ];

            $mappedDeviceName = $deviceNameMap[$deviceName] ?? $deviceName;

            // 先尝试通过设备名称查询device_code
            $deviceCode = Db::name('device')
                ->where('device_name', $mappedDeviceName)
                ->where('is_delete', 0)
                ->value('device_code');

            if ($deviceCode) {
                $query->where('device_code', $deviceCode);
            } else {
                // 如果找不到设备编码，尝试直接使用设备名称（兼容旧数据）
                $query->where('device_code', $mappedDeviceName);
            }

            // 班次过滤：白班/夜班分别统计；all 时不限班次（与能耗 getEnergyData 一致）
            if (isset($deviceInfo['shift']) && $deviceInfo['shift'] !== 'all') {
                $shiftType = $deviceInfo['shift'] === '白班' ? 0 : 1;
                $query->where('class', $shiftType);
            }
        }

        // 汇总工资
        return $query->sum('wage_sum') ?? 0;
    }

    /**
     * 计算单位成本
     * @param array $productionData
     * @param array $manualData
     * @param string $reportDate
     * @param string $processName
     * @return array
     */
    private function calculateCost($productionData, $manualData, $reportDate, $processName)
    {
        $qty = floatval($productionData['production_qty']);

        // 产量为0时，所有成本为0
        if ($qty == 0) {
            return [
                'accessory_cost' => 0,
                'guide_cost' => 0,
                'gas_cost' => 0,
                'electric_cost' => 0,
                'wage_cost' => 0,
                'total_cost' => 0
            ];
        }

        // 根据原稿设计要求：仅保留各个工序对应的固定系数
        $fixedCoefficient = 0;

        // strpos 模糊匹配，因为工序名可能是 "球化甲", "穿孔丙"
        if (strpos($processName, '球化') !== false) {
            $fixedCoefficient += ReportCoefficientModel::getCoefficientByDate('wire', $reportDate) ?? 40;
        }
        if (strpos($processName, '酸洗') !== false) {
            $fixedCoefficient += ReportCoefficientModel::getCoefficientByDate('rod', $reportDate) ?? 40;
        }
        if (strpos($processName, '穿孔') !== false) {
            $fixedCoefficient += ReportCoefficientModel::getCoefficientByDate('roller', $reportDate) ?? 100;
        }

        // 计算各项单位成本
        // 配件(元/吨) = (配件字段出库金额 ÷ 本工序产量) + 一个固定系数
        $accessoryCost = (floatval($manualData['aux_accessory']) / $qty) + $fixedCoefficient;
        $guideCost = floatval($manualData['aux_guide']) / $qty;
        $gasCost = floatval($manualData['aux_gas_amount']) / $qty;
        $electricCost = floatval($manualData['aux_power_amount']) / $qty;
        $wageCost = floatval($manualData['aux_wage']) / $qty;

        // 总成本
        $totalCost = $accessoryCost + $guideCost + $gasCost + $electricCost + $wageCost;

        return [
            'accessory_cost' => round($accessoryCost, 2),
            'guide_cost' => round($guideCost, 2),
            'gas_cost' => round($gasCost, 2),
            'electric_cost' => round($electricCost, 2),
            'wage_cost' => round($wageCost, 2),
            'total_cost' => round($totalCost, 2)
        ];
    }

    /**
     * 获取损耗行
     * @param array $reportData
     * @return array
     */
    private function getLossRow($reportData)
    {
        // 从系数配置读取损耗值
        $lossPrice = ReportCoefficientModel::getCoefficientByDate('loss', date('Y-m-d')) ?? 135.00;
        return [
            'processes_name' => '损耗',
            'production_qty' => null,
            'accessory_cost' => null,
            'guide_cost' => null,
            'gas_cost' => null,
            'electric_cost' => null,
            'wage_cost' => null,
            'total_cost' => $lossPrice,
            'product_model' => null,
            'aux_accessory' => null,
            'aux_gas_num' => null,
            'aux_gas_amount' => null,
            'aux_power_num' => null,
            'aux_power_amount' => null,
            'aux_wage' => null,
            'aux_guide' => null,
            'isTotal' => false
        ];
    }

    /**
     * 获取合计行
     * @param array $reportData
     * @return array
     */
    private function getTotalRow($reportData)
    {
        $totalGasAmount = 0;
        $totalCost = 0;

        foreach ($reportData as $row) {
            if ($row['processes_name'] === '损耗' || $row['processes_name'] === '合计') {
                continue;
            }

            $totalGasAmount += floatval($row['aux_gas_amount'] ?? 0);
            $totalCost += floatval($row['total_cost'] ?? 0);
        }

        // 加上损耗
        $lossPrice = ReportCoefficientModel::getCoefficientByDate('loss', date('Y-m-d')) ?? 135.00;
        $totalCost += $lossPrice;

        return [
            'processes_name' => '合计',
            'production_qty' => null,
            'accessory_cost' => null,
            'guide_cost' => null,
            'gas_cost' => null,
            'electric_cost' => null,
            'wage_cost' => null,
            'total_cost' => round($totalCost, 2),
            'product_model' => null,
            'aux_accessory' => null,
            'aux_gas_num' => $totalGasAmount,
            'aux_gas_amount' => null,
            'aux_power_num' => null,
            'aux_power_amount' => null,
            'aux_wage' => null,
            'aux_guide' => null,
            'isTotal' => true
        ];
    }

    /**
     * 保存手动输入数据
     * @param string $reportDate
     * @param string $processName
     * @param array $data
     * @return bool
     */
    public function saveManualData($reportDate, $processName, $data)
    {
        // 计算金额
        $gasPrice = DailyReportConfig::$unitPrices['gas'];
        $powerPrice = DailyReportConfig::$unitPrices['power'];

        $gasAmount = floatval($data['gas_num'] ?? 0) * $gasPrice;
        $powerAmount = floatval($data['power_num'] ?? 0) * $powerPrice;

        $saveData = [
            'accessory_amount' => $data['accessory_amount'] ?? 0,
            'guide_amount' => $data['guide_amount'] ?? 0,
            'gas_num' => $data['gas_num'] ?? 0,
            'power_num' => $data['power_num'] ?? 0,
            'wage_amount' => $data['wage_amount'] ?? 0,
            'gas_amount' => $gasAmount,
            'power_amount' => $powerAmount,
            'update_time' => date('Y-m-d H:i:s')
        ];

        // 查询是否存在
        $exist = Db::name('daily_report_manual')
            ->where('report_date', $reportDate)
            ->where('processes_name', $processName)
            ->find();

        if ($exist) {
            // 更新
            return Db::name('daily_report_manual')
                ->where('id', $exist['id'])
                ->update($saveData);
        } else {
            // 插入
            $saveData['report_date'] = $reportDate;
            $saveData['processes_name'] = $processName;
            $saveData['create_time'] = date('Y-m-d H:i:s');
            $saveData['create_uid'] = session('admin.id') ?? 0;

            return Db::name('daily_report_manual')->insert($saveData);
        }
    }


    /**
     * 导出生产日报表Excel
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @return array
     */
    public function exportDailyReport($startDate, $endDate)
    {
        // 获取报表数据
        $reportData = $this->getReportByDateRange($startDate, $endDate);

        // 创建Spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('生产日报表');

        // 标题行
        $title = $startDate . '钢管生产报表';
        if ($startDate != $endDate) {
            $title = $startDate . ' 至 ' . $endDate . '钢管生产报表';
        }
        $sheet->mergeCells('A1:Q1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // 表头
        $headers = [
            'A2' => '工序',
            'B2' => '产量（吨）',
            'C2' => "配件\n（不含顶头导板）\n（元/吨）",
            'D2' => '顶头/导板（元/吨）',
            'E2' => '天然气（元/吨）',
            'F2' => "电费\n（元/吨）",
            'G2' => "工资\n(元/吨)",
            'H2' => "成本\n（元/吨）",
            'I2' => '型号',
            'J2' => '配件',
            'K2' => '天然气量',
            'L2' => '天然气金额',
            'M2' => '电字',
            'N2' => '电费',
            'O2' => '工资',
            'P2' => '顶头导板',
            'Q2' => '',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $sheet->getStyle('A2:Q2')->getFont()->setBold(true);
        $sheet->getStyle('A2:Q2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A2:Q2')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(2)->setRowHeight(45);

        // 数据列宽
        $colWidths = [12, 12, 14, 14, 14, 12, 12, 12, 10, 12, 12, 12, 10, 10, 12, 12, 8];
        foreach (range('A', 'Q') as $i => $col) {
            $sheet->getColumnDimension($col)->setWidth($colWidths[$i]);
        }

        // 填充数据
        $rowIndex = 3;
        foreach ($reportData as $data) {
            $sheet->setCellValue('A' . $rowIndex, $data['processes_name'] ?? '');
            if (!$data['isTotal']) {
                $sheet->setCellValue('B' . $rowIndex, $this->formatExportNum($data['production_qty'] ?? 0));
                $sheet->setCellValue('C' . $rowIndex, $this->formatExportNum($data['accessory_cost'] ?? 0));
                $sheet->setCellValue('D' . $rowIndex, $this->formatExportNum($data['guide_cost'] ?? 0));
                $sheet->setCellValue('E' . $rowIndex, $this->formatExportNum($data['gas_cost'] ?? 0));
                $sheet->setCellValue('F' . $rowIndex, $this->formatExportNum($data['electric_cost'] ?? 0));
                $sheet->setCellValue('G' . $rowIndex, $this->formatExportNum($data['wage_cost'] ?? 0));
                $sheet->setCellValue('I' . $rowIndex, $data['product_model'] ?? '');
                $sheet->setCellValue('J' . $rowIndex, $this->formatExportNum($data['aux_accessory'] ?? 0));
                $sheet->setCellValue('K' . $rowIndex, $this->formatExportNum($data['aux_gas_num'] ?? 0));
                $sheet->setCellValue('L' . $rowIndex, $this->formatExportNum($data['aux_gas_amount'] ?? 0));
                $sheet->setCellValue('M' . $rowIndex, $this->formatExportNum($data['aux_power_num'] ?? 0));
                $sheet->setCellValue('N' . $rowIndex, $this->formatExportNum($data['aux_power_amount'] ?? 0));
                $sheet->setCellValue('O' . $rowIndex, $this->formatExportNum($data['aux_wage'] ?? 0));
                $sheet->setCellValue('P' . $rowIndex, $this->formatExportNum($data['aux_guide'] ?? 0));
            }
            // 成本列（损耗和合计行也显示）
            $sheet->setCellValue('H' . $rowIndex, $this->formatExportNum($data['total_cost'] ?? 0));
            // 合计行汇总天然气量
            if ($data['processes_name'] === '合计') {
                $sheet->setCellValue('K' . $rowIndex, $this->formatExportNum($data['aux_gas_num'] ?? 0));
            }
            $rowIndex++;
        }

        // 设置居中
        $sheet->getStyle('A3:Q' . ($rowIndex - 1))->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // 保存文件
        $exportDir = public_path() . '/storage/export/';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        $fileName = '生产日报表_' . $startDate . '_' . $endDate . '_' . date('YmdHis') . '.xlsx';
        $filePath = $exportDir . $fileName;

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $downloadUrl = request()->domain() . '/storage/export/' . $fileName;

        return [
            'data' => [
                'file_name' => $fileName,
                'file_path' => $filePath,
                'download_url' => $downloadUrl,
                'size' => filesize($filePath),
                'time' => date('Y-m-d H:i:s'),
            ],
            'msg' => '导出成功'
        ];
    }

    /**
     * 格式化导出数字
     */
    private function formatExportNum($val)
    {
        if ($val === null || $val === '') {
            return '';
        }
        return round(floatval($val), 2);
    }

}
