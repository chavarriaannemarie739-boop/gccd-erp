# 车间电视 APK 打包 — 零基础版（Android Studio）

> 不用记命令。按下面顺序在 Android Studio 里点就行。  
> **工程已在本机同步过**，你可以直接从 **第三步** 开始。

---

## 第三步：打开项目（最重要）

1. 打开 **Android Studio**（Dock 或启动台里找大象图标 **Android Studio**）
2. 第一次打开可能出现向导：
   - 问是否导入设置 → 选 **Do not import settings / 不导入**
   - 问安装类型 → 选 **Standard / 标准**，一路 **Next → Finish**
   - 等底部进度条跑完（可能在下载组件，**保持联网 10～30 分钟**）
3. 回到主界面，点 **Open**（或 **File → Open / 文件 → 打开**）
4. 在弹窗里依次进入：

   ```
   文稿 → 高齿传动项目代码 → gccd_tv → android
   ```

   ⚠️ 必须选 **`android` 这一层文件夹**，不要选 `gccd_tv` 或整个项目根目录。

5. 若弹出 **Trust Project / 信任项目** → 点 **Trust Project**
6. 右下角会出现 **Gradle Sync / 正在同步 Gradle**：
   - **第一次可能要 5～20 分钟**，等它自己跑完
   - 同步成功：底部没有红色报错
   - 若提示缺少 SDK：看下面「同步失败怎么办」

---

## 第四步：补装 SDK（仅同步报错时做）

1. 顶部菜单 **Android Studio → Settings…**（Mac）或 **File → Settings**（Windows）
2. 左侧 **Languages & Frameworks → Android SDK**
3. 切到 **SDK Platforms** 标签
4. 勾选 **Android 14.0 (Upside Down Cake)**，API Level **34**
5. 切到 **SDK Tools** 标签，确认 **Android SDK Build-Tools** 已勾选
6. 点 **Apply → OK**，等下载完成
7. 再点工具栏小象图标 **Sync Project with Gradle Files / 同步项目**（或 **File → Sync Project with Gradle Files**）

---

## 第五步：生成 APK

1. 顶部菜单 **Build → Build Bundle(s) / APK(s) → Build APK(s)**  
   （中文版：**构建 → 生成 App Bundle / APK → 生成 APK**）
2. 等 1～5 分钟，右下角弹出 **Build completed successfully**
3. 点通知里的 **locate**（或 **analyze APK** 旁边的小链接 **locate**）
4. Finder 会打开文件夹，里面就是 **`app-release.apk`**

完整路径（方便手动找）：

```
文稿/高齿传动项目代码/gccd_tv/android/app/build/outputs/apk/release/app-release.apk
```

把这个文件拷到 U 盘即可。

---

## 第六步：装到电视

1. U 盘插入电视，复制 **app-release.apk**
2. 电视 **设置 → 安全** → 允许 **未知来源** 安装
3. 用电视自带的 **文件管理器** 找到 U 盘里的 APK，点安装
4. 安装完成后桌面会出现 **生产车间数据大屏**，点开应直接进入「生产总览」全屏

---

## 同步失败 / 报错常见处理

| 你看到的 | 怎么做 |
|--------|--------|
| SDK location not found | 用 **Open** 重新打开 `gccd_tv/android`，Studio 会自动写 `local.properties` |
| Failed to install Android SDK Platform 34 | 按 **第四步** 勾选 API 34 下载 |
| Gradle sync failed | **File → Invalidate Caches → Invalidate and Restart** |
| Build 失败一堆红字 | 截图发开发同事，或把 Build 窗口最下面几行错误复制出来 |
| 电视装完黑屏（像关机） | 请装 **1.0.2+**：已去掉 Capacitor 启动页，改为原生 WebView 打开 `/dv/`；浏览器能开而 App 黑屏时务必先卸载旧 APK |
| 电视装完白屏 | 先在电视浏览器打开 `https://gccd.xnssoft.com/dv/` 看网络是否正常 |

---

## 以后只改了大屏页面，要重新打包时

需要有人在本机终端执行一次（或交给你同事）：

```bash
cd gccd_tv
npm run prepare:www
npx cap sync android
```

然后在 Android Studio 里重复 **第五步** 即可。

---

## 验收

装好后按 [`TV-FIELD-TEST.md`](TV-FIELD-TEST.md) 勾选试机项。

## 架构说明（可选阅读）

- **云端服务器**：只放 PHP 接口 + `public/dv/` 网页，**不需要上传 `gccd_tv` 文件夹**
- **TV APK**：页面打包在安装包里，只向服务器要数据，和浏览器访问 `/dv/` 是同一套接口
