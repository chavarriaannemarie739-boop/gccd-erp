<template>
  <section class="app-main" :class="{ 'app-main--cockpit': isDashboardCockpit }">
    <router-view v-slot="{ Component, route }">
      <!-- [AI-2026-06] 去掉 out-in：懒加载 chunk 未就绪时 out-in 会先卸载旧页再等新区，导致整页空白 -->
      <transition name="fade-slide">
        <!-- [AI-2026-06] 只缓存叶子页（RoundSteelList 等），不缓存 ParentView，避免嵌套 router-view 重复堆表 -->
        <keep-alive :include="cachedViews" :exclude="parentViewExclude">
          <component :is="Component" v-if="Component" :key="route.fullPath" />
        </keep-alive>
      </transition>
    </router-view>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute } from 'vue-router'
import { useTagsViewStore } from '@/store/modules/tagsView'

const route = useRoute()
const tagsViewStore = useTagsViewStore()
const { cachedViews } = storeToRefs(tagsViewStore)

const parentViewExclude = ['ParentView']

const isDashboardCockpit = computed(
  () =>
    route.name === 'Dashboard' ||
    route.path === '/dashboard' ||
    route.path === '/dashboard/index' ||
    route.path.startsWith('/dashboard/')
)
</script>

<style lang="scss" scoped>
.app-main {
  position: relative;
  width: 100%;
  height: calc(100vh - 60px);
  overflow: auto;
  overflow-x: hidden;

  &.app-main--cockpit {
    padding: 0 !important;
    margin: 0;
    overflow: hidden;
    box-sizing: border-box;
  }
}

.isMix {
  .app-main {
    height: calc(100vh - 120px);
    overflow-y: auto;
  }
}
</style>
