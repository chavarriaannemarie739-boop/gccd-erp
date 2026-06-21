<template>
  <div class="app-container">
    <el-form label-width="120px">
      <el-form-item label="logo">
        <el-switch v-model="settingsStore.sidebarLogo" />
      </el-form-item>
      <el-form-item label="名称">
        <el-switch v-model="settingsStore.sidebarName" />
      </el-form-item>
      <!--<el-form-item label="标签栏">-->
      <!--  <el-switch v-model="settingsStore.tagsView" />-->
      <!--</el-form-item>-->
      <el-form-item label="固定头部">
        <el-switch v-model="settingsStore.fixedHeader" />
      </el-form-item>
      <el-form-item label="组件大小">
        <el-select v-model="appStore.size" style="width: 12%" @change="sizeChange">
          <el-option
            v-for="item in sizes"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="主题颜色">
        <ul class="w-full space-x-2 flex py-2">
          <li
            v-for="(color, index) in themeColors"
            :key="index"
            class="inline-block w-[30px] h-[30px] cursor-pointer theme-wrap"
            :style="{ background: color }"
            @click="changeThemeColor(color)"
          >
            <svg-icon v-show="color === currentThemeColor" icon-class="check" />
          </li>
        </ul>
      </el-form-item>
    </el-form>
  </div>
</template>

<script setup>
import screenHeight from '@/utils/screen-height'
import { useAppStore } from '@/store/modules/app'
import { useSettingsStore } from '@/store/modules/settings'

const height = ref(680)
const appStore = useAppStore()
const settingsStore = useSettingsStore()
const sizes = [
  { label: '大', value: 'large' },
  { label: '默认', value: 'default' },
  { label: '小', value: 'small' }
]

// 组件大小
function sizeChange(size) {
  appStore.changeSize(size)
}

// 主题颜色
const themeColors = ref(['#409EFF', '#304156', '#11a983', '#13c2c2', '#6959CD', '#f5222d'])
const currentThemeColor = computed(() => {
  return settingsStore.themeColor
})
function changeThemeColor(color) {
  settingsStore.changeSetting({ key: 'themeColor', value: color })
}

onMounted(() => {
  height.value = screenHeight(160)
})
</script>

<style lang="scss" scoped>
.theme-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
}
</style>
