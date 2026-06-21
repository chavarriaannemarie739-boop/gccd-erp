# 断钢合并报工 + 小程序工序入口 — 实施记忆（2026-06）

> **状态**：已开发并线上跑通（合并池重建、列表展示、检索）。  
> **关联备忘（未开发）**：[DUANGANG-MULTI-PLAN-REPORT-BACKLOG.md](DUANGANG-MULTI-PLAN-REPORT-BACKLOG.md)（流转卡扫码方案）

---

## 1. 业务规则（定稿）

### 1.1 断钢合并键

| 维度 | 字段来源 | 说明 |
|------|----------|------|
| 圆钢规格 | `material.material_spec` → 工单 `y_material_spec` | 如邯钢、潍坊、巨能；含 `#` 时提取钢号 |
| 圆钢型号 | `material.material_model` → `y_material_model` | 如 50、65、70 |
| 成品规格 | 计划 `english_material` → 成品 `material_spec` → `c_material_spec` | 如 50×1.2；**不含成品型号** |

**合并规则：**

- **有钢号**（如天钢45#、邯钢45#）：按 **归一钢号 + 型号 + 成品规格** 合并（45# 相同可合并）。
- **无 `#`**（如邯钢、潍坊）：按 **圆钢规格 + 型号 + 成品规格** 合并（邯钢50×1.2 ≠ 潍坊50×1.2）。

**合并池容量：** `Σ(task_num − 穿孔累计 ÷ 0.97)`；穿孔 97t ≈ 断钢 100t。  
**退出合并池：** 穿孔累计 ÷ 0.97 ≥ `task_num`。

**报工方式：** 断钢只在合并池报工（`DuangangMerge/report`），禁止走旧 `ProductReport/report` 断钢入口；报工后成员工单 `process_code` 推进到穿孔。

**PC/大屏：** 生产计划进度列 **隐藏断钢**（`yarn/yarn/src/utils/productionPlanProgress.js`）。

### 1.2 穿孔设备

- 建计划时绑定 **1～2 台** 穿孔设备，允许修改；下推同步到 `ya_product_task_punch_device`。
- 小程序穿孔：先选设备 → 再看该设备任务（`punchDevice` → `processTaskList?device_code=`）。

### 1.3 小程序工作台「生产报工」入口

按 **用户部门名称**（非角色/岗位）识别工序：

| 部门名含 | 入口 |
|----------|------|
| 断钢 | `duangangList` 合并池 |
| 穿孔 | `punchDevice` 选设备 |
| 球化/矫直/酸洗 | `processTaskList` 本工序任务 |
| 都不含 | 旧页 `pages/workOrder/workOrder` |

**数据链：** PC 用户管理 `/system/user` 勾选部门 → 登录写入 `dept_names` → 接口 `ProductTask/workContext`（或本地 `workContext.js` 降级）→ 工作台卡片。

**测试账号：**

- 管理员 / 超级管理员：无车间部门 → **通用工单**（预期行为）。
- 断钢：`wangjiao`、`jiangshaoyin`（部门「断钢工序」）。
- 穿孔：`suifujun`、`jiachengjun`（部门「穿孔工序」）。

改部门后须 **退出重新登录**。

---

## 2. 数据库

### 2.1 表（前缀 `ya_`）

| 表 | 用途 |
|----|------|
| `duangang_merge_group` | 合并池（含 `steel_grade`、`y_material_spec`、`y_material_model`、`c_material_spec`） |
| `duangang_merge_member` | 池内成员工单 |
| `duangang_merge_report` | 合并报工记录 |
| `production_punch_device` | 计划穿孔设备 |
| `product_task_punch_device` | 工单穿孔设备 |

### 2.2 SQL 脚本分工

| 文件 | 何时用 |
|------|--------|
| `gccd_server/scripts/add-duangang-merge-and-punch-device.sql` | **新环境**：一次建 5 表（已含 `y_material_spec`） |
| `gccd_server/scripts/patch-duangang-merge-y-material-spec.sql` | **老环境已建表**：只 `ALTER` 补 `y_material_spec` 列，**执行一次** |
| `gccd_server/scripts/run-duangang-migration.php` | 建表 + 合并池 `rebuildAll()`；注意去掉 SQL 行注释后再拆句执行 |

**改合并逻辑或修物料字段后：** 须再跑 `DuangangMergeService::rebuildAll()`。

---

## 3. 关键代码位置

### 3.1 后端

| 路径 | 职责 |
|------|------|
| `app/common/service/utils/DuangangMergeKeyHelper.php` | 合并键、钢号提取 |
| `app/common/service/admin/DuangangMergeService.php` | 重建/列表/报工；`loadActiveTaskRows` 须 `with(['getPlanName','getMaterial','getYMaterial'])` 才能带出成品规格 |
| `app/admin/controller/production/DuangangMerge.php` | 列表 `keyword` 综合检索 |
| `app/common/service/admin/MiniProgramWorkService.php` | `workContext` 按部门推断工序 |
| `app/common/service/admin/ProductionPunchDeviceService.php` | 计划穿孔设备 |
| `app/admin/controller/production/ProductTask.php` | `listForMini`、`workContext` |
| `app/common/service/system/UserService.php` | 登录返回 `dept_names` |
| `app/common/model/admin/ProductTaskModel.php` | `getYMaterial` / `getMaterial` / `getPlanName` 绑定 |

### 3.2 小程序

| 路径 | 职责 |
|------|------|
| `pages/workbench/workbench.js` | 工作台入口；先本地推断再调 `workContext` |
| `utils/workContext.js` | 本地工序推断 |
| `utils/util.js` | `silentFail` 静默失败 |
| `pageWorkOrder/pages/duangangList/*` | 合并池列表 + **顶部单框 keyword 检索** |
| `pageWorkOrder/pages/duangangAdd/*` | 合并报工提交 |
| `pageWorkOrder/pages/punchDevice/*` | 穿孔选设备 |
| `pageWorkOrder/pages/processTaskList/*` | 其他工序任务 |

### 3.3 PC

| 路径 | 职责 |
|------|------|
| `yarn/yarn/src/views/mes/ProductionOrderList/components/NewProductionPlan.vue` | 穿孔设备多选 |
| `yarn/yarn/src/views/mes/ProductionOrderList/components/EditingAndProduction.vue` | 同上 |
| `yarn/yarn/src/utils/productionPlanProgress.js` | 隐藏断钢列 |

---

## 4. 已踩坑与修复

| # | 现象 | 原因 | 修复 |
|---|------|------|------|
| 1 | `method does not exist: workContext` | 线上未部署新接口 | 部署后端；工作台加本地降级 + `silentFail` |
| 2 | 合并池只显示「50/65」 | 未关联计划导致 `c_material_spec` 空；列表未展示 `y_material_spec` | `getPlanName` + 表字段 `y_material_spec` + 列表三行展示 |
| 3 | 邯钢/潍坊被合成一条 | 旧逻辑无 `#` 时只按型号合并 | 改为 **规格+型号+成品规格** |
| 4 | 首表未创建 | `run-duangang-migration.php` 首条 SQL 因 `--` 注释被跳过 | 执行前 strip 行注释 |
| 5 | 列表无检索 | 新页未做筛选 | 后端 `keyword` 模糊匹配四字段；前端单检索框 |

**列表检索 `keyword` 匹配：** `steel_grade`、`y_material_spec`、`y_material_model`、`c_material_spec`。

---

## 5. 部署清单（增量）

### 后端（必传）

- `DuangangMergeService.php`、`DuangangMergeKeyHelper.php`、`DuangangMerge.php`
- `MiniProgramWorkService.php`、`ProductTask.php`、`ProductTaskService.php`
- `ProductReportService.php`、`ProductionService.php`、`ProductionPunchDeviceService.php`
- `RoutingProcessHelper.php`、`ProductionConfig.php`
- `UserService.php`（`dept_names`）
- `ProductTaskModel.php`
- SQL 脚本 / `run-duangang-migration.php`（按环境选用）

### 小程序

- `workbench.js`、`utils/workContext.js`、`utils/util.js`
- `pageWorkOrder/pages/duangangList/*`
- `pageWorkOrder/pages/duangangAdd/*`
- `pageWorkOrder/pages/punchDevice/*`
- `pageWorkOrder/pages/processTaskList/*`
- `app.json`（页面注册）
- `workOrderAdd.js`（穿孔锁定设备等）

### PC

- 生产计划穿孔设备组件 + `productionPlanProgress.js` + `EquipmentLedger.js`

---

## 6. 运维命令

```bash
# 老库补列（列已存在则跳过）
php -r '...' # 或 mysql 执行 patch-duangang-merge-y-material-spec.sql

# 重建合并池（改逻辑/修数据后）
cd gccd_server && php -r '
require "vendor/autoload.php";
$app = new think\App(__DIR__); $app->initialize();
\app\common\service\admin\DuangangMergeService::rebuildAll();
'
```

**2026-06-16 线上重建结果：** 约 60 个进行中合并组；示例标题 `邯钢 · 50 · 50*1.2`。

---

## 7. 环境与联调

| 项 | 说明 |
|----|------|
| 小程序 API | `https://gccd.xnssoft.com/`（`gccd_mini/utils/util.js`） |
| 本地 PC | `yarn/yarn` → `npm run dev` → 前端 9527，PHP 9526 |
| 本地 PHP 超时 | 连远程库易 30s 超时杀进程；测小程序可不启本地服务 |

---

## 8. 待验证 / 后续

- [ ] 断钢合并报工 → 穿孔任务 + 设备筛选 端到端
- [ ] 旧计划补穿孔设备（重新编辑保存或同步脚本）
- [ ] 大屏断钢块是否隐藏（`WxRegisterService` 若未改）
- [ ] 超管是否需「工序切换」测试入口（用户未要求则不做）
