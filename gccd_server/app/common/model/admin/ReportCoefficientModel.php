<?php

// +----------------------------------------------------------------------
// | 高齿传动生产管理系统
// +----------------------------------------------------------------------
// | 报表系数配置模型
// +----------------------------------------------------------------------

namespace app\common\model\admin;

use think\Model;

/**
 * 报表固定系数配置模型
 */
class ReportCoefficientModel extends Model
{
    protected $name = "report_coefficient";
    protected $pk = 'id';

    // 禁用自动时间戳（避免和type字段名冲突）
    protected $autoWriteTimestamp = false;

    /**
     * 单价类型映射
     */
    public static $typeMap = [
        'wire' => '退火丝单价',
        'rod' => '盘条单价',
        'roller' => '轧辊单价',
        'gas' => '天然气单价',
        'power' => '电费单价',
        'motor' => '电机折旧单价',
        'loss' => '损耗单价',
        'auxiliary' => '辅助工单价'
    ];

    /**
     * 获取指定日期生效的系数
     * @param string $type 系数类型
     * @param string $date 报表日期
     * @return float|null
     */
    public static function getCoefficientByDate($type, $date)
    {
        $result = self::where('type', $type)
            ->where('effective_date', '<=', $date)
            ->where('is_delete', 0)
            ->order('effective_date', 'desc')
            ->find();

        return $result ? floatval($result['value']) : null;
    }

    /**
     * 获取所有系数的当前值
     * @param string $date 报表日期
     * @return array
     */
    public static function getAllCoefficients($date)
    {
        $coefficients = [];
        foreach (self::$typeMap as $type => $name) {
            $coefficients[$type] = [
                'name' => $name,
                'value' => self::getCoefficientByDate($type, $date)
            ];
        }
        return $coefficients;
    }
}
