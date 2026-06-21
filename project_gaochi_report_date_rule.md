---
name: 12点分界报工归属规则
description: 生产报工和能耗报工的12点分界归属日期规则——上午11:59前提交归昨天产量，12:00后提交归今天产量
type: project
originSessionId: 17ef42da-8b6d-4a77-b8a4-9e3b6e1afb03
---
## 规则

**公式**：`report_date = date('Y-m-d', strtotime('-12 hours'))`

- 提交时间 < 12:00 → 归属昨天
- 提交时间 >= 12:00 → 归属今天
- 企业管理要求：过期（上午12点前没报）统计不上自己负责

## 适用范围

| 报工类型 | 表 | 适用规则 | 
|----------|-----|----------|
| 生产报工 | `ya_product_report` | 是 |
| 能耗报工 | `ya_product_power` | 是 |
| 机修/电工报工 | `ya_maintenance_report` | 否（不参与产量统计） |

## 涉及的核心方法

- `ProductReportService::calcReportDate()` — 12点分界计算
- `ProductReportService::add()` — 生产报工 PC 端新增
- `ProductReportService::report()` — 生产报工小程序提交流程
- `ProductReportService::reportpower()` — 能耗报工
- `ProductReportService::addWage()` — 工资详情日期跟归属日期走
- `DailySummaryService::calcProductionQty()` — 按 report_date 查产量
- `DailySummaryService::calcEnergy()` — 按 report_date 查能耗
- `DailySummaryService::calcDefectiveReportQty()` — 按 report_date 查不良品

## 字段说明

- `ya_product_report.report_date` — 生产报工归属日期
- `ya_product_power.report_date` — 能耗报工归属日期
- `ya_product_report.class` — 班次（0=白班, 1=夜班），前端传参或按时间自动判
- `ya_product_power.class` — 同上

## 删除重报处理

统计员在 PC 端编辑弹窗中可手动修改归属日期（`report_date` 和 `class` 两个字段均可编辑）。

**Why:** 工人在小程序报工滞后（如夜班下班忘报、上午10点统计员发现报错计划单删除后重报），系统自动计算的归属日期可能不对，需要统计员在PC端手动修正。管理规则兜底：过期不报自己负责。
