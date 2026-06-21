-- 断钢合并池 + 穿孔设备（1~2台）— 2026-06
-- 执行前请备份数据库

CREATE TABLE IF NOT EXISTS `ya_duangang_merge_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `merge_key` varchar(255) NOT NULL DEFAULT '' COMMENT '合并键',
  `steel_grade` varchar(64) NOT NULL DEFAULT '' COMMENT '归一钢号或空',
  `y_material_spec` varchar(255) NOT NULL DEFAULT '' COMMENT '圆钢规格(邯钢/潍坊/45#等)',
  `y_material_model` varchar(128) NOT NULL DEFAULT '' COMMENT '圆钢型号',
  `c_material_spec` varchar(128) NOT NULL DEFAULT '' COMMENT '成品规格',
  `capacity_total` decimal(16,3) NOT NULL DEFAULT '0.000' COMMENT '可报总量',
  `reported_total` decimal(16,3) NOT NULL DEFAULT '0.000' COMMENT '已报总量',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0进行中 1已完成',
  `is_delete` tinyint(1) NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_merge_key_status` (`merge_key`,`status`,`is_delete`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='断钢合并池';

CREATE TABLE IF NOT EXISTS `ya_duangang_merge_member` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `merge_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `product_task_id` int(11) unsigned NOT NULL DEFAULT '0',
  `task_code` varchar(64) NOT NULL DEFAULT '',
  `relation_code` varchar(64) NOT NULL DEFAULT '' COMMENT '计划单号',
  `material_id` int(11) NOT NULL DEFAULT '0',
  `task_num` decimal(16,3) NOT NULL DEFAULT '0.000',
  `remain_capacity` decimal(16,3) NOT NULL DEFAULT '0.000',
  `is_delete` tinyint(1) NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`merge_group_id`),
  KEY `idx_task_id` (`product_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='断钢合并池成员';

CREATE TABLE IF NOT EXISTS `ya_duangang_merge_report` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `merge_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `report_code` varchar(64) NOT NULL DEFAULT '',
  `processes_code` varchar(64) NOT NULL DEFAULT '',
  `report_num` decimal(16,3) NOT NULL DEFAULT '0.000',
  `defective_num` decimal(16,3) NOT NULL DEFAULT '0.000',
  `device_code` varchar(64) NOT NULL DEFAULT '',
  `device_name` varchar(128) NOT NULL DEFAULT '',
  `material_id` int(11) NOT NULL DEFAULT '0',
  `remark` varchar(500) NOT NULL DEFAULT '',
  `defective` text,
  `data` text,
  `report_date` date DEFAULT NULL,
  `class` tinyint(1) NOT NULL DEFAULT '0',
  `create_uid` int(11) NOT NULL DEFAULT '0',
  `create_time` datetime DEFAULT NULL,
  `is_delete` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_group_id` (`merge_group_id`),
  KEY `idx_report_code` (`report_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='断钢合并报工';

CREATE TABLE IF NOT EXISTS `ya_production_punch_device` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `production_plan_code` varchar(64) NOT NULL DEFAULT '',
  `device_code` varchar(64) NOT NULL DEFAULT '',
  `device_name` varchar(128) NOT NULL DEFAULT '',
  `sort` tinyint(1) NOT NULL DEFAULT '1',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_device` (`production_plan_code`,`device_code`),
  KEY `idx_plan_code` (`production_plan_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产计划穿孔设备';

CREATE TABLE IF NOT EXISTS `ya_product_task_punch_device` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `task_code` varchar(64) NOT NULL DEFAULT '',
  `product_task_id` int(11) unsigned NOT NULL DEFAULT '0',
  `device_code` varchar(64) NOT NULL DEFAULT '',
  `device_name` varchar(128) NOT NULL DEFAULT '',
  `sort` tinyint(1) NOT NULL DEFAULT '1',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_code` (`task_code`),
  KEY `idx_task_device` (`task_code`,`device_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产工单穿孔设备';
