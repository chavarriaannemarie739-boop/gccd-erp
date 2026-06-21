-- [AI-2026-06] 物料最低库存预警阈值（配件/圆钢档案维护，库存列表展示）
-- 执行前请备份；列已存在则跳过

ALTER TABLE `ya_material`
    ADD COLUMN `min_stock_qty` DECIMAL(12, 3) DEFAULT NULL COMMENT '最低库存预警阈值，NULL或0表示不预警' AFTER `material_property`;
