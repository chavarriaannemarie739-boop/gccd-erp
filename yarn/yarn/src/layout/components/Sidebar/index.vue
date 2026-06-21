<template>
  <div
    v-if="layout !== 'mix'"
    :class="{
      'has-logo': sidebarLogoName,
      'top-menu-wrap': layout === 'top'
    }"
    class="menu-wrap"
  >
    <!--顶部模式 top-->
    <logo v-if="sidebarLogoName" :collapse="!appStore.sidebar.opened" />
    <el-scrollbar v-if="showContent">
      <LeftMenu :menu-list="permissionStore.routes" base-path="" />
    </el-scrollbar>
    <NavRight v-if="layout === 'top'" />
  </div>

  <div v-else :class="{ 'has-logo': sidebarLogoName }" class="menu-wrap">
    <!--<template >-->
    <!--混合/左侧-->
    <div class="header">
      <logo v-if="sidebarLogoName" :collapse="!appStore.sidebar.opened" />
      <div class="toggle-sidebar">
        <hamburger :is-active="appStore.sidebar.opened" @toggle-click="toggleSideBar" />
      </div>
      <TopMenu />
      <NavRight />
    </div>
    <!--</template>-->
  </div>
</template>

<script setup>
import Logo from './Logo.vue'
import TopMenu from './TopMenu.vue'
import LeftMenu from './LeftMenu.vue'
import NavRight from '@/layout/components/NavBar/NavRight.vue'
import { storeToRefs } from 'pinia'
import { useAppStore } from '@/store/modules/app'
import { useSettingsStore } from '@/store/modules/settings'
import { usePermissionStore } from '@/store/modules/permission'
const appStore = useAppStore()
const settingsStore = useSettingsStore()
const permissionStore = usePermissionStore()
const { sidebarLogo, sidebarName } = storeToRefs(settingsStore)
const sidebarLogoName = computed(() => {
  if (sidebarLogo.value || sidebarName.value) {
    return true
  }
  return false
})
function toggleSideBar() {
  appStore.toggleSidebar()
}
const layout = computed(() => settingsStore.layout)
const showContent = ref(true)
watch(
  () => layout.value,
  () => {
    showContent.value = false
    nextTick(() => {
      showContent.value = true
    })
  }
)
</script>

<style lang="scss" scoped>
.sidebar-container {
  :deep(.el-menu-item.is-active) {
    background: $menuHover !important;
    color: $menuActiveText !important;
    border-bottom: 0;
  }
  :deep(.el-menu-item),
  :deep(.el-sub-menu__title) {
    &:hover,
    &:focus {
      color: $menuActiveText;
      background: $menuHover !important;
    }
    font-size: 13px;
    //font-weight: bold;
  }
}
.top-menu-wrap.sidebar-container {
  background-color: $menuTopBg;
  :deep(.el-sub-menu-item):not(.is-disabled) {
    &:hover,
    &:focus {
      //color: $menuActiveText !important;
      background-color: $menuHover !important;
    }
  }
  :deep(.el-menu-item),
  :deep(.el-sub-menu__title) {
    &:hover,
    &:focus {
      color: $menuActiveText;
    }
    color: $menuTopText;
    font-size: 18px;
    //font-weight: bold;
  }
  :deep(.el-menu) {
    background-color: $menuTopBg;
  }
}
.toggle-sidebar {
  height: 60px;
  line-height: 60px;
  display: flex;
}
:deep(.setting-container) {
  .setting-item {
    //color: #fff;

    .svg-icon {
      margin-right: 0;
    }

    &:hover {
      color: var(--el-color-primary);
    }
  }
}

.isMix {
  .menu-wrap {
    box-shadow: 1px 4px 4px #00000040 !important;
    z-index: 99;
    width: 100% !important;
    //height: 50px;
    height: 60px;
    background-color: $menuTopBg;

    /**
    顶部菜单样式 0
     */
    .header {
      :deep(.el-menu-item):not(.is-disabled) {
        font-size: 18px;
        //font-weight: bold;
        &:hover,
        &:focus {
          color: $menuActiveText !important;
          background-color: $menuHover !important;
        }
      }
    }
    /**
   顶部菜单样式 1
    */
    :deep(.header) {
      display: flex;
      width: 100%;
      --el-menu-item-height: 60px;
      //--el-menu-item-height: 50px;

      .logo-wrap {
        width: $sideBarWidth;
      }

      .el-menu {
        //background-color: $menuBg;
        background-color: $menuTopBg;

        .el-menu-item {
          //color: $menuText;
          color: $menuTopText;
          border-bottom: 0;
        }
      }

      .el-scrollbar {
        flex: 1;
        min-width: 0;
        //height: 50px;
        height: 60px;
      }
    }
  }

  .left-menu {
    display: inline-block;
    width: $sideBarWidth;
    //background-color: $menuBg;
    background-color: $menuTopBg;

    :deep(.el-menu) {
      //background-color: $menuBg;
      background-color: $menuTopBg;

      .el-menu-item {
        //color: $menuText;
        color: $menuTopText;
      }
    }
  }
}
</style>
