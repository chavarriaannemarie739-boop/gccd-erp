-- [AI-2026-08] 盘点圆钢厂商快照；兼容 MySQL 5.5，不改写历史数据。
SET @manufacturer_snapshot_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ya_material_inventory'
      AND COLUMN_NAME = 'y_material_manufacturer'
);

SET @manufacturer_snapshot_sql = IF(
    @manufacturer_snapshot_exists = 0,
    'ALTER TABLE `ya_material_inventory` ADD COLUMN `y_material_manufacturer` VARCHAR(255) NULL DEFAULT NULL COMMENT ''圆钢厂商快照'' AFTER `material_name`',
    'SELECT 1'
);

PREPARE manufacturer_snapshot_stmt FROM @manufacturer_snapshot_sql;
EXECUTE manufacturer_snapshot_stmt;
DEALLOCATE PREPARE manufacturer_snapshot_stmt;
