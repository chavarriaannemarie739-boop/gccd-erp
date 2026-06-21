# PRECHECK — 前置检查清单

> 开始任何 Codex 任务前，运行此检查。
> 失败先修环境，不进主任务。

## 网络

- [ ] GitHub / 目标仓库可访问（`ping github.com`）
- [ ] npm registry 可达（`npm ping`）
- [ ] 代理正常运行

## CLI 工具

- [ ] `node` / `npm` / `python3`
- [ ] `gh`（GitHub CLI）
- [ ] 项目构建工具

## 项目环境

- [ ] 当前 branch 正确
- [ ] 环境变量就位（`.env`）
- [ ] 依赖已安装（`npm install` / `pip install`）
- [ ] 可正常构建（`npm run build`）

## 认证

- [ ] GitHub auth 未过期（`gh auth status`）
- [ ] Graph API token 有效
- [ ] 其他第三方 token

## 列表检索（涉及筛选/搜索功能时必做）

- [ ] 生成/更新检索台账：`node scripts/audit-search-registry.mjs`
- [ ] 无摆设筛选框：`node scripts/audit-search-registry.mjs --check`
- [ ] 核心接口冒烟：`php gccd_server/scripts/search-smoke.php`
- [ ] 台账报告已阅：`docs/SEARCH_REGISTRY_REPORT.md`

## 其他

- [ ] workspace 下的 `AGENTS.md` 已读
- [ ] `.codex/memories/` 已同步最新
