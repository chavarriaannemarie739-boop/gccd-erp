# 仓库权限细化 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 按菜单勾选控制圆钢/配件/成品的档案、库存、出入库可见与数据范围；同人可多勾（如圆钢入库+成品发货）；无权限 Tab 不显示；超管看全部。

**Architecture:** 新增 `WarehouseScopeService` 从用户 `menu_url`（权限勾选）∪ 旧角色名映射推导 `warehouse_scopes`；登录信息下发；库存/出入库/档案接口强制过滤；前端三页按 scopes 显隐 Tab。菜单侧增加按钮级权限码供角色勾选。

**Tech Stack:** ThinkPHP 8、Vue 3（现有 Options/Composition 混用页按原风格改）、MySQL `ya_system_menu` / `ya_system_role_menus`

**Spec:** `docs/superpowers/specs/2026-08-06-warehouse-permission-design.md`

---

## File map

| 文件 | 职责 |
|------|------|
| Create: `gccd_server/app/common/service/admin/WarehouseScopeService.php` | 推导允许仓库类型、权限码常量、过滤辅助 |
| Modify: `gccd_server/app/common/service/admin/InStockService.php` | 限仓改为调用 WarehouseScopeService |
| Modify: `gccd_server/app/admin/controller/stock/InStock.php` | list / mateiallist 强制 scopes |
| Modify: `gccd_server/app/admin/controller/stock/OutStock.php` | list 强制 scopes |
| Modify: `gccd_server/app/admin/controller/production/Material.php` | list 按档案 scope 限制 material_property |
| Modify: `gccd_server/app/common/service/system/UserService.php` | info 增加 `warehouse_scopes` |
| Create: `gccd_server/database/warehouse_scope_menus.sql` | 按钮菜单 + 角色默认勾选补丁 |
| Modify: `yarn/yarn/src/store/modules/user.js` | 存 warehouse_scopes |
| Modify: 入库/出库/库存 三个 Vue | Tab 按 scopes 显隐并默认首个有权 Tab |
| Create: `yarn/yarn/src/utils/warehouseScope.js` | 前端判断工具 |

### 权限码（menu_url）

```
warehouse.archive.roundSteel | warehouse.archive.accessory | warehouse.archive.product
warehouse.inventory.roundSteel | warehouse.inventory.accessory | warehouse.inventory.product
warehouse.inbound.roundSteel | warehouse.inbound.accessory | warehouse.inbound.product
warehouse.outbound.roundSteel | warehouse.outbound.accessory | warehouse.outbound.product
```

成品发货沿用现有 `admin/production.Ship/list`，不自动打开成品库存 Tab。

兼容：角色名含 `配件库/圆钢库/成品库` 等仍并入对应仓；超管 `warehouse_scopes=[]` 表示不限。

---

### Task 1: WarehouseScopeService

**Files:**
- Create: `gccd_server/app/common/service/admin/WarehouseScopeService.php`

- [ ] **Step 1:** 实现权限码常量、`getAllowedWarehouseTypes(): array`（空=不限）、`allows(string $type): bool`、`filterTabKeyword(?string): ?string`、档案 `allowedMaterialProperties()`  
- [ ] **Step 2:** `php -l` 语法检查  
- [ ] **Step 3:** Commit（若用户要求再提交）

### Task 2: 后端接入过滤 + 下发 scopes

**Files:**
- Modify: `InStockService.php`（getUserWarehouseAllowedValues 委托新服务）
- Modify: `InStock.php` list / mateiallist
- Modify: `OutStock.php` list
- Modify: `Material.php` list
- Modify: `UserService.php` info 增加 warehouse_scopes

- [ ] **Step 1:** 接入并保证超管行为不变  
- [ ] **Step 2:** `php -l` 相关文件  

### Task 3: 菜单 SQL 补丁

**Files:**
- Create: `gccd_server/database/warehouse_scope_menus.sql`

- [ ] **Step 1:** 在库存/入库/出库/档案父菜单下插入 button 菜单（menu_type=2）  
- [ ] **Step 2:** 给现有角色「配件库」「圆钢库」「成品库/圆钢、成品」按设计默认勾选  
- [ ] **Step 3:** 在现网执行 SQL（需确认）  

### Task 4: 前端 Tab 显隐

**Files:**
- Create: `yarn/yarn/src/utils/warehouseScope.js`
- Modify: `user.js` store
- Modify: `InboundList.vue` / `OutboundList.vue` / `MaterialInventory.vue`

- [ ] **Step 1:** 工具函数 `canWarehouse(type)` / `visibleWarehouseTabs(page)`  
- [ ] **Step 2:** 三页 `v-if` Tab + 默认 activeName  
- [ ] **Step 3:** 本地/现网用账号验收  

### Task 5: 验收

- [ ] 超管：三仓 Tab 全在  
- [ ] 仅勾圆钢库存+入库：只见圆钢 Tab；档案仅圆钢菜单  
- [ ] 圆钢入库+成品发货同人：有入库圆钢 Tab + 发货菜单，无成品库存 Tab（未勾）  
- [ ] 接口带别仓参数无法查出数据  

---

## 执行备注

- 改现有函数前用 git log / 调用方确认影响（AGENTS 3.1）。  
- 多 AI 协作：本任务范围限仓库权限，不改无关模块。  
- 提交仅在用户明确要求时执行。
