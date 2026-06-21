/**
 * v-preventReClick  防止重复点击
 */
export default {
  mounted(el, binding, vnode, prevVnode) {
    el.addEventListener('click', () => {
      if (!el.disabled) {
        el.disabled = true
        setTimeout(() => {
          el.disabled = false
        }, 3000)
      }
    })
  }
}
