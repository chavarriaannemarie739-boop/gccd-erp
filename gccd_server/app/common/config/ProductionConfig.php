<?php

namespace app\common\config;

/**
 * 生产模块常量
 */
class ProductionConfig
{
    /** 穿孔报工折算断钢系数：断钢已完成 = 穿孔累计 / PUNCH_TO_DUANGANG_RATIO */
    public const PUNCH_TO_DUANGANG_RATIO = 0.97;

    public const PROCESS_NAME_DUANGANG = '断钢';

    public const PROCESS_NAME_PUNCH = '穿孔';

    /** 计划穿孔设备最多绑定数量 */
    public const PUNCH_DEVICE_MAX = 2;
}
