-- [AI-2026-10] 酸洗盘点覆盖：给成品调整单补盘点归属日。可重复执行，列已存在则跳过。
-- 不跑这句也能提交：盘点日会写在 reason 里。跑过之后调整单多一列，方便按盘点日筛选。

SET @stocktake_date_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ya_stock_adjust'
      AND COLUMN_NAME = 'stocktake_date'
);

SET @add_stocktake_date_sql = IF(
    @stocktake_date_exists = 0,
    'ALTER TABLE `ya_stock_adjust` ADD COLUMN `stocktake_date` date DEFAULT NULL COMMENT ''酸洗盘点归属日'' AFTER `adjust_type`',
    'SELECT 1'
);

PREPARE add_stocktake_date_stmt FROM @add_stocktake_date_sql;
EXECUTE add_stocktake_date_stmt;
DEALLOCATE PREPARE add_stocktake_date_stmt;
