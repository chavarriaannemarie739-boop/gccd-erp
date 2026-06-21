# 项目记忆文件 (Project Memory)

## 一、项目及仓库基本信息
- **项目名称**：高齿传动智能制造 ERP 系统
- **GitHub 仓库地址**：[chavarriaannemarie739-boop/gccd-erp](https://github.com/chavarriaannemarie739-boop/gccd-erp)
- **仓库属性**：私有仓库 (Private)
- **当前主分支**：`main`

## 二、子模块 Git 管理说明
为保证项目完整备份且不遗漏任何源码，我们对项目中的子模块 Git 仓库进行了如下处理：
1. **涉及目录**：
   - `gccd_mini/` (小程序)
   - `gccd_server/` (后端)
   - `yarn/yarn/` (Vue3 管理后台)
2. **处理方案**：
   - 将原独立的 `.git` 文件夹重命名备份为 `.git.bak`。
   - 使得所有子模块源码能够作为整体被外层根目录的 Git 仓库追踪并成功提交推送至 GitHub。
   - **如后续需要重新与原自建 Git 服务器（IP: 42.192.67.226）同步，只需将 `.git.bak` 恢复为 `.git` 即可。**

## 三、常用 Git 操作命令说明
- **提交本地修改**：
  ```bash
  git add .
  git commit -m "chore(scope): 描述信息"
  git push origin main
  ```
