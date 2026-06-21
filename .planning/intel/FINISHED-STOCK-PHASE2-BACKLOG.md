# 成品库特殊库存 — 第二期备忘（勿忘）

> 用户确认：第一期暂不纳入，后续迭代需跟进。

## 待办项

1. **超发审批流**  
   当前 >±2% 直接拦截，主管线下处理后再发。二期应做：超限超发走系统内审批/复核后再放行。

2. **计划单与客户字段解耦**  
   长期优化：`production_plan_name` 不再兼作客户名，独立 `customer_id` / 客户档案字段。

3. **自动对账报表**  
   酸洗报工入库 − 发货 ± 调整 与 `ya_wms_material` 差异预警、导出对账。

## 第一期已交付（2026-06）

- 部分转库存（归属「库存」）
- 主管清零
- 发货 ±2% 容差 + 超发 adjust 留痕
- 成品库按归属拆行展示

## 相关文件

- `gccd_server/scripts/add-finished-stock-adjust.sql`
- `gccd_server/app/common/service/admin/FinishedStockService.php`
