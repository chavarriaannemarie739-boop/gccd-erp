<template>
  <el-config-provider :locale="appStore.locale" :size="appStore.size">
    <!-- 开启水印 -->
    <el-watermark
      v-if="watermarkEnabled"
      :font="{ color: watermarkfontColor }"
      :content="watermarkContent"
      class="w-full h-full"
    >
      <router-view />
    </el-watermark>
    <!-- 关闭水印 -->
    <router-view v-else />
  </el-config-provider>
</template>

<script setup>
import { useAppStore } from '@/store/modules/app'
import { useSettingsStore } from '@/store/modules/settings'
import { Boot } from '@wangeditor/editor'
import attachmentModule from '@wangeditor/plugin-upload-attachment'

const appStore = useAppStore()
const settingsStore = useSettingsStore()

// wangEditor：注册。要在创建编辑器之前注册，且只能注册一次，不可重复注册。
Boot.registerModule(attachmentModule)
window.addEventListener('beforeunload', function (event) {
  sessionStorage.setItem('pageStatus', 'refreshed') // 存储刷新状态
  console.log('页面被刷新---')
})
window.addEventListener('unload', function () {
  // 在这里添加清理操作，比如清除定时器
  console.log('页面已刷新或关闭')
  if (sessionStorage.getItem('pageStatus') === 'refreshed') {
    // 页面被刷新时执行的代码
    console.log('页面被刷新')
    // 清除状态，避免重复触发
    sessionStorage.clear()
  }
})

onMounted(() => {
  // 全站固定左侧菜单布局（与 settings.js 默认一致，避免本地缓存仍为 mix/top）
  settingsStore.changeSetting({ key: 'layout', value: 'left' })
  settingsStore.changeSetting({ key: 'theme', value: settingsStore.theme })
  settingsStore.changeSetting({ key: 'themeColor', value: settingsStore.themeColor })
  settingsStore.changeSetting({ key: 'faviconUrl', value: settingsStore.faviconUrl })
})

const watermarkEnabled = computed(() => settingsStore.watermarkEnabled)
const watermarkContent = computed(() => settingsStore.watermarkContent)
// 明亮/暗黑主题水印字体颜色适配
const watermarkfontColor = computed(() => {
  return settingsStore.theme === 'dark' ? 'rgba(255, 255, 255, .15)' : 'rgba(0, 0, 0, .15)'
})
</script>
