# 最低库存阈值部署说明

<!-- [AI-2026-08] 库存阈值改为按物料与仓库范围维护。 -->

## 部署顺序

1. 备份数据库。
2. 执行 `gccd_server/database/material_stock_threshold.sql`。
3. 发布后端代码。
4. 发布后台前端代码并刷新浏览器缓存。
5. 运行以下验证命令并确认全部通过：
   - `php gccd_server/tests/service/material-stock-threshold-test.php`
   - 在 `yarn/yarn` 下运行
     `node --test src/tests/material-stock-threshold.contract.test.mjs`
6. 分别在“库存管理 → 配件库/圆钢库”检查最低库存、库存状态和预警汇总。

必须先执行 SQL 再发布代码。新代码不会在阈值表缺失时静默回退到
`ya_material.min_stock_qty`。

## 兼容说明

- 迁移脚本可重复执行，首次执行时从未删除的配件、圆钢档案回填正数阈值。
- 旧列 `ya_material.min_stock_qty` 暂不删除，仅作为迁移来源。
- 旧脚本 `gccd_server/scripts/add-min-stock-qty.sql` 保留，不参与新链路运行时读写。
- 阈值存于 `ya_material_stock_threshold`，唯一维度为
  `material_id + warehouse_scope`，不会写入库存批次行。
