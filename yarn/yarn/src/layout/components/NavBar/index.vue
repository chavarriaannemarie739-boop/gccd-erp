<template>
  <!-- 顶部导航栏 -->
  <div class="navbar">
    <!-- 左侧面包屑 -->
    <div class="flex">
      <hamburger :is-active="appStore.sidebar.opened" @toggle-click="toggleSideBar" />
      <breadcrumb />
    </div>
    <!-- 右侧导航设置 -->
    <div class="flex">
      <NavRight />
    </div>
  </div>
</template>

<script setup>
import { useAppStore } from '@/store/modules/app'
import NavRight from '@/layout/components/NavBar/NavRight.vue'

const appStore = useAppStore()

/**
 * 左侧菜单栏显示/隐藏
 */
function toggleSideBar() {
  appStore.toggleSidebar()
}
</script>

<style lang="scss" scoped>
.navbar {
  display: flex;
  //height: 50px;
  height: 60px;
  align-items: center;
  background-color: #fff;
  box-shadow: 0 0 1px #0003;
  justify-content: space-between;
}
</style>
