-- 断钢合并池：补充圆钢规格展示字段
ALTER TABLE `ya_duangang_merge_group`
  ADD COLUMN `y_material_spec` varchar(255) NOT NULL DEFAULT '' COMMENT '圆钢规格(邯钢/潍坊/45#等)' AFTER `steel_grade`;
