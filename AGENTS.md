# 高齿传动 ERP 项目 — AI 助手统一工作守则

> 本文档是本项目的**唯一 AI 工作规范**。任何 AI 助手（Claude Code / Cursor / Codex / QClaw / Antigravity 等）在编辑本项目代码前，必须阅读并遵守本文档。
>
> CLAUDE.md、.cursorrules 等旧配置已于 2026-05 合并至此文件，请勿再单独编辑它们。

---

## 一、项目身份

| 属性 | 值 |
|------|-----|
| 项目名称 | 高齿传动智能制造 ERP 系统 |
| 技术栈 | ThinkPHP 8 + Vue 3 (后台) + 微信小程序 |
| 数据库 | MySQL 5.5+ (表前缀 `ya_`) |
| 缓存 | Redis 5.0+ |
| 目录结构 | `gccd_server/` 后端 · `gccd_mini/` 小程序 · `gccd_app/` 管理后台 |

---

## 二、代码规范

### 2.1 PHP（适配 PSR-12）

- **缩进**：使用 **4 个空格**（非 Tab）
- **命名法**：一律驼峰（`camelCase`），禁止下划线命名（`snake_case`）
- **类名**：`StudlyCaps`（如 `ProductionService`）
- **方法名**：`camelCase`（如 `getReportList()`）
- **属性/变量**：`$camelCase`（如 `$userId`）
- **注释**：
  - 方法必须写 PHPDoc（`@param` `@return` `@throws`）
  - 复杂业务逻辑必须写行内注释
  - 简单 getter/setter 可以省略
- **异常**：使用 try-catch + 日志，禁止静默吞掉异常
- **SQL**：优先使用 ThinkPHP 查询构造器，禁止裸写 SQL 拼接
- **文件编码**：UTF-8 无 BOM

### 2.2 微信小程序 JS（ES6+）

- **变量声明**：使用 `const`（默认）和 `let`（需要修改时），禁止 `var`
- **函数**：使用箭头函数 `=>`，禁止 `function` 关键字（Page 生命周期方法除外）
- **分号**：**必须加分号**，统一风格
- **模板字符串**：使用反引号 `` ` `` 替代字符串拼接 `+`
- **解构赋值**：对象/数组取值优先使用解构
- **组件化**：公共逻辑抽取到 `components/`，禁止在页面内写 500+ 行

### 2.3 Vue 3 后台

- **API 风格**：使用 Composition API（`<script setup>`），禁止 Options API
- **组件命名**：多单词 PascalCase（如 `UserList.vue`）
- **样式**：使用 `<style scoped>`，禁止全局样式污染
- **状态管理**：Pinia 代替 Vuex（如已使用）

---

## 三、开发工作流

### 3.1 改前检查（强制）

在编辑任何**函数、类、方法**之前，必须：

1. 使用 `gitnexus_impact` 或 `git log --oneline` 确认改动范围
2. 识别直接调用方和被调用方（blast radius）
3. 高风险改动需告知用户确认

### 3.2 改后验证

修改完成后至少执行一项验证：

- 运行 `php -l` 检查 PHP 语法
- 小程序代码运行开发者工具预览
- 后台页面刷新验证功能

### 3.3 Git 提交规范

使用 Conventional Commits 格式：

```
<type>(<scope>): <简短描述>

<可选的详细说明>
```

**type**：`feat` / `fix` / `refactor` / `style` / `docs` / `chore` / `perf`
**scope**：模块名（如 `stocktake` / `wage` / `equipment`）

示例：
```
feat(stocktake): 新增盘点推算÷0.97规则
fix(wage): 修复计件工资小数位溢出
style(php): 统一缩进为4空格
```

### 3.4 多 AI 协作约定

1. **不改他人的模块**：一个 AI 任务只修改指定的模块，不碰其他模块的代码
2. **标记修改范围**：在每个修改文件中用注释标记本次改动边界（如 `// [AI-2026-05] 修复库存计算`）
3. **不删除他人代码**：除非确认逻辑冗余，否则优先增加而非删除
4. **互知原则**：多 AI 同时并行时，每个 AI 被告知"有其他 AI 也在修改本项目"

---

## 四、工具链参考

| 工具 | 用途 | 配置文件 |
|------|------|----------|
| PHP CodeSniffer | PSR-12 规范检查 | `phpcs.xml`（项目根目录） |
| PHP CS Fixer | PHP 自动格式化 | `.php-cs-fixer.php`（项目根目录） |
| PHPStan | PHP 静态分析 | `phpstan.neon`（项目根目录） |
| ESLint | JS 代码检查 | `.eslintrc.js`（各子项目） |
| Prettier | JS/Vue 格式化 | `.prettierrc`（项目根目录） |
| GitNexus | 代码索引/影响分析 | `.gitnexus/` |

> 所有工具配置文件已在项目根目录下就绪，首次使用前运行对应工具的安装命令即可。

---

## 五、列表检索规范（强制）

新增或修改列表页筛选时，必须同时改齐 **4 处** 并在 `docs/SEARCH_REGISTRY.json` 可审计：

1. Vue/WXML `v-model` 字段
2. `api/*.js` 请求体字段
3. `Service::$search_field` 声明
4. `controller::list()` 处理分支（物料类优先用 `MaterialSearchHelper`）

**字段命名：**

| 含义 | 标准参数名 | 说明 |
|------|-----------|------|
| 圆钢规格/型号 | `y_material_spec` / `y_material_model` | 禁止新代码使用 `y_mateial_*` |
| 成品规格/型号（跨表） | `c_material_spec` / `c_material_model` | 报工、盘点等 |
| 生产计划成品 | `material_spec` / `material_model` | 仅生产计划列表 |

**物料检索 JOIN 路径（展示与检索必须一致）：**

- 成品：`production.english_material → material`
- 圆钢（计划明细）：`production_detail.material_id → material`
- 圆钢（工单）：`product_task.material_id → material`

发布前执行：

```bash
node scripts/audit-search-registry.mjs --check
php gccd_server/scripts/search-smoke.php
```

---

## 六、不得违反（红线）

- ❌ 禁止在未跑影响分析的情况下编辑已存在的函数
- ❌ 禁止提交未绑定 `v-model` 的筛选输入框（摆设检索）
- ❌ 禁止使用 `var`（JS）或下划线命名法（PHP）
- ❌ 禁止裸写 SQL 拼接（PHP）
- ❌ 禁止静默吞异常（`catch {}` 无日志无处理）
- ❌ 禁止修改 `.env` 中的数据库密码/密钥
- ❌ 禁止在 `AGENTS.md` 之外创建新的 AI 配置文件

---

## 七、用户沟通与交付规范（极其重要）

1. ❌ **禁止输出代码细节**：不要在回复中包含修改了哪几行代码、修改前后的对比或详细的代码解释。
2. 📢 **只交代改好了和需要部署的文件**：在每次修改完毕后，仅需简明直奔主题告诉用户：**「改好了，您线上需要更新以下文件：」** 并用清晰的项目结构列表给出来。
3. 💬 **沟通直来直去，简洁高效**：免去无意义的客套或冗长陈述，保持最高的沟通效率。
