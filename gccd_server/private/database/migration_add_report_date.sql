-- 报工归属日期字段（12点分界规则）
-- ya_product_report: 生产报工
-- ya_product_power: 能耗报工

ALTER TABLE `ya_product_report` ADD COLUMN `report_date` date DEFAULT NULL COMMENT '报工归属日期（12点前归昨天，12点后归今天）' AFTER `class`;
UPDATE `ya_product_report` SET `report_date` = DATE(`create_time`) WHERE `report_date` IS NULL;

ALTER TABLE `ya_product_power` ADD COLUMN `report_date` date DEFAULT NULL COMMENT '报工归属日期' AFTER `class`;
UPDATE `ya_product_power` SET `report_date` = DATE(`create_time`) WHERE `report_date` IS NULL;
