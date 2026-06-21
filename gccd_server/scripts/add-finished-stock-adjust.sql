-- [AI-2026-06] 成品库：归属转移、库存调整、发货归属
-- 执行前请备份；列/表已存在则跳过对应语句

CREATE TABLE IF NOT EXISTS `ya_stock_owner_transfer` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `transfer_code` varchar(64) NOT NULL DEFAULT '' COMMENT '转移单号',
    `plan_code` varchar(64) NOT NULL DEFAULT '' COMMENT '生产计划单号',
    `material_id` int(11) NOT NULL DEFAULT '0' COMMENT '成品物料ID',
    `material_batch` varchar(64) NOT NULL DEFAULT '' COMMENT '批次号(计划单号)',
    `from_owner` varchar(128) NOT NULL DEFAULT '' COMMENT '转出归属',
    `to_owner` varchar(128) NOT NULL DEFAULT '' COMMENT '转入归属',
    `transfer_qty` decimal(12, 2) NOT NULL DEFAULT '0.00' COMMENT '转移数量',
    `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
    `create_uid` int(11) NOT NULL DEFAULT '0',
    `create_time` datetime DEFAULT NULL,
    `is_delete` tinyint(1) NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`),
    KEY `idx_plan_code` (`plan_code`),
    KEY `idx_material_batch` (`material_batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='成品库存归属转移';

CREATE TABLE IF NOT EXISTS `ya_stock_adjust` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `adjust_code` varchar(64) NOT NULL DEFAULT '' COMMENT '调整单号',
    `plan_code` varchar(64) NOT NULL DEFAULT '' COMMENT '生产计划单号',
    `material_id` int(11) NOT NULL DEFAULT '0' COMMENT '成品物料ID',
    `material_batch` varchar(64) NOT NULL DEFAULT '' COMMENT '批次号',
    `stock_owner` varchar(128) NOT NULL DEFAULT '' COMMENT '归属(客户名/库存)',
    `adjust_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'write_off|over_ship',
    `adjust_qty` decimal(12, 2) NOT NULL DEFAULT '0.00' COMMENT '调整量(清零为负)',
    `before_qty` decimal(12, 2) NOT NULL DEFAULT '0.00' COMMENT '调整前可用',
    `after_qty` decimal(12, 2) NOT NULL DEFAULT '0.00' COMMENT '调整后可用',
    `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
    `ship_code` varchar(64) NOT NULL DEFAULT '' COMMENT '关联发货单号',
    `create_uid` int(11) NOT NULL DEFAULT '0',
    `create_time` datetime DEFAULT NULL,
    `is_delete` tinyint(1) NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`),
    KEY `idx_plan_owner` (`plan_code`, `stock_owner`),
    KEY `idx_adjust_type` (`adjust_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='成品库存调整';

-- ya_ship 增加发货归属（从哪条成品库归属行发出）
ALTER TABLE `ya_ship`
    ADD COLUMN `stock_owner` varchar(128) NOT NULL DEFAULT '' COMMENT '成品库归属(客户名/库存)' AFTER `customer_name`;
