<?php

// +----------------------------------------------------------------------
// | 高齿传动生产管理系统
// +----------------------------------------------------------------------
// | 日报表工序映射配置
// +----------------------------------------------------------------------

namespace app\common\config;

/**
 * 日报表工序映射配置
 * 将Excel显示的工序名称映射到实际的设备+班次
 */
class DailyReportConfig
{
    /**
     * 工序映射关系
     * 格式：Excel显示名称 => 设备信息
     */
    public static $processMapping = [
        // 断钢工序（多台设备，按产量汇总）；「锻钢」为历史名称，映射相同
        '断钢' => [
            'devices' => ['断钢1', '断钢2', '断钢3', '断钢4', '断钢5', '断钢6'],
            'type' => 'multiple', // 多台设备汇总
            'shift' => 'all' // 不区分班次
        ],
        '锻钢' => [
            'devices' => ['断钢1', '断钢2', '断钢3', '断钢4', '断钢5', '断钢6'],
            'type' => 'multiple',
            'shift' => 'all'
        ],

        // 穿孔工序（区分设备和班次）
        '穿孔甲' => [
            'device' => '穿孔40机',
            'shift' => '白班',
            'type' => 'single'
        ],
        '穿孔乙' => [
            'device' => '穿孔40机',
            'shift' => '夜班',
            'type' => 'single'
        ],
        '穿孔丙' => [
            'device' => '穿孔50机',
            'shift' => '白班',
            'type' => 'single'
        ],
        '穿孔丁' => [
            'device' => '穿孔50机',
            'shift' => '夜班',
            'type' => 'single'
        ],

        // 球化工序（区分设备和班次）
        '球化甲' => [
            'device' => '球化南1',
            'shift' => '白班',
            'type' => 'single'
        ],
        '球化乙' => [
            'device' => '球化南1',
            'shift' => '夜班',
            'type' => 'single'
        ],
        '球化丙' => [
            'device' => '球化北',
            'shift' => '白班',
            'type' => 'single'
        ],
        '球化丁' => [
            'device' => '球化北',
            'shift' => '夜班',
            'type' => 'single'
        ],

        // 矫直工序
        '矫直' => [
            'devices' => ['矫直100型', '矫直80型'],
            'type' => 'multiple',
            'shift' => 'all'
        ],

        // 酸洗工序
        '酸洗' => [
            'device' => '酸洗车间',
            'type' => 'single',
            'shift' => 'all'
        ],

        // 辅助工序
        '辅助' => [
            'type' => 'auxiliary', // 辅助工序，单独处理
            'shift' => 'all'
        ],

        // 损耗（统计项，不对应具体设备）
        '损耗' => [
            'type' => 'statistics', // 统计项
            'shift' => 'all'
        ]
    ];

    /**
     * 单价配置（元/单位）
     * 后续可从系统设置表读取
     */
    public static $unitPrices = [
        'gas' => 3.5,    // 天然气：元/立方米
        'power' => 0.88  // 电费：元/度
    ];


    /**
     * 天干次序（甲→癸），用于自动命名新工序
     */
    public static $ordinals = ['甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸'];

    /**
     * 设备名→工序类别 匹配规则
     * 新设备出现时，根据设备名称自动判断属于哪类工序
     */
    public static $devicePatterns = [
        '/^断钢/' => '断钢',
        '/^锻钢/' => '断钢',
        '/^穿孔/' => '穿孔',
        '/^球化/' => '球化',
        '/^矫直/' => '矫直',
        '/^酸洗/' => '酸洗',
    ];

    /**
     * 自动发现新工序
     * 扫描报工表中未配置的设备+班次组合，动态生成工序名
     * @param string $date 报表日期
     * @param array $reportData 已有的报工数据（用于确定已有设备）
     * @return array 新增的工序映射
     */
    public static function discoverNewProcesses($date, $reportData = [])
    {
        // 收集现有映射中已覆盖的 (device, shift) 组合
        $covered = [];
        foreach (self::$processMapping as $name => $info) {
            if (isset($info['device']) && isset($info['shift'])) {
                $covered[$info['device'] . '_' . $info['shift']] = true;
            }
            if (isset($info['devices'])) {
                foreach ($info['devices'] as $d) {
                    $covered[$d . '_all'] = true;
                }
            }
        }

        // 从报工数据里提取设备名
        $deviceSet = [];
        foreach ($reportData as $row) {
            $devName = $row['device_name'] ?? '';
            $cls = isset($row['class']) ? intval($row['class']) : 0;
            $shift = ($cls === 1) ? '夜班' : '白班';
            $key = $devName . '_' . $shift;
            if ($devName && !isset($covered[$key]) && !isset($covered[$devName . '_all'])) {
                $deviceSet[$key] = ['device' => $devName, 'shift' => $shift];
            }
        }

        // 对每个未覆盖的设备+班次，生成工序名
        $newProcesses = [];
        foreach ($deviceSet as $key => $item) {
            $category = self::inferCategory($item['device']);
            if (!$category) {
                continue;
            }
            // 统计该类别已有多少工序（含本次新增的）
            $count = 0;
            foreach (self::$processMapping as $pName => $pInfo) {
                if (strpos($pName, $category) === 0 && preg_match('/^' . $category . '[甲乙丙丁戊己庚辛壬癸]/u', $pName)) {
                    $count++;
                }
            }
            $count += count(array_filter($newProcesses, function($np) use ($category) {
                return strpos($np, $category) === 0;
            }));
            $ordinal = self::$ordinals[$count] ?? ('_' . ($count + 1));
            $processName = $category . $ordinal;
            $newProcesses[$processName] = [
                'device' => $item['device'],
                'shift' => $item['shift'],
                'type' => 'single',
                'auto' => true,
            ];
        }

        return $newProcesses;
    }

    /**
     * 根据设备名推断工序类别
     */
    public static function inferCategory($deviceName)
    {
        foreach (self::$devicePatterns as $pattern => $category) {
            if (preg_match($pattern, $deviceName)) {
                return $category;
            }
        }
        return null;
    }

    /**
     * 根据工序名称获取设备信息
     * @param string $processName 工序名称
     * @return array|null
     */
    public static function getDeviceInfo($processName)
    {
        return self::$processMapping[$processName] ?? null;
    }

    /**
     * 获取所有工序列表
     * @return array
     */
    public static function getAllProcesses()
    {
        return array_keys(self::$processMapping);
    }

    /**
     * 判断是否为汇总型工序（多台设备）
     * @param string $processName
     * @return bool
     */
    public static function isMultipleDeviceProcess($processName)
    {
        $info = self::getDeviceInfo($processName);
        return $info && $info['type'] === 'multiple';
    }

    /**
     * 判断是否为统计项（不对应设备）
     * @param string $processName
     * @return bool
     */
    public static function isStatisticsProcess($processName)
    {
        $info = self::getDeviceInfo($processName);
        return $info && in_array($info['type'], ['statistics', 'auxiliary']);
    }
}
