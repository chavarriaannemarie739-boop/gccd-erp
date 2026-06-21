# 生产车间数据大屏 — Android TV（Capacitor）

## 前置

1. 完成 [`yarn/yarn/docs/DEPLOY-DV.md`](../yarn/yarn/docs/DEPLOY-DV.md) 后端 Kiosk 配置
2. 编辑 `yarn/yarn/public/dv-config.json` 中的 `kioskKey`
3. 安装 [Android Studio](https://developer.android.com/studio) 与 JDK 17

## 构建 APK

```bash
cd gccd_tv
npm install
npm run android:build
```

APK 输出：`android/app/build/outputs/apk/release/app-release.apk`（debug 签名，可直接 sideload）

详细图文步骤见 [`docs/BUILD-TV-APK.md`](../docs/BUILD-TV-APK.md)。

## 开发调试

```bash
npm run prepare:www
npx cap sync android
npx cap open android
```

在 Android Studio 选择 TV 设备或模拟器 Run。

## 特性

- 横屏、沉浸式全屏（隐藏状态栏/导航栏）
- 屏幕常亮
- 禁用返回键退出（Kiosk 体验）
- 打包与 Web `/dv/` 同一套 Vue 驾驶舱

## 配置

运行时 API 与 Key：打包前写入 `yarn/yarn/public/dv-config.json`，`sync:web` 会复制到 `www/dv-config.json`。
