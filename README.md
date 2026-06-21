# 高齿传动智能制造 ERP 系统

> 工厂级 ERP 系统，覆盖生产管理、工资核算、设备管理、库存盘点、质量检查等核心业务。
>
> **前端**：微信小程序 (Vant Weapp) + Vue 3 管理后台  
> **后端**：ThinkPHP 8 (PHP 8.0+)  
> **数据库**：MySQL 5.5+ (表前缀 `ya_`) · Redis 5.0+

---

## 项目结构

```
高齿传动项目代码/
├── gccd_server/     ─── 后端服务 (ThinkPHP 8)
│   ├── app/
│   │   ├── admin/      后台管理控制器（15个模块）
│   │   ├── api/        小程序 API 接口
│   │   └── common/     公共层（80个模型 / 79个服务 / 34个验证器）
│   ├── config/         配置文件（25个）
│   └── private/        数据库 SQL
├── gccd_mini/       ─── 微信小程序（Vant Weapp）
│   └── pages/          13个页面 + 3个公共组件
└── gccd_app/        ─── Vue 3 管理后台（Element Plus）
```

## 核心功能

| 模块 | 说明 |
|------|------|
| 生产管理 | 生产任务、产品报表、计数、工艺路线、日报周报 |
| 工资管理 | 计件工资、工序工资、巡检工资、工资预览导出 |
| 设备管理 | 设备档案、维修记录、保养计划、状态监控 |
| 库存管理 | 入库、出库、盘点、物料结算、库存预警 |
| 质量管理 | 质检记录、缺陷记录、检查项管理 |
| 系统管理 | 用户、角色权限、菜单、部门、岗位 |

## 项目配置文件

| 文件 | 用途 |
|------|------|
| `AGENTS.md` | **AI 助手统一工作规范**（所有 AI 工具共用） |
| `phpcs.xml` | PHP PSR-12 代码规范检查配置 |
| `.php-cs-fixer.php` | PHP 代码自动格式化配置 |
| `phpstan.neon` | PHP 静态分析配置 |
| `.eslintrc.json` | JavaScript 代码检查配置 |
| `.prettierrc` | JS/Vue 代码格式化配置 |
| `CONTRIBUTING.md` | 开发者快速上手指南 |

## 开发

详见 [`CONTRIBUTING.md`](CONTRIBUTING.md)。

## 技术栈来源

本项目基于 [yylAdmin](https://gitee.com/skyselang/yylAdmin) 开源框架构建。
