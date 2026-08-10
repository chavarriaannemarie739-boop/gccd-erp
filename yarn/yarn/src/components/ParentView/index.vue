<template>
  <router-view v-slot="{ Component, route }">
    <!-- [AI-2026-06] 物料档案等多级菜单：叶子页在此层缓存并强制 key 替换，防止重复挂载 -->
    <keep-alive :include="cachedViews">
      <component :is="Component" v-if="Component" :key="route.fullPath" />
    </keep-alive>
  </router-view>
</template>

<script setup>
import { storeToRefs } from 'pinia'
import { useTagsViewStore } from '@/store/modules/tagsView'

defineOptions({
  name: 'ParentView'
})

const tagsViewStore = useTagsViewStore()
const { cachedViews } = storeToRefs(tagsViewStore)
</script>
