# 盘点 / 智能推算 — 上下文记忆（压缩版）

> 更新：2026-05-20 · 模块：小程序盘点 + 后端 `MaterialInventoryService`

---

## 1. 核心业务规则（必须一致）

| 规则 | 说明 |
|------|------|
| 期初 | 每个品种 **最后一次手动盘点**（`source=manual` 或空，排除 `import`） |
| 统计区间 | **期初盘点日 + 1 天** ～ **推算日 − 1 天**（按品种独立） |
| 推算日 `calc_date` | **必须是「今天」**；流水统计到 **昨天** |
| 盘点日 vs 推算日 | 列表页「盘点日期」= 昨天（填报用）；**与 `calc_date` 无关** |
| 当日已手动 | `inventory_date === calc_date` → 直接用期初，不再扣流水 |
| 圆钢入库 | 仅 `warehouse LIKE '%圆钢%'`（与 PC 入库「圆钢仓库」Tab 一致） |
| 圆钢断钢消耗 | 有 `material_id + 型号` 时 **只按 material_id**，禁止「名称+规格」回落（防 20#/60 扣到 20#/75） |

**公式（圆钢）**：`预测 = 期初 + 圆钢仓入库 − 断钢(gx_0010)消耗`

---

## 2. 关键代码位置

| 路径 | 职责 |
|------|------|
| `gccd_server/app/common/service/admin/MaterialInventoryService.php` | 期初、推算、月台账（月台账逻辑未完全对齐） |
| `gccd_server/app/admin/controller/production/Inventory.php` | `autoCalc` / `submitStocktake` |
| `gccd_mini/pageWorkOrder/pages/stocktakeList/stocktakeList.js` | 智能推算入口；**已改** `calc_date=今天` |
| `gccd_mini/pageWorkOrder/pages/stocktakeAdd/stocktakeAdd.js` | 预填、提交、本地缓存 |
| `盘点功能使用说明.md` | 操作员说明 |

---

## 3. 已修复问题（需部署后端 + 发版小程序）

| # | 问题 | 修复 |
|---|------|------|
| 1 | 全厂统一期初日 / 工序混用 | `buildLatestManualBaseline()` 按品种取最后手动 |
| 2 | 成品库圆钢入库参与推算（如澳森55 18.8） | `applyRoundSteelInboundWarehouseFilter()` |
| 3 | 小程序传「昨天」作 `calc_date` → 区间为空、只显示期初 | `stocktakeList.js` 传 **今天** |
| 4 | 圆钢同规格多型号串扣（凯亚20#75 显示 1.2） | `sumReportDaily` / `sumInboundDaily` 有型号时仅 `material_id` |
| 5 | material_id 错乱、圆钢重复行 | `resolveRoundSteelMaterialId`、`dedupeCalcResults`、提交时纠正 id |

---

## 4. 典型数据问题（非公式错）

| 现象 | 原因 | 处理 |
|------|------|------|
| 推算 = 期初不变 | `calc_date` 仍用昨天；或该区间无报工/入库 | 发版小程序；查报工日期 |
| 推算与报工对不上 | 盘点 **凯亚**，报工 **溯源**（同规格型号不同类别） | 统一档案或改盘点/报工（例：巨能50 id=291 已改 凯亚→溯源/816） |
| 仍用旧期初 | 该品种 **未在盘点日提交一行**（例：凯亚20#50 无5/18手动） | 补提交手动（含 0 吨） |
| 期初 material_id 与填写不符 | 提交时选错档案（如填75 id=3440 档案70） | 改库或重提；推算按 y_spec+y_model 纠正 id |
| 负数预测 | 期初小于区间消耗（例：富伦50） | 核对手动与报工 |
| Step6 无期初品种 | 仅有入库、无手动 | 产品倾向：只推算有手动期初的品种（未全关） |

---

## 5. 已核对样例（calc_date=2026-05-20）

| 品种 | 期初(5/18) | 5/19断钢 | 推算 |
|------|------------|----------|------|
| 溯源/宣钢/70 | 66.84 | 34.2 (25.8+28.5) | **32.64** ✓ |
| 溯源/巨能/50 | 180.21 | 54.4 | **125.81**（改 id=291 后） |
| 凯亚/20#/75 | 36.5 | 0（75无报工；勿扣60的35.3） | **36.5**（修串型号后） |

---

## 6. 审计工具

```bash
# 纯 SQL 圆钢批量核对（推荐）
php gccd_server/runtime/audit_stocktake_pure.php 2026-05-20
# 输出 JSON：runtime/audit_stocktake_YYYY-MM-DD.json

# Think 命令（本地 PHP8 可能因 apidoc 弃用报错）
php think audit-stocktake-calc 2026-05-20
```

---

## 7. 数据库备忘

- 库：`gxgccd`，表前缀 `ya_`
- 盘点：`ya_material_inventory`
- 报工：`ya_product_report`（断钢 `processes_code=gx_0010`）
- 入库：`ya_wms_in`（圆钢仓 `warehouse LIKE '%圆钢%'`）
- 档案：`ya_material`（圆钢 `material_property=圆钢`）

**手动改数示例**（巨能50 类别纠错）：
```sql
UPDATE ya_material_inventory
SET material_name='溯源', material_id=816, update_time=NOW()
WHERE id=291 AND process_name='圆钢' AND inventory_date='2026-05-18';
```

---

## 8. 待办 / 未改

- [ ] `getMonthlyLedger()` 与 `autoCalculateInventory` 规则对齐
- [ ] Step6「新入库自动检出」是否关闭或收紧（用户倾向仅推算有手动期初）
- [ ] 提交时强制校验 `material_id` 与 名称/规格/型号 一致
- [ ] PC 入库删除默认物理删（`InStockService::dele real=true`）评估
- [ ] 5/18 批量 material_id 错乱清洗（约 15 条圆钢）

---

## 9. 部署检查清单

1. 部署 `MaterialInventoryService.php`
2. 发版小程序（含 `stocktakeList.js` calc_date 修复）
3. 列表页点「智能推算」→ 弹窗应含「推算日今天、流水至昨天」
4. 抽测：宣钢70=32.64、巨能50=125.81、凯亚20#75=36.5
