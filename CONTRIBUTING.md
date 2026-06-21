# 高齿传动 ERP 项目 — 开发者指南

## 快速开始

### 环境要求

| 工具 | 版本 |
|------|------|
| PHP | 8.0+ |
| MySQL | 5.5+ |
| Redis | 5.0+ |
| Node.js | 18+ |
| Composer | 最新 |

### 后端启动

```bash
cd gccd_server
composer install
cp .env.example .env    # 编辑数据库配置
php think run           # 开发服务器
```

### 小程序构建

```bash
cd gccd_mini
npm install
# 用微信开发者工具打开 gccd_mini 目录
```

### 后台管理

```bash
cd gccd_app
npm install
npm run dev
```

## 代码规范

所有规范见 `AGENTS.md`。简要概括：

| 语言 | 规范 | 工具 |
|------|------|------|
| PHP | PSR-12 + 驼峰命名 | `phpcs`, `php-cs-fixer` |
| JavaScript | ES6+ (const/let, 箭头函数, 分号) | `eslint`, `prettier` |
| Vue 3 | Composition API (`<script setup>`) | `eslint` |

## 提交流程

```bash
# 1. 运行检查
phpcs gccd_server/app
eslint gccd_mini/pages

# 2. 自动修复格式
phpcbf gccd_server/app
npx prettier --write "gccd_mini/pages/**/*.js"

# 3. 提交
git add .
git commit -m "feat(module): 简短说明"
```

## 项目结构

```
高齿传动项目代码/
├── gccd_server/          # 后端 (ThinkPHP 8)
│   ├── app/admin/        # 后台管理 (15模块)
│   ├── app/api/          # 小程序API
│   ├── app/common/       # 公共层(Model/Service/Validate)
│   └── config/           # 配置
├── gccd_mini/            # 微信小程序 (Vant UI)
│   └── pages/            # 13个页面
├── gccd_app/             # 后台Vue3 (Element Plus)
├── AGENTS.md             # [唯一] AI助手工作规范
├── phpcs.xml             # PHP代码检查
├── .php-cs-fixer.php     # PHP格式化
├── phpstan.neon          # PHP静态分析
├── .eslintrc.json        # JS检查
└── .prettierrc           # 格式化配置
```
