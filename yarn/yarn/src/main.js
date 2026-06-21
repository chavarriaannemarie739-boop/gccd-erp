import { createApp } from 'vue'
import App from './App.vue'
import router from '@/router'
import { setupStore } from '@/store'
import 'default-passive-events'

import '@/permission'

import 'virtual:svg-icons-register'

import i18n from '@/lang/index'

import 'element-plus/theme-chalk/dark/css-vars.css'
import '@/styles/index.scss'
import 'uno.css'

import Particles from '@tsparticles/vue3'
import { loadSlim } from '@tsparticles/slim'
import installPlugins from '@/plugins/myPlugins.js'
import directive from '@/directive/index.js' // directive
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
const app = createApp(App)
setupStore(app)
/**
 * 自定义插件
 */
app.use(installPlugins)
app.use(router)
app.use(i18n)

app.use(Particles, {
  init: async (engine) => {
    await loadSlim(engine)
  }
})
/**
 * 自定义指令
 */
directive(app)
app.mount('#app')
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}
