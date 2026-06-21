# 高齿传动项目 - 项目概览

## 基本信息

| 项目 | 信息 |
|------|------|
| **项目名称** | 高齿传动智能制造 ERP 系统 |
| **项目类型** | 工业 ERP + 微信小程序 |
| **技术栈** | ThinkPHP 8 + Vue 3 + 微信小程序 |
| **数据库** | MySQL 5.5+ (表前缀：ya_) |
| **缓存** | Redis 5.0+ |
| **部署环境** | phpStudy, Nginx/Apache |

## 项目结构

```
高齿传动项目代码/
├── gccd_server/          # 后端服务 (ThinkPHP8)
│   ├── app/
│   │   ├── admin/        # 后台管理控制器 (15 个模块)
│   │   ├── api/          # 小程序 API 接口
│   │   ├── common/       # 公共组件
│   │   │   ├── model/    # 数据模型 (37 个 admin 模型)
│   │   │   ├── service/  # 业务逻辑层 (30+ 个 service)
│   │   │   └── validate/ # 验证器
│   │   └── ...
│   ├── config/           # 配置文件 (25 个)
│   ├── private/
│   │   └── database/     # 数据库 SQL 文件
│   ├── public/           # 入口文件
│   └── .env              # 环境变量
│
├── gccd_mini/            # 微信小程序 (Vant UI)
│   ├── pages/            # 页面 (13 个)
│   │   ├── workbench/    # 工作台
│   │   ├── equipment/    # 设备管理
│   │   ├── energy/       # 能耗监控
│   │   ├── material/     # 物料管理
│   │   ├── workOrder/    # 工单管理
│   │   ├── stocktake/    # 库存盘点
│   │   └── mine/         # 个人中心
│   └── utils/            # 工具函数
│
└── .cursorrules          # UI/UX Pro Max 配置
```

## 核心功能模块

### 1. 生产管理 (production/)
- 生产任务管理
- 产品报表
- 产品计数
- 工艺路线
- 生产进度跟踪
- 日报/周报管理

### 2. 工资管理 (wage/)
- 计件工资计算
- 工序工资管理
- 巡检工资管理
- 工资预览和导出

### 3. 设备管理 (repair/)
- 设备档案
- 设备维修记录
- 设备保养计划
- 设备状态监控

### 4. 库存管理 (stock/, material/)
- 入库管理
- 出库管理
- 库存盘点
- 物料结算
- 库存预警

### 5. 质量管理 (check/)
- 质量检查记录
- 质量缺陷记录
- 质量检查项管理

### 6. 系统管理 (system/)
- 用户管理
- 角色权限
- 菜单管理
- 部门管理
- 岗位管理

## 数据模型

### 核心表 (37 个 admin 模型)

| 模型 | 表名 | 说明 |
|------|------|------|
| ProductionModel | ya_production | 生产主表 |
| ProductReportModel | ya_product_report | 产品报表 |
| ProcessWageModel | ya_process_wage | 工序工资 |
| UserWageModel | ya_user_wage | 用户工资 |
| DeviceModel | ya_repair | 设备管理 |
| MaterialModel | ya_material | 物料管理 |
| StockModel | ya_stock | 库存管理 |
| SupervisorAttendanceModel | ya_supervisor_attendance | 巡检考勤 |
| QualityCheckRecordModel | ya_quality_check_record | 质量检查记录 |
| InStockModel | ya_in_stock | 入库单 |
| OutStockModel | ya_out_stock | 出库单 |
| RoutingModel | ya_routing | 工艺路线 |
| ProductTaskModel | ya_product_task | 产品任务 |
| ... | ... | ... |

## 环境配置

### 本地开发环境
```ini
[database]
driver = mysql
hostname = 127.0.0.1
hostport = 3306
database = gxgccd
username = yyladmin
password = yyladmin
charset = utf8mb4
prefix = ya_
```

### 线上环境
- **主机**: 42.192.67.226:13306
- **数据库**: gxgccd
- **用户**: gxgccd
- **域名**: https://gccd.xnssoft.com
- **后台地址**: http://localhost:9527
- **API 地址**: http://localhost:9526

## 用户账号

| 角色 | 账号 | 密码 | 权限 |
|------|------|------|------|
| 超管 | skyselang | 123456 | 全部权限 |
| 管理员 | yyladmin | 123456 | 部分权限 |
| 普通用户 | liyongxing | 123456 | 受限权限 |

## 开发规范

### 代码规范
- PHP 8.0+ 语法
- PSR-12 代码规范
- ThinkPHP 8 最佳实践
- Vue 3 Composition API

### 命名规范
- 表名：`ya_` 前缀 + 下划线命名
- 模型：`*Model.php`
- 控制器：`*Controller.php`
- 服务：`*Service.php`
- 验证器：`*Validate.php`

### API 规范
- RESTful 风格
- JSON 响应格式
- Token 认证
- 统一错误码

## 部署说明

### 后端部署
1. 克隆项目
2. 安装依赖：`composer install`
3. 导入数据库：`private/database/yyladmin.sql`
4. 配置 `.env` 文件
5. 设置伪静态
6. 启动服务：`php -S 127.0.0.1:9526 router.php`

### 前端部署
1. 克隆项目
2. 安装依赖：`npm install`
3. 配置 `.env.production`
4. 打包：`npm run build:prod`
5. 启动服务：`npm run dev`

### 小程序部署
1. 微信开发者工具打开项目
2. 构建 npm
3. 预览/上传

## 已知问题

1. **数据库连接**: 需要确保 MySQL 服务运行
2. **Redis 连接**: 需要确保 Redis 服务运行
3. **文件权限**: 确保 `runtime/` 目录可写
4. **伪静态**: Nginx/Apache 伪静态配置必须正确

## 相关资源

- [yylAdmin 官方文档](https://www.yyladmin.top/)
- [yylAdmin Gitee](https://gitee.com/skyselang/yylAdmin)
- [ThinkPHP 8 文档](https://www.thinkphp.cn/doc8/)
- [Vue 3 文档](https://cn.vuejs.org/)
- [微信小程序文档](https://developers.weixin.qq.com/miniprogram/dev/framework/)

## 更新日志

### 2026-04-28
- 项目初始化
- 创建记忆系统
- 整理项目结构

---

**最后更新**: 2026-04-28
**维护者**: 李永兴
