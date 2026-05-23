# Cursor 协作记忆 — 高齿传动生产管理系统

> 供后续对话快速恢复上下文。**维护人**：项目负责人（Cursor 主导开发）；原前后端仅负责打包上传与云端保活。

## 1. 项目与仓库

- **前端**：`yarn/`（Vue3 + Vite + Element Plus），本地 `npm run dev` / `npm run build:prod`。
- **后端**：`gccd_server/`（ThinkPHP 8，PHP ≥ 8），站点根目录一般为 `gccd_server/public`。
- **小程序**：`gccd_mini/`（领料申请等，与 PC 出库/库存配合）。
- **业务文档**：`docs/`（含批次/成本对照表、本记忆文件）。

## 2. 业务目标（长期）

1. **追溯**：计划/成品侧主键 → 报工 → 领料/出库 → **原材料批次**（不断链）。
2. **物料成本闭环**：领料/出库明细 **物料 + 批次 + 数量 × 入库单价 → 金额**，与 `wms_material` 扣账一致。

现场未必有「批次」口语，批次是**数字化转型规范**中的账面概念；系统以 `material_batch` + `material_id` 为库存最小粒度之一。

## 3. 已落地的技术要点（随迭代更新）

| 主题 | 说明 |
|------|------|
| 物料库存列表串批显示 | `InStockService::materiallist` 已改为按当前行批次 join `wms_in` 取金额，不再把同物料多批拼成一串覆盖 `material_batch`。 |
| 出库确认扣账 | `OutStockService::editStatus` 调用 `AddmaterialNum` 时**必须传入**库管确认的 `material_batch`，避免只按 `material_id` 误扣。 |
| 出库确认 / 新增出库库存校验 | `editStatus` 与 `add`（当明细带 `material_batch`）均校验：**出库数量 ≤ 该批 `wms_material.material_num`**；确认时批次为空会直接拒绝（与校验器一致）。 |
| 新增出库带批次 | PC `NewOutbound.vue` 提交 `content` 时写入 `material_batch`，与所选库存行一致。 |
| 配件出库数量 | `NewOutbound` 配件仓前端校验出库数量不大于库存。 |
| 追溯只读页 | 前端路由 `#/report/batch-material-trace`（报表中心下「批次物料追溯」）；接口 `POST /admin/stock.OutStock/traceMaterialFlow`，body `{ keyword }`。 |

## 4. 关键路径文件（批次 / 出库 / 库存）

- 后端：`OutStockService.php`、`InStockService.php`、`TraceabilityService.php`、`WmsMaterialModel.php`、`app/admin/controller/stock/OutStock.php`
- 前端：`OutboundList`、`NewOutbound.vue`、`MaterialInventory.vue`、`BatchMaterialTrace.vue`
- API：`yarn/src/api/mes/WarehouseManagement/OutboundManagement.js`

## 5. 已知缺口 / 待排期（ backlog 摘要）

- 出库单行 **多批次拆量**（一次申请多批凑数）：产品未做，需明细多行或向导 + 接口支持。
- **无批次出库**：`AddmaterialNum` 仍保留按 `material_id` 单条扣减路径，长期建议禁止或仅历史数据。
- 追溯页 v1：**未**自动串联报工明细，可按关键字扩展二期接口。
- **服务端权限**：新接口走现有 `OutStock` 控制器，需确认菜单/权限是否拦截；若 403 需在后台菜单中配置。

## 6. 协作约定

- 需求与阶段结论尽量落入 `docs/`，避免仅存在于聊天。
- 发版给工程师：说明 **构建产物路径**、**改动文件列表**、**是否清缓存 / 重启 PHP**，不必再依赖其做功能设计。

---
*最后更新：批次追溯页 + 出库库存校验 + NewOutbound 传批次 上线于本仓库提交。*
