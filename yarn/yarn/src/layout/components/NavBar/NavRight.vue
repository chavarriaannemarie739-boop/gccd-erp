<template>
  <!-- 单根包裹 + 禁止收缩：mix/top 顶栏 flex 中双根会被压成 0 宽导致整块消失 -->
  <div class="nav-right">
  <div v-if="device !== 'mobile'" class="setting-container">
    <div
      v-if="stockAlertStore.totalCount > 0"
      class="setting-item stock-alert-entry"
      @click="goStockAlert"
    >
      <el-badge :value="stockAlertStore.totalCount" :max="99">
        <el-tooltip effect="dark" content="库存低于阈值，点击查看">
          <div class="stock-alert-text"><svg-icon icon-class="message" /> 库存预警</div>
        </el-tooltip>
      </el-badge>
    </div>
    <!-- 用户名称 -->
    <!--<div class="setting-item">-->
    <!--  <el-tooltip effect="dark" :content="user.username">-->
    <!--    <span>{{ user.nickname }}</span>-->
    <!--  </el-tooltip>-->
    <!--</div>-->
    <!-- 系统设置：已按需求隐藏顶栏入口；仍可通过侧边栏「系统管理」等菜单进入 /setting -->
    <!-- 清除缓存 -->
    <div
      class="setting-item"
      v-if="checkPermission(['admin/system.Setting/cacheClear'])"
      @click="clearCache"
    >
      <el-tooltip effect="dark" :content="$t('navbar.Clear Cache')">
        <div><svg-icon icon-class="delete" /> 清除缓存</div>
      </el-tooltip>
    </div>
    <!-- 全屏切换 -->
    <!--<div class="setting-item" @click="toggle">-->
    <!--  <el-tooltip effect="dark" :content="$t('navbar.full screen')">-->
    <!--    <svg-icon icon-class="full-screen" />-->
    <!--  </el-tooltip>-->
    <!--</div>-->
    <!-- 语言切换 -->
    <!--<div class="setting-lang">-->
    <!--  <el-tooltip effect="dark" :content="$t('navbar.Language switching')">-->
    <!--    <lang-select />-->
    <!--  </el-tooltip>-->
    <!--</div>-->
    <!-- 主题切换 -->
    <!--<div class="setting-item">-->
    <!--  <el-tooltip effect="dark" :content="$t('navbar.Theme switching')">-->
    <!--    <theme-select class="cursor-pointer" />-->
    <!--  </el-tooltip>-->
    <!--</div>-->
  </div>
  <el-dropdown trigger="click">
    <!-- 用户头像 -->
    <div class="avatar-container">
      <el-tag size="default" type="info" effect="plain">
        {{ user.nickname }}{{ user.is_super == 1 ? '(超管)' : '' }}</el-tag
      >
      <!--<el-avatar v-if="user.avatar_url" :size="40" :src="user.avatar_url" />-->
      <!--<el-avatar v-else :size="40">-->
      <!--  <svg-icon icon-class="user-filled" size="25px" style="margin-right: 0" />-->
      <!--</el-avatar>-->
      <svg-icon icon-class="caret-bottom" style="margin-right: 0" />
    </div>
    <template #dropdown>
      <el-dropdown-menu>
        <el-dropdown-item>
          <router-link to="/">{{ $t('navbar.dashboard') }}</router-link>
        </el-dropdown-item>
        <el-dropdown-item>
          <router-link
            v-if="checkPermission(['admin/system.UserCenter/info'])"
            to="/system/user-center"
          >
            {{ $t('navbar.userCenter') }}
          </router-link>
        </el-dropdown-item>
        <el-dropdown-item divided @click="logout">
          {{ $t('navbar.logout') }}
        </el-dropdown-item>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
  </div>
</template>

<script setup>
import checkPermission from '@/utils/permission'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '@/store/modules/app'
import { useUserStore } from '@/store/modules/user'
import { useTagsViewStore } from '@/store/modules/tagsView'
import { cacheClear } from '@/api/system/setting'
import { useI18n } from 'vue-i18n'
import { useStockAlertStore } from '@/store/modules/stockAlert'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const tagsViewStore = useTagsViewStore()
const { device } = storeToRefs(appStore)
const { user } = storeToRefs(userStore)
const { t } = useI18n()
const stockAlertStore = useStockAlertStore()

onMounted(() => {
  stockAlertStore.fetchSummary()
})

function goStockAlert() {
  router.push({ name: 'MaterialInventory' })
}

// 清除缓存
function clearCache() {
  ElMessage('正在清除缓存')
  cacheClear()
    .then((res) => {
      ElMessage.success(res.msg)
    })
    .catch(() => {})
}

// 退出系统
function logout() {
  ElMessageBox.confirm(t('common.Are you sure you want to exit the system?'), t('common.tip'), {
    type: 'warning'
  })
    .then(() => {
      userStore
        .logout()
        .then(() => {
          tagsViewStore.delAllViews()
        })
        .then(() => {
          router.push(`/login?redirect=${route.fullPath}`)
        })
    })
    .catch(() => {})
}
</script>

<style lang="scss" scoped>
.nav-right {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.setting-container {
  display: flex;
  align-items: center;
  .setting-item {
    cursor: pointer;
    margin-top: 4px;
    margin-right: 14px;
  }
}
.setting-lang {
  cursor: pointer;
  margin-top: 12px;
  margin-right: 14px;
}
.stock-alert-entry {
  .stock-alert-text {
    color: #f56c6c;
    font-weight: 600;
  }
}
.avatar-container {
  display: flex;
  place-items: center center;
  margin: 0 6px 0 0;
  cursor: pointer;
  img {
    width: 32px;
    height: 32px;
    border-radius: 6px;
  }
}
</style>
