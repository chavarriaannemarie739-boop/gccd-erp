# 独立大屏 `/dv/` 部署说明

## 1. 后端（必须先做）

在服务器 `gccd_server/.env` 末尾增加（参考 [`private/kiosk.env.example`](../../gccd_server/private/kiosk.env.example)）：

```ini
[KIOSK]
DASHBOARD_KEY = 你的32位以上随机密钥
```

> 不要写成 `kiosk.dashboard_key=xxx` 单行格式，ThinkPHP 读不到。

部署 PHP 变更：

- [`config/admin.php`](../../gccd_server/config/admin.php) — 免登录白名单 + Key 配置
- [`app/admin/controller/wechat/WeChat.php`](../../gccd_server/app/admin/controller/wechat/WeChat.php) — `dashboardBoardKiosk` 方法

重启 PHP / 清 OPcache。

## 2. 前端构建

```bash
cd yarn/yarn
# 编辑 public/dv-config.json：apiBaseUrl、kioskKey 与服务器 .env 一致
npm run build:dv
```

产物目录：`yarn/yarn/dv/`（含 `index.html`、`js/`、`css/`、`dv-config.json`）。

本地预览：

```bash
npm run dev:dv
# 浏览器打开 http://localhost:9528/entries/dv/index.html
```

## 3. Nginx 配置示例

将旧 `dev-v` 静态目录替换为新构建产物：

```nginx
location /dv/ {
    alias /path/to/yarn/yarn/dv/;
    index index.html;
    try_files $uri $uri/ /dv/index.html;
}

# 接口仍走 PHP（与现有 /admin 一致）
location /admin {
    # ... 现有配置 ...
}
```

访问：`https://gccd.xnssoft.com/dv/`

## 4. 验收对照

| 项 | 说明 |
|----|------|
| 页面 | 无登录、无侧边栏，标题「生产总览」 |
| 数据 | 与后台 `#/dashboard` 全屏后 KPI / 计划 / 质量 / 能耗 / 设备一致 |
| 配置 | 改 `dv/dv-config.json` 的 `kioskKey` 后刷新即可，无需重打包（若 Key 写在 json） |

## 5. 安全说明

- `kiosk.dashboard_key` 仅用于只读大屏接口 `dashboardBoardKiosk`
- 泄露后可轮换 Key，同步更新 `dv-config.json` 与 TV App 配置
- 建议车间网段 Nginx IP 白名单加固（可选）
