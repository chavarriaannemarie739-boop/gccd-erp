<template>
  <el-scrollbar
    ref="scrollbarInst"
    class="scroll-container"
    :vertical="false"
    @wheel.prevent="handleScroll"
  >
    <slot></slot>
  </el-scrollbar>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, unref } from 'vue'
import { useTagsViewStore } from '@/store/modules/tagsView'

const tagAndTagSpacing = ref(4)

const emits = defineEmits(['scroll'])
const emitScroll = () => {
  emits('scroll')
}

const tagsViewStore = useTagsViewStore()

/** Element Plus ElScrollbar 通过 expose 暴露 wrapRef，不在 $refs 上 */
const scrollbarInst = ref(null)

function wrapEl() {
  return unref(scrollbarInst.value?.wrapRef)
}

onMounted(() => {
  const el = wrapEl()
  el?.addEventListener('scroll', emitScroll, true)
})
onBeforeUnmount(() => {
  wrapEl()?.removeEventListener('scroll', emitScroll)
})

function handleScroll(e) {
  const w = wrapEl()
  if (!w) return
  const eventDelta = e.wheelDelta || -e.deltaY * 40
  w.scrollLeft = w.scrollLeft + eventDelta / 4
}

function moveToTarget(currentTag) {
  const $container = scrollbarInst.value?.$el
  const $scrollWrapper = wrapEl()
  if (!$container || !$scrollWrapper) return

  const $containerWidth = $container.offsetWidth

  let firstTag = null
  let lastTag = null

  // 查找第一个标签和最后一个标签
  if (tagsViewStore.visitedViews.length > 0) {
    firstTag = tagsViewStore.visitedViews[0]
    lastTag = tagsViewStore.visitedViews[tagsViewStore.visitedViews.length - 1]
  }

  if (firstTag === currentTag) {
    $scrollWrapper.scrollLeft = 0
  } else if (lastTag === currentTag) {
    $scrollWrapper.scrollLeft = $scrollWrapper.scrollWidth - $containerWidth
  } else {
    const tagListDom = document.getElementsByClassName('tags-item')
    const currentIndex = tagsViewStore.visitedViews.findIndex((item) => item === currentTag)
    let prevTag = null
    let nextTag = null
    for (const k in tagListDom) {
      if (k !== 'length' && Object.hasOwnProperty.call(tagListDom, k)) {
        if (tagListDom[k].dataset.path === tagsViewStore.visitedViews[currentIndex - 1].path) {
          prevTag = tagListDom[k]
        }
        if (tagListDom[k].dataset.path === tagsViewStore.visitedViews[currentIndex + 1].path) {
          nextTag = tagListDom[k]
        }
      }
    }

    try {
      // 标签的偏移下一个标签的左后
      const afterNextTagOffsetLeft =
        nextTag.offsetLeft + nextTag.offsetWidth + tagAndTagSpacing.value

      // 标签的偏移前一个标签的左前
      const beforePrevTagOffsetLeft = prevTag.offsetLeft - tagAndTagSpacing.value
      if (afterNextTagOffsetLeft > $scrollWrapper.scrollLeft + $containerWidth) {
        $scrollWrapper.scrollLeft = afterNextTagOffsetLeft - $containerWidth
      } else if (beforePrevTagOffsetLeft < $scrollWrapper.scrollLeft) {
        $scrollWrapper.scrollLeft = beforePrevTagOffsetLeft
      }
    } catch (error) {}
  }
}

defineExpose({
  moveToTarget
})
</script>

<style lang="scss" scoped>
.scroll-container {
  position: relative;
  width: 100%;
  overflow: hidden;
  white-space: nowrap;

  .el-scrollbar__bar {
    bottom: 0;
  }

  .el-scrollbar__wrap {
    height: 49px;
  }
}
</style>
