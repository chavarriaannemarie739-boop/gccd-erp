# UI/UX Pro Max - Meta-Skill 配置完成！✅

## 配置内容

我已经为你配置了 UI/UX Pro Max 的 Meta-Skill，现在 AI 可以：

### 1️⃣ 生成 UI/UX 设计文档
- 完整的色彩规范（CSS 变量）
- 详细的布局结构（ASCII 图展示）
- 组件详细设计（尺寸、内容、配色）
- 交互说明和动画效果

### 2️⃣ 生成 Figma Make Prompt
- 英文结构化 Prompt
- 包含所有设计规范
- AI 可直接生成高保真原型

### 3️⃣ 提供前端代码建议
- 技术栈推荐
- 目录结构
- 组件划分
- ECharts 配置

---

## 配置文件位置

```
~/.claude/packages/ui-ux-pro-max.md          # Claude 配置文件
~/.claude/packages/ui-ux-pro-max-meta.md     # Meta-Skill 配置
/Users/lizengrui/Documents/高齿传动项目代码/.cursorrules  # Cursor 配置文件
```

---

## 如何使用

### 在 OpenClaw 中（当前会话）

直接说：

```
请帮我设计一个数据大屏，展示生产统计、设备状态、能耗监控
风格：深色科技风
分辨率：1920×1080
```

AI 会自动调用 UI/UX Pro Max 技能，输出：
1. 完整的设计文档
2. Figma Make Prompt
3. 前端代码建议

### 在 Cursor 中

打开项目后，AI 会自动读取 `.cursorrules` 文件，你可以说：

```
帮我设计一个生产监控数据大屏
```

Cursor 会按照 UI/UX Pro Max 的规范生成设计文档。

### 在 Figma 中

复制 AI 生成的 Figma Make Prompt，粘贴到 Figma AI（Make 功能），AI 会直接生成高保真原型！

---

## 使用示例

### 示例 1：数据大屏设计

**你说：**
```
请帮我设计一个生产监控数据大屏，展示：
- 今日产量统计（目标 vs 实际）
- 设备运行状态（在线/故障）
- 实时能耗监控
- 库存预警
风格：深色科技风
分辨率：1920×1080
```

**AI 输出：**
1. ✅ 完整的设计文档（色彩、布局、组件）
2. ✅ Figma Make Prompt（英文）
3. ✅ 前端代码建议（Vue 3 + ECharts）

### 示例 2：落地页设计

**你说：**
```
帮我设计一个 SaaS 产品的落地页
行业：AI 聊天机器人
包含：Hero 区域、功能展示、用户评价、定价、CTA
```

**AI 输出：**
1. ✅ 行业分析（选择最佳模式）
2. ✅ 设计文档（Hero-Centric + Social Proof）
3. ✅ Figma Prompt
4. ✅ 前端实现建议（React + Tailwind）

### 示例 3：小程序设计

**你说：**
```
帮我设计一个 ERP 微信小程序
页面：首页、设备管理、库存管理、报表中心
导航：底部 Tab 栏（5 个标签）
```

**AI 输出：**
1. ✅ 页面清单
2. ✅ 交互流程
3. ✅ 设计规范
4. ✅ Figma Prompt（移动端优化）
5. ✅ 小程序代码结构

---

## 设计原则

### 色彩规范
```css
--bg-primary: #0a1628;
--bg-secondary: #1a2a40;
--color-primary: #00f0ff;
--color-success: #00d084;
--color-warning: #ff6b35;
--color-error: #ff4757;
```

### 布局原则
- Grid-based layout (3 columns standard)
- Card-based components
- Responsive design
- Real-time update support

### 字体规范
- Headings: 18-24px, bold
- Numbers: 28-32px, bold, monospace
- Body: 14-16px
- Small: 12px

### 动画效果
- Transitions: 200-300ms
- Hover scale: 1.02x
- Pulse: 1s infinite
- Scroll: smooth

---

## 质量检查清单

AI 会在输出前检查：

- [ ] No emojis as icons (use SVG: Heroicons/Lucide)
- [ ] cursor-pointer on all clickable elements
- [ ] Hover states with smooth transitions (150-300ms)
- [ ] Light mode: text contrast 4.5:1 minimum
- [ ] Focus states visible for keyboard nav
- [ ] prefers-reduced-motion respected
- [ ] Responsive: 375px, 768px, 1024px, 1440px
- [ ] Accessibility: WCAG AA compliant

---

## 技术栈推荐

### 前端框架
- Vue 3 + Element Plus
- React + Ant Design
- Next.js + Tailwind CSS
- Flutter (mobile)
- WeChat Mini Program (小程序)

### 图表库
- ECharts 5.x ⭐ (推荐)
- AntV G2
- Chart.js
- D3.js

### 状态管理
- Pinia (Vue)
- Redux/Zustand (React)

### 实时通信
- Socket.io-client
- WebSocket API

---

## 下一步

现在你可以：

1. **继续设计数据大屏** - 我帮你生成更详细的方案
2. **设计其他页面** - 比如设备管理、库存管理等
3. **生成 Figma Prompt** - 直接粘贴到 Figma AI 生成原型
4. **编写测试用例** - 覆盖所有功能

**想做什么？告诉我！** 🚀

---

## 参考资源

- 官方文档：https://uupm.cc
- GitHub: https://github.com/nextlevelbuilder/ui-ux-pro-max-skill
- 技能包：~/Downloads/ui-ux-pro-max-skill-main/

---

## 版本信息

- **技能版本**: 2.0
- **UI 样式**: 67 种
- **配色方案**: 161 种
- **字体搭配**: 57 种
- **行业规则**: 161 条

---

配置完成！现在 AI 已经具备专业的 UI/UX 设计能力了！🎉
