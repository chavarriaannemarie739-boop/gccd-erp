-- [AI-2026-08] 最低库存阈值从物料档案迁移到“物料 + 仓库范围”。
-- 部署顺序：必须先执行本文件，再发布后端与后台前端代码。
-- MySQL 5.5 兼容；旧 ya_material.min_stock_qty 暂不删除，仅作幂等迁移来源。

CREATE TABLE IF NOT EXISTS `ya_material_stock_threshold` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `material_id` int(11) unsigned NOT NULL COMMENT '物料ID',
  `warehouse_scope` varchar(16) NOT NULL COMMENT '仓库范围：配件/圆钢',
  `min_stock_qty` decimal(18,3) NOT NULL COMMENT '最低库存数量',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `create_uid` int(11) unsigned DEFAULT NULL COMMENT '创建人ID',
  `update_time` datetime DEFAULT NULL COMMENT '更新时间',
  `update_uid` int(11) unsigned DEFAULT NULL COMMENT '更新人ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_material_scope` (`material_id`, `warehouse_scope`),
  KEY `idx_scope_threshold` (`warehouse_scope`, `min_stock_qty`),
  KEY `idx_material_id` (`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='物料仓库范围最低库存阈值';

-- [AI-2026-08] 幂等修复历史空仓库库存行；仅按有效配件/圆钢档案补规范仓库值。
UPDATE `ya_wms_material` AS `w`
INNER JOIN `ya_material` AS `m` ON `m`.`id` = `w`.`material_id`
SET `w`.`stock` = CASE
  WHEN `m`.`material_property` = '配件' THEN '配件仓库'
  WHEN `m`.`material_property` = '圆钢' THEN '圆钢仓库'
  ELSE `w`.`stock`
END
WHERE `w`.`is_delete` = 0
  AND `m`.`is_delete` = 0
  AND `m`.`material_property` IN ('配件', '圆钢')
  AND (`w`.`stock` IS NULL OR TRIM(`w`.`stock`) = '');

INSERT IGNORE INTO `ya_material_stock_threshold`
  (`material_id`, `warehouse_scope`, `min_stock_qty`, `create_time`, `create_uid`)
SELECT
  `id`,
  `material_property`,
  `min_stock_qty`,
  NOW(),
  NULL
FROM `ya_material`
WHERE `is_delete` = 0
  AND `material_property` IN ('配件', '圆钢')
  AND `min_stock_qty` IS NOT NULL
  AND `min_stock_qty` > 0;
