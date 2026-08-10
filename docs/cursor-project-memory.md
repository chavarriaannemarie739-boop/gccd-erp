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

## 四、小程序与PC端蓝牙标签打印优化 (30x30mm 规格)

### 1. 小程序蓝牙打印优化细节
- **高效多份打印 (PRINT N)**: 小程序增加了打印份数选择器。为了避免蓝牙循环多次发送大数据包导致丢包或延迟，利用客户端 `tsplBuilder.js` 对后端传回的 base64 编译指令（GBK）直接检索并将末尾的 `PRINT 1` 动态修改为 `PRINT N`，实现指令单次发送、打印机本地复印。
- **防止顶部裁切**: 将首行 Y 轴坐标从 `8` 下移至 `20`，整体布局排布至 `Y = 20, 40, 60`，QR 码移至 `Y = 85`，解决了得力打印机物理页距偏离导致首行文字顶部切边的问题。
- **精细化中文字体与自动居中**: 改用 `TSS16.BF2` (16px) 字体，文字截断上限扩展至 20 字符。计算中英文混排宽度，动态生成 `TEXT` 居中 `X` 坐标，达到与 PC 端一致的精美居中排版。
- **字符集编码 (CODEPAGE 936)**: 显式声明 `CODEPAGE 936` (GBK)，防止打印机在非中文默认代码页下将汉字解析为乱码或空格。

### 2. 部署与调试保障
- **清理服务器缓存**: 在 `gccd_server/public/clear_opcache.php` 中实现了轻量级 OPcache 清理接口（通过访问 `https://gccd.xnssoft.com/clear_opcache.php` 触发），避免服务器 PHP OPcache 缓存储存旧代码影响调试。
