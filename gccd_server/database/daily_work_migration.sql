-- ========================================
-- 穿孔工序日工工资统计 - 数据库变更脚本
-- 数据库：gxgccd（线上请逐段执行，已存在的 ALTER 报错可忽略）
-- ========================================

-- 1. 创建日工记录表
CREATE TABLE IF NOT EXISTS `ya_daily_work` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '主键 ID',
  `user_id` int NOT NULL COMMENT '报工人员 ID',
  `work_date` date NOT NULL COMMENT '工作日期',
  `work_type` tinyint NOT NULL DEFAULT 1 COMMENT '报工类型：1=全天日工，2=半天日工',
  `work_hours` decimal(5,1) NOT NULL DEFAULT 12.0 COMMENT '实际工时',
  `daily_wage` decimal(10,2) NOT NULL COMMENT '日工工资',
  `team_leader_allowance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '岗位津贴',
  `total_wage` decimal(10,2) NOT NULL COMMENT '合计工资',
  `report_id` int DEFAULT 0 COMMENT '关联报工记录ID',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `create_uid` int NOT NULL COMMENT '创建人 ID',
  `update_time` datetime DEFAULT NULL COMMENT '更新时间',
  `update_uid` int DEFAULT NULL COMMENT '更新人 ID',
  PRIMARY KEY (`id`),
  KEY `idx_work_date` (`work_date`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='日工记录表';

-- 2. 用户工资明细表增加工资类型（字段已存在则跳过）
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ya_user_wage_details' AND COLUMN_NAME = 'wage_type'
);
SET @sqlstmt := IF(
  @exist = 0,
  'ALTER TABLE `ya_user_wage_details` ADD COLUMN `wage_type` varchar(50) DEFAULT NULL COMMENT ''工资类型：piece=计件，fixed=固定工资，daily_work=日工，team_leader_allowance=岗位津贴'' AFTER `relation_code`',
  'SELECT 1'
);
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. 产品报工表增加日工标识（字段已存在则跳过）
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ya_product_report' AND COLUMN_NAME = 'is_daily_work'
);
SET @sqlstmt := IF(
  @exist = 0,
  'ALTER TABLE `ya_product_report` ADD COLUMN `is_daily_work` tinyint NOT NULL DEFAULT 0 COMMENT ''是否日工报工：0=否，1=是'' AFTER `processes_code`',
  'SELECT 1'
);
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ========================================
-- 变更完成
-- ========================================
