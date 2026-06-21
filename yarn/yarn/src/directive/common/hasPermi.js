/**
 * v-hasPermi 操作权限处理
 */
import { useUserStore } from '@/store/modules/user.js'
export default {
  mounted(el, binding, vnode) {
    const { value } = binding
    if (value && value instanceof Array && value.length > 0) {
      const userStore = useUserStore()
      const roles = userStore.user.roles
      const permissionRoles = value
      const permissionHas = roles.some((role) => {
        return permissionRoles.includes(role)
      })
      if (!permissionHas) {
        el.parentNode && el.parentNode.removeChild(el)
      }
    } else {
      throw new Error(`请设置操作权限标签值`)
    }
  }
}
