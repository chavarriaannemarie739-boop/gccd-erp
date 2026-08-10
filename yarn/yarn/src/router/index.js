import { createRouter, createWebHashHistory } from 'vue-router'
export const Layout = () => import('@/layout/index.vue')

/**
 * 静态路由（仅登录壳 / 系统页；业务菜单含首页均由后台授权下发）
 */
export const constantRoutes = [
  {
    path: '/redirect',
    component: Layout,
    meta: { hidden: true },
    children: [
      {
        path: '/redirect/:path(.*)',
        component: () => import('@/views/system/components/SystemRedirect.vue')
      }
    ]
  },

  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/system/login.vue'),
    meta: { title: '登录', hidden: true }
  },

  // [AI-2026-08] 首页驾驶舱改为角色菜单授权，不再写死在静态路由
  {
    path: '/_sys',
    component: Layout,
    meta: { hidden: true },
    children: [
      {
        path: 'setting',
        component: () => import('@/views/system/components/SystemSetting.vue'),
        name: 'Setting',
        meta: { title: 'System setting', hidden: true }
      },
      {
        path: '401',
        name: '401',
        component: () => import('@/views/system/components/System401.vue'),
        meta: { title: '401', hidden: true }
      },
      {
        path: '404',
        name: '404',
        component: () => import('@/views/system/components/System404.vue'),
        meta: { title: '404', hidden: true }
      }
    ]
  }
]

/**
 * 创建路由
 */
const router = createRouter({
  history: createWebHashHistory(),
  routes: constantRoutes,
  scrollBehavior: () => ({ left: 0, top: 0 })
})

/**
 * 重置路由
 */
export function resetRouter() {
  router.replace({ path: '/login' })
}

export default router
