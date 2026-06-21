import { computed, onUnmounted, ref, watch } from 'vue'

/**
 * 列表「逐行轮换」：每次步进一条，首行移出、其余上移一格（非连续像素滚动）
 * @param {import('vue').Ref | import('vue').ComputedRef} listRef 列表
 * @param {number} visibleCount 固定可见行数
 * @param {number} stepIntervalMs 步进间隔
 */
function normalizeList(raw) {
  const arr = Array.isArray(raw) ? raw : []
  // null / undefined / 非对象（数字、字符串等）都会导致 r.xxx 抛错，只保留普通对象行
  return arr.filter((item) => item !== null && typeof item === 'object' && !Array.isArray(item))
}

export function useRotatingWindow(listRef, visibleCount, stepIntervalMs = 3500) {
  const tick = ref(0)
  let timer = null

  const displayItems = computed(() => {
    const list = normalizeList(listRef.value)
    if (!list.length) return []
    const n = list.length
    if (n <= visibleCount) return list.slice(0, visibleCount)
    const start = tick.value % n
    return Array.from({ length: visibleCount }, (_, i) => list[(start + i) % n])
  })

  const hasMore = computed(() => {
    const list = normalizeList(listRef.value)
    return list.length > visibleCount
  })

  function step() {
    const list = normalizeList(listRef.value)
    if (list.length > visibleCount) {
      tick.value += 1
    }
  }

  function start() {
    stop()
    timer = setInterval(step, stepIntervalMs)
  }

  function stop() {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
  }

  watch(
    listRef,
    () => {
      tick.value = 0
    },
    { deep: true }
  )

  onUnmounted(stop)

  return { displayItems, hasMore, start, stop, tick }
}
