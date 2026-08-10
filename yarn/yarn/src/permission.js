import router from '@/router'
import defaultSettings from '@/settings'
import { useUserStoreHook } from '@/store/modules/user'
import { useSettingsStoreHook } from '@/store/modules/settings'
import { usePermissionStoreHook } from '@/store/modules/permission'
import { findDefaultHomePath, hasDashboardAccess } from '@/utils/homeRoute'
import { translateRouteTitle } from '@/utils/i18n'
import getPageTitle from '@/utils/page-title'
import NProgress from 'nprogress'
import 'nprogress/nprogress.css'

// 进度条
NProgress.configure({ showSpinner: false })

const userStore = useUserStoreHook()
const settingsStore = useSettingsStoreHook()
const permissionStore = usePermissionStoreHook()

// 白名单路由
const whiteList = ['/login']

router.beforeEach(async (to, from, next) => {
  NProgress.start()
  // 设置页面标题
  document.title = getPageTitle(translateRouteTitle(to.meta.title))

  const storePrefix = defaultSettings.storePrefix
  const tokenName = settingsStore.tokenName

  // 【App端支持】从 URL 参数读取 AdminToken（App WebView 传入）
  const urlParams = new URLSearchParams(window.location.search)
  const urlToken = urlParams.get('AdminToken') || to.query.AdminToken
  if (urlToken) {
    localStorage.setItem(storePrefix + tokenName, urlToken)
    // 清除 URL 中的 token 参数
    urlParams.delete('AdminToken')
    const cleanSearch = urlParams.toString()
    const newUrl = window.location.pathname + (cleanSearch ? '?' + cleanSearch : '') + window.location.hash
    window.history.replaceState({}, '', newUrl)
  }

  const hasToken = localStorage.getItem(storePrefix + tokenName)
  if (hasToken) {
    if (to.path === '/login') {
      // 已登录：进默认首页（有驾驶舱权限才进 /dashboard）
      const home = findDefaultHomePath(permissionStore.routes)
      next({ path: home === '/404' ? '/' : home })
      NProgress.done()
    } else {
      const hasRoles = userStore.user.roles && userStore.user.roles.length > 0
      if (hasRoles) {
        const home = findDefaultHomePath(permissionStore.routes)
        // [AI-2026-08] 根路径或无首页权限时访问驾驶舱 → 落到首个已授权菜单
        if (to.path === '/' || to.path === '/dashboard') {
          next({ path: home, replace: true })
          return
        }
        if (
          (to.path === '/dashboard/index' || to.path.startsWith('/dashboard/')) &&
          !hasDashboardAccess(permissionStore.routes)
        ) {
          next({ path: home, replace: true })
          return
        }
        // 未匹配到任何路由，跳转404
        if (to.matched.length === 0) {
          from.name ? next({ name: from.name }) : next({ name: '404' })
        } else {
          next()
        }
      } else {
        try {
          const { menus } = await userStore.userInfo()
          const accessRoutes = await permissionStore.generateRoutes(menus)
          accessRoutes.forEach((route) => {
            router.addRoute(route)
          })
          const home = findDefaultHomePath(permissionStore.routes)
          if (
            to.path === '/' ||
            to.path === '/dashboard' ||
            ((to.path === '/dashboard/index' || to.path.startsWith('/dashboard/')) &&
              !hasDashboardAccess(permissionStore.routes))
          ) {
            next({ path: home, replace: true })
          } else {
            next({ ...to, replace: true })
          }
        } catch (error) {
          // 移除 token 并跳转登录页
          await userStore.resetToken()
          next(`/login?redirect=${to.path}`)
          NProgress.done()
        }
      }
    }
  } else {
    // 未登录可以访问白名单页面
    if (whiteList.indexOf(to.path) !== -1) {
      next()
    } else {
      next(`/login?redirect=${to.path}`)
      NProgress.done()
    }
  }
})

router.afterEach(() => {
  NProgress.done()
})
