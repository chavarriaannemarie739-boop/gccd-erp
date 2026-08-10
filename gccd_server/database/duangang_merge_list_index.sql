-- [AI-2026-08] 断钢合并池列表：按进行中/已完成 + 更新时间分页，避免全表 filesort
-- MySQL 5.5+ 兼容：索引不存在时再执行；重复执行可能报 Duplicate key name

ALTER TABLE `ya_duangang_merge_group`
  ADD INDEX `idx_delete_status_update` (`is_delete`, `status`, `update_time`);

ALTER TABLE `ya_duangang_merge_member`
  ADD INDEX `idx_delete_group` (`is_delete`, `merge_group_id`);
