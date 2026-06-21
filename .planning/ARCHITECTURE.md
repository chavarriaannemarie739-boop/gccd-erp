# 高齿传动项目 - 技术架构

## 架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                         用户层                                    │
│  ┌──────────────┐          ┌──────────────┐                     │
│  │  微信小程序   │          │  Web 管理后台  │                     │
│  │  (gccd_mini) │          │  (Vue 3)     │                     │
│  └──────────────┘          └──────────────┘                     │
└─────────────────────────────────────────────────────────────────┘
                              ↕ HTTP/HTTPS
┌─────────────────────────────────────────────────────────────────┐
│                         应用层                                    │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              ThinkPHP 8 后端服务 (gccd_server)             │  │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  │  │
│  │  │ 控制器层  │  │ 服务层   │  │ 模型层   │  │ 中间件   │  │  │
│  │  │ (Controller)│ (Service) │ (Model)  │ (Middleware)│  │  │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────┘  │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              ↕
┌─────────────────────────────────────────────────────────────────┐
│                         数据层                                    │
│  ┌──────────────┐          ┌──────────────┐                     │
│  │   MySQL 数据库  │          │   Redis 缓存   │                     │
│  │   (gxgccd)   │          │   (缓存/会话) │                     │
│  └──────────────┘          └──────────────┘                     │
└─────────────────────────────────────────────────────────────────┘
```

## 技术栈详情

### 后端技术栈

| 技术 | 版本 | 说明 |
|------|------|------|
| **PHP** | 8.0+ | 编程语言 |
| **ThinkPHP** | 8.x | PHP 框架 |
| **MySQL** | 5.5+ | 关系数据库 |
| **Redis** | 5.0+ | 缓存数据库 |
| **Nginx** | - | Web 服务器 |
| **Composer** | - | 依赖管理 |

### 前端技术栈

| 技术 | 版本 | 说明 |
|------|------|------|
| **Vue** | 3.x | 前端框架 |
| **Element Plus** | - | UI 组件库 |
| **Axios** | - | HTTP 客户端 |
| **Pinia** | - | 状态管理 |

### 小程序技术栈

| 技术 | 版本 | 说明 |
|------|------|------|
| **微信小程序** | - | 小程序框架 |
| **Vant Weapp** | - | UI 组件库 |
| **Node.js** | 18+ | 构建工具 |

## 模块划分

### 1. 后台管理模块 (admin/)

```
admin/
├── check/              # 质量检查模块
│   └── Check.php
├── content/            # 内容管理模块
│   ├── Category.php
│   ├── Content.php
│   ├── Setting.php
│   └── Tag.php
├── file/               # 文件管理模块
│   ├── Export.php
│   ├── File.php
│   ├── Group.php
│   ├── Import.php
│   ├── Setting.php
│   └── Tag.php
├── material/           # 物料管理模块
│   ├── Settlement.php
│   └── Stock.php
├── member/             # 会员管理模块
│   ├── Api.php
│   ├── Group.php
│   ├── Log.php
│   ├── Member.php
│   ├── Setting.php
│   ├── SupervisorAttendance.php  # 巡检考勤
│   ├── SupervisorWage.php        # 巡检工资
│   ├── Tag.php
│   └── Third.php
├── message/            # 消息通知模块
│   └── Message.php
├── processes/          # 工艺管理模块
│   └── Processes.php
├── production/         # 生产管理模块
│   ├── DailyReport.php         # 日报
│   ├── Inventory.php           # 库存
│   ├── Material.php            # 物料
│   ├── ProductCount.php        # 产品计数
│   ├── Production.php          # 生产
│   ├── ProductReport.php       # 产品报表
│   ├── ProductTask.php         # 产品任务
│   ├── QualityCheckRecord.php  # 质量检查记录
│   ├── Routing.php             # 工艺路线
│   └── Ship.php                # 发货
├── receiving/          # 收货管理模块
│   └── Receiving.php
├── repair/             # 设备维修模块
│   └── Device.php
├── setting/            # 系统设置模块
│   ├── Accord.php
│   ├── Carousel.php
│   ├── Feedback.php
│   ├── Link.php
│   ├── Notice.php
│   ├── Region.php
│   └── Setting.php
├── stock/              # 库存管理模块
│   ├── InStock.php         # 入库
│   └── OutStock.php        # 出库
├── system/             # 系统管理模块
│   ├── Apidoc.php
│   ├── Dept.php
│   ├── Index.php
│   ├── Login.php
│   ├── Menu.php
│   ├── Notice.php
│   ├── Post.php
│   ├── Role.php
│   ├── Setting.php
│   ├── User.php
│   ├── UserCenter.php
│   └── UserLog.php
├── wage/               # 工资管理模块
│   ├── ProcessWage.php     # 工序工资
│   ├── UserWage.php        # 用户工资
│   └── Wage.php
└── wechat/             # 微信管理模块
    ├── WeChat.php
    └── WeChatUser.php
```

### 2. 数据模型 (common/model/admin/)

```
model/admin/
├── CheckModel.php              # 质量检查
├── DailySummaryModel.php       # 日报汇总
├── DeviceModel.php             # 设备管理
├── InStockModel.php            # 入库
├── MaintenanceReportModel.php  # 保养报告
├── MaterialInventoryModel.php  # 物料库存
├── MaterialModel.php           # 物料
├── MesCodeModel.php            # MES 编码
├── MessageModel.php            # 消息
├── OutStockModel.php           # 出库
├── PowerModel.php              # 能耗
├── ProcessesCheckModel.php     # 工艺检查
├── ProcessesModel.php          # 工艺
├── ProcessWageModel.php        # 工序工资
├── ProductCountModel.php       # 产品计数
├── ProductionDetailModel.php   # 生产明细
├── ProductionModel.php         # 生产
├── ProductPowerModel.php       # 产品能耗
├── ProductReportModel.php      # 产品报表
├── ProductTaskModel.php        # 产品任务
├── QualityCheckDefectModel.php # 质量缺陷
├── QualityCheckItemModel.php   # 质量检查项
├── QualityCheckRecordModel.php # 质量检查记录
├── ReceivingModel.php          # 收货
├── RepairDetailModel.php       # 维修明细
├── RepairModel.php             # 维修
├── ReportCoefficientModel.php  # 报表系数
├── RoutingDetailModel.php      # 工艺路线明细
├── RoutingModel.php            # 工艺路线
├── ShipModel.php               # 发货
├── StockMaterialModel.php      # 库存物料
├── StockModel.php              # 库存
├── SupervisorAttendanceModel.php # 巡检考勤
├── UserWageDetailModel.php     # 用户工资明细
├── UserWageModel.php           # 用户工资
└── WageModel.php               # 工资
```

### 3. 业务服务 (common/service/admin/)

```
service/admin/
├── CheckService.php              # 质量检查服务
├── DailyReportService.php        # 日报服务
├── DailySummaryService.php       # 日报汇总服务
├── DatabaseInitService.php       # 数据库初始化服务
├── DeviceService.php             # 设备服务
├── InStockService.php            # 入库服务
├── MaterialInventoryService.php  # 物料库存服务
├── MaterialService.php           # 物料服务
├── MessageService.php            # 消息服务
├── OutStockService.php           # 出库服务
├── ProcessesService.php          # 工艺服务
├── ProcessWageService.php        # 工序工资服务
├── ProductCountService.php       # 产品计数服务
├── ProductionService.php         # 生产服务
├── ProductReportService.php      # 产品报表服务
├── ProductTaskService.php        # 产品任务服务
├── PublicService.php             # 公共服务
├── QualityCheckRecordService.php # 质量检查记录服务
├── ReceivingService.php          # 收货服务
├── RoutingService.php            # 工艺路线服务
├── SettlementService.php         # 结算服务
├── ShipService.php               # 发货服务
├── StockService.php              # 库存服务
├── SupervisorAttendanceService.php # 巡检考勤服务
├── SupervisorWageService.php     # 巡检工资服务
├── TraceabilityService.php       # 追溯服务
├── UserWageService.php           # 用户工资服务
└── WageService.php               # 工资服务
```

## 数据流图

```
用户操作
   ↓
┌─────────────────────────────────────┐
│  前端 (Vue 3 / 小程序)                │
│  - 发起 HTTP 请求                      │
│  - 携带 Token 认证                     │
└─────────────────────────────────────┘
   ↓
┌─────────────────────────────────────┐
│  ThinkPHP 8 中间件层                  │
│  - 身份验证                          │
│  - 权限检查                          │
│  - 请求过滤                          │
└─────────────────────────────────────┘
   ↓
┌─────────────────────────────────────┐
│  控制器层 (Controller)                │
│  - 参数验证                          │
│  - 调用服务层                        │
└─────────────────────────────────────┘
   ↓
┌─────────────────────────────────────┐
│  服务层 (Service)                    │
│  - 业务逻辑处理                      │
│  - 数据验证                          │
│  - 事务管理                          │
└─────────────────────────────────────┘
   ↓
┌─────────────────────────────────────┐
│  模型层 (Model)                      │
│  - 数据库操作                        │
│  - 数据查询                          │
│  - 数据缓存                          │
└─────────────────────────────────────┘
   ↓
┌─────────────────────────────────────┐
│  数据库 (MySQL) / 缓存 (Redis)        │
└─────────────────────────────────────┘
   ↑
┌─────────────────────────────────────┐
│  JSON 响应                            │
│  - 状态码                            │
│  - 数据                              │
│  - 错误信息                          │
└─────────────────────────────────────┘
```

## 目录权限

```bash
# 必须可写的目录
runtime/          # 运行时缓存
public/uploads/   # 文件上传
```

## 配置文件

### 主配置文件 (config/app.php)
```php
return [
    // 应用配置
];
```

### 数据库配置 (config/database.php)
```php
return [
    // 数据库连接配置
];
```

### 路由配置 (config/route.php)
```php
return [
    // 路由规则
];
```

## 安全设计

### 1. 认证机制
- Token 认证 (JWT)
- 登录状态保持
- Token 自动刷新

### 2. 权限控制
- 角色权限管理
- 菜单权限控制
- API 权限控制

### 3. 数据安全
- SQL 注入防护
- XSS 防护
- CSRF 防护
- 数据加密存储

## 性能优化

### 1. 数据库优化
- 索引优化
- 查询优化
- 读写分离

### 2. 缓存优化
- Redis 缓存
- 查询结果缓存
- 会话缓存

### 3. 代码优化
- 自动加载优化
- 配置缓存
- 路由缓存

---

**最后更新**: 2026-04-28
**维护者**: 李永兴
