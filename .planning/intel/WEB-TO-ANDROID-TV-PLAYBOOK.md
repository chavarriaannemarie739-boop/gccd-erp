# 网页大屏 → Android TV App 全流程经验（Playbook）

> **用途：** 制造业 ERP/MES 类项目，把已有 Vue 驾驶舱/大屏封装成车间电视 APK。  
> **首案：** 高齿传动 `gccd`（2026-06-04）。其他项目照此走，可少踩 80% 的坑。  
> **关联文档：** [BUILD-TV-APK.md](../../docs/BUILD-TV-APK.md) · [DEPLOY-DV.md](../../yarn/yarn/docs/DEPLOY-DV.md) · [TV-FIELD-TEST.md](../../docs/TV-FIELD-TEST.md)

---

## 一、从想法到交付：阶段地图

| 阶段 | 做什么 | 产出 | 本项目的结论 |
|------|--------|------|--------------|
| **1. 需求** | 确认是否要独立 URL、是否免登录、是否与后台某页数据一致 | 验收标准一句话 | `/dv/` 免登录，数据 = `#/dashboard` 生产总览 |
| **2. 现状排查** | 线上旧大屏源码是否在仓库；对应哪个 Vue 文件 | 复用 vs 重建 | 旧 `/dv/`（dev-v）不在仓库；复用 `CockpitDashboard.vue` |
| **3. 后端** | Kiosk 只读接口 + 免登白名单 + `.env` 密钥 | PHP + config | `dashboardBoardKiosk` + `[KIOSK] DASHBOARD_KEY` |
| **4. 前端** | 抽共用组件 + Vite 多入口 `build:dv` | `yarn/.../dv/` 静态包 | `entries/dv/` + `standalone` 模式 |
| **5. 上云** | `public/dv/` + Nginx | 浏览器可访问 | 宝塔上传，与 `admin` 同级 |
| **6. Android** | Capacitor 工程 + 打 APK | `app-debug.apk` | `gccd_tv/`，最终改为**原生 WebView 壳** |
| **7. 电视试机** | 黑屏 / 排版 / 缓存 / 性能 | 可 24h 跑 | 荣耀电视老 WebView 是最大变量 |
| **8. 交付客户** | APK + 安装说明 | 重命名 APK 更正式 | `高齿生产大屏-TV-v1.0.3.apk` |

**原则：** 网页先跑通再上 APK；APK 本质是「全屏浏览器 + 常亮 + 禁返回」，不是重写一套 UI。

---

## 二、架构：什么上云、什么不上云

```
┌─────────────────────────────────────────────────────────┐
│  云端服务器（gccd_server）                                  │
│  ├── PHP：dashboardBoard / dashboardBoardKiosk（数据）   │
│  ├── .env：[KIOSK] DASHBOARD_KEY                         │
│  └── public/dv/：index.html + js/css + dv-config.json    │
└─────────────────────────────────────────────────────────┘
         ▲ HTTPS                           ▲ HTTPS
         │                                 │
   浏览器 /dv/                      TV App（WebView）
                                         │
┌────────────────────────────────────────┴────────────────┐
│  本机 gccd_tv/（不上传服务器）                            │
│  └── android/ → 打 APK → U 盘装电视                      │
└─────────────────────────────────────────────────────────┘
```

| 路径 | 是否上云 | 说明 |
|------|----------|------|
| `gccd_server/app/.../WeChat.php` | ✅ | Kiosk 接口 |
| `gccd_server/config/admin.php` | ✅ | 免登 + key 配置 |
| `gccd_server/app/admin/common.php` | ✅ | MenuCache 未刷新时仍放行 Kiosk |
| `gccd_server/.env` `[KIOSK]` | ✅ | 必须用段格式，见下文 |
| `public/dv/*` | ✅ | 网页 + App 共用页面（App 在线加载时） |
| `gccd_tv/` 整个目录 | ❌ | 只在开发机 Android Studio 打 APK |

---

## 三、后端 Kiosk（必做，且易配错）

### 3.1 `.env` 正确写法（ThinkPHP 8）

```ini
[KIOSK]
DASHBOARD_KEY = 你的32位以上随机密钥
```

**错误写法（读不到）：** `kiosk.dashboard_key=xxx` 单行。

改完 **重启 PHP**；仍报「未配置」时清 `runtime/cache/`。

### 3.2 接口与 Header

- 登录版：`POST /admin/wechat.WeChat/dashboardBoard`（需 AdminToken）
- 大屏/TV：`POST /admin/wechat.WeChat/dashboardBoardKiosk` + Header `X-Kiosk-Key`

### 3.3 前端运行时配置

`public/dv/dv-config.json`：

```json
{
  "apiBaseUrl": "https://你的域名",
  "kioskKey": "与 .env DASHBOARD_KEY 一致"
}
```

密钥轮换：改 `.env` + `dv-config.json`；若 APK 写死 URL 带版本参数则不必重打 APK。

---

## 四、前端独立大屏 `/dv/`

### 4.1 推荐结构

1. 从后台首页抽出 **`CockpitDashboard.vue`**，加 prop `standalone`
2. 独立入口 `entries/dv/main.js` + `DvApp.vue`（只包一层 `<CockpitDashboard standalone />`）
3. Vite：`npm run build:dv` → 产物 `dv/`
4. `postbuild-dv.mjs` 把 `dv/entries/dv/index.html` 整理为 `dv/index.html`

### 4.2 构建注意（老电视必看）

| 项 | 现代浏览器 | 车间电视 WebView（如荣耀） |
|----|------------|---------------------------|
| ES Module | ✅ Vite 默认 | ❌ 需 `@vitejs/plugin-legacy`（vite5 配 `^5.4.x`） |
| CSS Grid | ✅ | ❌ 或「假支持」仍塌成一列 |
| `clamp()` / `min()` | ✅ | ❌ 部分失效 |
| `ResizeObserver` | ✅ | ❌ 缩放自适应失效 |

**排版修复（已验证）：** 对 `.dashboard-root--standalone` **强制 flex 布局**，不要只靠 `@supports not (display:grid)`——老内核会谎报支持。

**缩放修复：** 无 `ResizeObserver` 时用 `window.resize` + 延时多次 `scheduleFit()`（400ms / 1200ms / 3000ms）。

### 4.3 上传服务器

- 产物已是静态文件，**无需再打包**
- 宝塔：`public/dv/` 先删旧 `js/`、`css/`，再整包覆盖
- 强刷验收：`https://域名/dv/`

---

## 五、Android TV App 演进（本项目实际路径）

### 5.1 方案对比

| 方案 | 优点 | 缺点 | 本项目结果 |
|------|------|------|------------|
| Capacitor 打包本地 `www/` | 可离线 | 老 WebView 跑不动 Vite 产物 → **黑屏** | 放弃 |
| Capacitor `server.url` 在线 `/dv/` | 与浏览器一致 | Splash + Bridge 仍可能黑屏 | 部分有效 |
| **原生 WebView `loadUrl(线上/dv/)`** | 最简单、最接近浏览器 | 必须联网 | **最终采用 v1.0.3** |

### 5.2 黑屏排查顺序（经验证）

1. 电视浏览器能否打开 `/dv/`？  
   - 不能 → 网络/域名  
   - 能 → App/WebView 问题  
2. App 能否看到「正在加载…」？  
   - 看不到 → Activity/主题/Splash（`Theme.SplashScreen` 会永久黑底 `#04080f`）  
   - 看到但无内容 → JS 不兼容 → legacy 构建  
   - 有数据但一列排版 → Grid 不兼容 → standalone flex  
3. 改了服务器但 App 不变 → **WebView 缓存** → `LOAD_NO_CACHE` + 清应用数据 + URL 加 `?v=版本号`

### 5.3 MainActivity 要点（gccd_tv）

- 继承 `AppCompatActivity`，**不要** `BridgeActivity`（若仅做 WebView 壳）
- Activity 主题用 `AppTheme.NoActionBar`，**不要** `NoActionBarLaunch`（SplashScreen）
- `FLAG_KEEP_SCREEN_ON`、禁返回键、延迟再进沉浸式（避免部分 TV onCreate 全屏黑屏）
- `settings.setCacheMode(LOAD_NO_CACHE)` + `webView.clearCache(true)`

### 5.4 打 APK（给非工程师）

1. 安装 Android Studio（标准安装，等 SDK 下载完）
2. Open 文件夹：**`项目/gccd_tv/android`**（必须是 `android` 这一层）
3. 等 Gradle Sync 完成（首次 10～30 分钟）
4. **Build → Generate APK(s)**
5. 产物：`android/app/build/outputs/apk/debug/app-debug.apk`  
   - 发给客户可重命名为 `xxx-TV-v1.0.3.apk`
6. 电视：**先卸载旧版**再装；允许未知来源

**不需要 Java 命令行**；本机若无 JBR，用 Android Studio 自带 JDK。

---

## 六、性能：5 秒+ 加载

### 6.1 根因（本项目）

`WxRegisterService::dashboardBoard()` 每次请求：

- 调 `getDevice()`、能耗、质量聚合
- 最多 30 条计划 × `getPlanWithJoinStats`（**N+1**）
- 无缓存 → 每个浏览器/App/60s 轮询都重算

### 6.2 已做优化

- 聚合结果 **Cache 90 秒**（key: `cockpit_dashboard_board`）
- 第一次仍慢，之后同机/多电视/轮询 **秒开**
- 大屏 60s 轮询可接受最多 90s 数据延迟

### 6.3 后续可做（改动大）

- 计划进度改批量 SQL，消灭 N+1
- 接口拆成「首屏 KPI」+「慢块 lazy load」
- Redis 缓存（若已上 Redis）
- 前端图表延迟渲染 / 骨架屏（体感）

---

## 七、交付与运维清单

### 7.1 给客户

- [ ] APK 文件（说明：需联网、卸载旧版再装）
- [ ] 可选：浏览器收藏 `https://域名/dv/` 作备用
- [ ] [TV-FIELD-TEST.md](../../docs/TV-FIELD-TEST.md) 试机项

### 7.2 云端更新（仅大屏页面）

1. 本机 `npm run build:dv`
2. 上传 `dv/` → `public/dv/`（删旧 js/css）
3. TV App v1.0.3+：**重开 App 即可**（禁缓存）

### 7.3 云端更新（后端）

- `WxRegisterService.php` / `WeChat.php` / `admin.php` / `common.php`
- 改 `.env` 后重启 PHP

### 7.4 密钥泄露

轮换 `DASHBOARD_KEY` → 同步 `dv-config.json` → 重启 PHP；旧 App 仍带旧 Key 会 401，需更新配置或重打 APK（若 Key 写死在壳里）。

---

## 八、踩坑速查表（给其他项目抄）

| 现象 | 常见原因 | 处理 |
|------|----------|------|
| Kiosk 未配置 | `.env` 格式错 / 未重启 PHP | `[KIOSK]` 段 + 清 runtime/cache |
| 闪「请登录」 | 未进免登白名单 / MenuCache | `admin.php` + `common.php` path 兜底 |
| App 全黑无字 | Splash 主题 / Capacitor Bridge | 改 WebView 壳 + NoActionBar 主题 |
| 有「加载中」无内容 | ES Module | `plugin-legacy` + `build:dv` |
| 数据有、排版一列 | CSS Grid 不支持 | standalone 强制 flex |
| 浏览器正常 App 排版旧 | WebView 缓存 | NO_CACHE + 清应用数据 + `?v=` |
| 浏览器快 App 慢 | 同一 URL 不同内核 | 以 App WebView 为准做兼容 |
| 每次打开 5s+ | 后端无缓存 N+1 | Cache 60～90s + 后续 SQL 优化 |
| Gradle SDK 报错 | 缺 platform | Android Studio SDK Manager 装 API 34 |
| 以为 gccd_tv 要上云 | 误解架构 | 只上 `public/dv/` + PHP |

---

## 九、本项目文件速查（gccd）

| 用途 | 路径 |
|------|------|
| 驾驶舱组件 | `yarn/yarn/src/views/cockpit/CockpitDashboard.vue` |
| 独立入口 | `yarn/yarn/entries/dv/` |
| 构建大屏 | `yarn/yarn` → `npm run build:dv` |
| 上传目录 | `gccd_server/public/dv/` |
| Kiosk API | `gccd_server/app/admin/controller/wechat/WeChat.php` |
| 聚合+缓存 | `gccd_server/app/common/service/wechat/WxRegisterService.php` |
| TV 工程 | `gccd_tv/android/` |
| APK 输出 | `gccd_tv/android/app/build/outputs/apk/debug/app-debug.apk` |
| 打 APK 说明 | `docs/BUILD-TV-APK.md` |

---

## 十、下次新项目 Checklist（复制即用）

```
□ 确认要对齐的后台页面与数据源
□ 后端：Kiosk 只读接口 + [KIOSK] DASHBOARD_KEY + 免登白名单
□ 前端：standalone 组件 + build:dv + dv-config.json
□ 上云 public/dv/，浏览器无痕验收
□ build:dv 开启 legacy（只要目标含 Android TV）
□ standalone 布局用 flex 兜底，勿依赖 Grid/@supports
□ Android：优先 WebView loadUrl(线上/dv/)，禁缓存，无 Splash 主题
□ 后端 dashboard 聚合加 60～90s 缓存
□ 电视试机：浏览器 → App → 排版 → 24h
□ 交付 APK + 安装说明；文档留本 Playbook
```

---

## 修订记录

| 日期 | 说明 |
|------|------|
| 2026-06-04 | 首版：高齿 gccd 从需求到 TV v1.0.3、legacy 排版、缓存优化全过程 |
