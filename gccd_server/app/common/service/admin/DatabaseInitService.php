<?php

// +----------------------------------------------------------------------
// | 高齿传动生产管理系统
// +----------------------------------------------------------------------
// | 数据库初始化服务 - 一次性执行建表和加字段
// | 应在部署时手动调用，不应在每次请求时执行
// +----------------------------------------------------------------------

namespace app\common\service\admin;

use think\facade\Db;

class DatabaseInitService
{
    private static $initialized = false;

    public static function ensure(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        try {
            Db::execute("ALTER TABLE ya_product_report ADD COLUMN class INT(1) DEFAULT 0 COMMENT '班次 0白班 1夜班'");
        } catch (\Exception $e) {
        }

        try {
            Db::execute("ALTER TABLE ya_user_wage_details ADD COLUMN class INT(1) DEFAULT 0 COMMENT '班次 0白班 1夜班'");
        } catch (\Exception $e) {
        }

        try {
            Db::execute("ALTER TABLE ya_material ADD COLUMN unit_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '物料单价'");
        } catch (\Exception $e) {
        }

        try {
            Db::execute("
                CREATE TABLE IF NOT EXISTS `ya_daily_report_manual` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `report_date` varchar(20) DEFAULT NULL COMMENT '报表日期',
                  `processes_name` varchar(50) DEFAULT NULL COMMENT '工序名称',
                  `accessory_amount` decimal(10,2) DEFAULT '0.00' COMMENT '配件金额',
                  `guide_amount` decimal(10,2) DEFAULT '0.00' COMMENT '顶头导板金额',
                  `gas_num` decimal(10,2) DEFAULT '0.00' COMMENT '天然气量',
                  `gas_amount` decimal(10,2) DEFAULT '0.00' COMMENT '天然气发生额',
                  `power_num` decimal(10,2) DEFAULT '0.00' COMMENT '电量',
                  `power_amount` decimal(10,2) DEFAULT '0.00' COMMENT '电费发生额',
                  `wage_amount` decimal(10,2) DEFAULT '0.00' COMMENT '工资',
                  `create_uid` int(11) DEFAULT '0',
                  `create_time` datetime DEFAULT NULL,
                  `update_time` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`) USING BTREE,
                  UNIQUE KEY `idx_date_process` (`report_date`,`processes_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='日报表手工调整数据'
            ");
        } catch (\Exception $e) {
        }

        try {
            Db::execute("
                CREATE TABLE IF NOT EXISTS `ya_supervisor_attendance` (
                  `id`              int(11)      NOT NULL AUTO_INCREMENT,
                  `user_id`         int(11)      NOT NULL DEFAULT '0' COMMENT '管理员用户ID',
                  `attendance_date` date         NOT NULL COMMENT '签到日期',
                  `check_in_time`   datetime     DEFAULT NULL COMMENT '签到时间',
                  `check_out_time`  datetime     DEFAULT NULL COMMENT '签退时间',
                  `work_hours`      decimal(4,1) NOT NULL DEFAULT '12.0' COMMENT '实际工作小时数',
                  `status`          tinyint(4)   NOT NULL DEFAULT '0' COMMENT '状态：0已签到未签退 1已签退',
                  `remark`          varchar(255) DEFAULT '' COMMENT '备注',
                  `create_time`     datetime     DEFAULT NULL COMMENT '创建时间',
                  `update_time`     datetime     DEFAULT NULL COMMENT '更新时间',
                  PRIMARY KEY (`id`),
                  KEY `idx_user_date` (`user_id`, `attendance_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='车间主任/工序主管签到表'
            ");
        } catch (\Exception $e) {
        }

        try {
            Db::execute("
                CREATE TABLE IF NOT EXISTS `ya_daily_summary` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `report_date` date NOT NULL COMMENT '报表日期',
                  `processes_name` varchar(50) NOT NULL DEFAULT '' COMMENT '工序名称',
                  `class` tinyint(1) NOT NULL DEFAULT 0 COMMENT '班次 0白班 1夜班',
                  `production_qty` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT '产量合计',
                  `defective_report_qty` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT '报工不良数',
                  `defective_qc_qty` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT '质检不良数',
                  `gas_consume` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT '天然气消耗合计',
                  `power_consume` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT '电消耗合计',
                  `gas_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '天然气金额',
                  `power_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '电费金额',
                  `accessory_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '配件金额',
                  `guide_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '顶头导板金额',
                  `wage_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '工资合计',
                  `report_defect_category_json` longtext COMMENT '报工不良分类(每日合计KEY)',
                  `qc_defect_category_json` longtext COMMENT '质检不良分类(每日合计KEY)',
                  `create_time` datetime DEFAULT NULL,
                  `update_time` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `idx_date_process_class` (`report_date`, `processes_name`, `class`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='生产日汇总表'
            ");
        } catch (\Exception $e) {
        }
    }
}
