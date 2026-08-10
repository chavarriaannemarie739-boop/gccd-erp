<?php

namespace app\common\config;

/**
 * 生产模块常量
 */
class ProductionConfig
{
    /**
     * 计划完成容差：后道工序报工 ≥ 计划×该系数可标工单/合并池完成。
     * [AI-2026-08] 与盘点消耗折算无关；盘点仍用 MaterialInventoryService 自有系数。
     */
    public const PLAN_COMPLETE_RATIO = 0.97;

    /** @deprecated 请用 PLAN_COMPLETE_RATIO；保留别名避免旧引用报错 */
    public const PUNCH_TO_DUANGANG_RATIO = self::PLAN_COMPLETE_RATIO;

    public const PROCESS_NAME_DUANGANG = '断钢';

    public const PROCESS_NAME_PUNCH = '穿孔';

    public const PROCESS_NAME_SPHEROIDIZE = '球化';

    public const PROCESS_NAME_STRAIGHTEN = '矫直';

    public const PROCESS_NAME_PICKLE = '酸洗';

    /**
     * 工单自动完成判定工序（不含断钢：合并报工后不宜按单卡断钢）
     *
     * @return string[]
     */
    public static function planCompleteProcessNames(): array
    {
        return [
            self::PROCESS_NAME_PUNCH,
            self::PROCESS_NAME_SPHEROIDIZE,
            self::PROCESS_NAME_STRAIGHTEN,
            self::PROCESS_NAME_PICKLE,
        ];
    }

    /** 计划穿孔设备最多绑定数量 */
    public const PUNCH_DEVICE_MAX = 2;
}
