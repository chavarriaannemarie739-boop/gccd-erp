-- 补充添加剩余字段（device_name 已存在，跳过）

ALTER TABLE `ya_maintenance_report` ADD COLUMN `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注' AFTER `user_names`;
ALTER TABLE `ya_maintenance_report` ADD COLUMN `report_type` varchar(50) NOT NULL DEFAULT '' COMMENT '报工类型' AFTER `id`;
ALTER TABLE `ya_maintenance_report` ADD COLUMN `device_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '设备ID' AFTER `report_type`;
