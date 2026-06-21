# 报工纠错与盘点推算 — 会话记忆（2026-05）

## 一、核心结论（给后续 AI / 开发）

### 1. 报工删改必须联动，不能只改 `ya_product_report` 一行

| 操作 | 已实现联动（`ProductReportService.php`） |
|------|----------------------------------------|
| **删除** | 工资明细+月汇总扣回、末道成品入库冲销、工单 `report` JSON 重算、日汇总刷新 |
| **编辑** | 同上 + 改工单同步 `material_id`/`routing_code`、末道入库数量调整、工资重算 |

**归属日期**：联动重算工资、日汇总时以记录上的 **`report_date`** 为准，不用当前提交时间重算 12 点规则。

**改工单号**：
- 可填 `scgd-*`（任务单号）或误填 `scjh-*`（计划单号），后端按任务/计划反查并**落库为 task_code**。
- 列表圆钢/成品规格由 `enrichReportListRow` 按当前任务重算展示。

### 2. 12 点归属规则（不可乱）

```
report_date = date('Y-m-d', strtotime('-12 hours'))
```
- 12 点前提交 → 记**昨天**；12 点及以后 → 记**今天**
- 详见 `project_gaochi_report_date_rule.md`

**纠错实操（未部署联动前）**：删错单 → 正确工单重报 → 后台编辑把 **归属日期** 改回应记那天。  
**勿**指望删了重报自动保持昨天（重报按提交时刻算日期）。

### 3. 盘点智能推算 — 关键公式与坑

#### 圆钢（溯源｜巨能｜50 等）
```
结存 = 期初 + 入库 − 断钢消耗(gx_0010)
```
- 消耗按 **类别+规格+型号**（`variety` 索引）汇总，避免改工单后凯亚/溯源 `material_id` 不一致漏计。
- 改 `relation_code` 后需**再保存一次**报工以同步 `material_id`。

#### 断钢（凯亚｜20#｜50 等，如 50*1.5）
```
结存 = 期初 + 断钢产出(gx_0010) − 穿孔消耗(gx_0011) ÷ 0.97
```
- **穿孔报工吨数 ÷ 0.97 = 圆钢/断钢消耗**（3% 损耗）。
- 曾 bug：`autoCalculateInventory` 断钢分支未 ÷0.97 → 显示 70.4 而非 67.14（例：176.01 − 105.61）。
- 已修：常量 `PROCESS_YIELD_RATIO = 0.97`，与月度台账一致。

#### 数字核对例（断钢 凯亚｜20#｜50）
| 项 | 值 |
|----|-----|
| 期初 | 176.01 |
| 穿孔报工 | 23.99 + 29.15 + 52.47 = 105.61 |
| 消耗(÷0.97) | 108.87 |
| 推算结存 | **67.14**（无期内断钢产出时） |

### 4. 用户要的流程（已对齐）

- **不要**复杂审批流；**要**管理员后台直接改/删报工，全套数据自动对。
- **不要**员工 10 分钟撤回（上级发现为主）。
- **不要**仅靠扫码防错（现场做不到）。

---

## 二、改动文件清单（部署用）

| 文件 | 作用 |
|------|------|
| `gccd_server/app/common/service/admin/ProductReportService.php` | 删改联动、改工单同步物料、入库 remark 绑定 `report:{code}`、工资按 `report_date` |
| `gccd_server/app/common/service/admin/MaterialInventoryService.php` | 报工按 spec+model/variety 汇总；断钢穿孔 ÷0.97 |
| `yarn/yarn/src/views/mes/ProductionOrderList/WorkReport/components/EditorsReport.vue` | 编辑增加「生产任务单号」 |

小程序 `stocktakeAdd` **无代码改动**，用接口 `Inventory/autoCalculate` → `MaterialInventoryService::autoCalculateInventory`。

---

## 三、数据关联速查

```
product_report.report_code  →  user_wage_details.relation_code
末道报工                    →  wms_in.remark = 'report:{report_code}'（新单）；旧单按计划+数量+时间匹配
product_report.report_date  →  日产量 daily_summary、盘点报工区间
product_report.relation_code →  product_task.task_code（非 scjh 计划号）
product_report.material_id  →  圆钢规格展示、推算 id 维度
```

**工序编码（推算）**：圆钢扣 `gx_0010` 断钢；断钢扣 `gx_0011` 穿孔（÷0.97）。

---

## 四、智能盘点操作顺序

1. 列表页 → **智能推算**（写缓存 `stocktake_auto_calc`）
2. 断钢/圆钢填报页 → **智能预填**（读缓存覆盖草稿）
3. 改报工/部署后 → **必须重新推算**再预填

---

## 五、未做 / 注意

- 工序成本报表部分仍按 `create_time` 筛，与 `report_date` 可能不一致。
- 历史入库无 `report:` 备注时，删报工入库匹配为启发式（计划号+数量+时间窗）。
- 同一计划多任务、多圆钢子任务时，改工单取第一条匹配 `material_id` 的任务行。

---

*压缩自 2026-05 报工纠错与盘点推算讨论。*
