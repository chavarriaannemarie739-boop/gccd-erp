import { createRouter, createWebHashHistory } from 'vue-router'
export const Layout = () => import('@/layout/index.vue')

/**
 * 静态路由
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

  {
    path: '/',
    name: '/',
    component: Layout,
    redirect: '/dashboard',
    children: [
      {
        // path: 'dashboard'解决首页会再次添加tag,
        path: '/dashboard',
        name: 'Dashboard',
        component: () => import('@/views/system/index.vue'),
        meta: {
          title: 'dashboard',
          icon: 'home-filled',
          affix: true,
          keepAlive: true,
          alwaysShow: false,
          /** 驾驶舱为整页大屏，不在顶栏重复显示「首页」面包屑以省出纵向空间 */
          breadcrumb: false
        }
      },
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
      },
    ]
  },
  {
    path: '/report',
    name: '/report',
    component: Layout,
    redirect: '/report/daily-production',
    meta: { title: '报表中心', icon: 'document', alwaysShow: true },
    children: [
      {
        path: 'daily-production',
        name: 'DailyProductionReport',
        component: () => import('@/views/report/DailyProductionReport.vue'),
        meta: { title: '生产统计日报', icon: 'menu' }
      },
      {
        path: 'process-daily-production',
        name: 'ProcessDailyProductionReport',
        component: () => import('@/views/report/ProcessDailyProductionReport.vue'),
        meta: { title: '工序每日生产报表', icon: 'document' }
      },
      {
        path: 'process-cost-daily',
        name: 'ProcessCostDailyReport',
        component: () => import('@/views/report/ProcessCostDailyReport.vue'),
        meta: { title: '工序成本日报表', icon: 'document' }
      },
      {
        path: 'coefficient',
        name: 'CoefficientManage',
        component: () => import('@/views/report/CoefficientManage.vue'),
        meta: { title: '系数配置', icon: 'setting' }
      },
      {
        path: 'batch-material-trace',
        name: 'BatchMaterialTrace',
        component: () => import('@/views/report/BatchMaterialTrace.vue'),
        meta: { title: '批次物料追溯', icon: 'connection' }
      }
    ]
  },
  {
    path: '/inventory-check',
    name: '/inventory-check',
    component: Layout,
    redirect: '/inventory-check/test-product-stocktake',
    meta: { title: '盘点检验', icon: 'finished', alwaysShow: true },
    children: [
      {
        path: 'test-product-stocktake',
        name: 'TestProductStocktake',
        component: () => import('@/views/mes/ProductStocktake/ProductStocktake.vue'),
        meta: { title: '盘点记录', icon: 'menu' }
      },
      {
        path: 'test-product-stocktake-report',
        name: 'TestProductStocktakeReport',
        component: () => import('@/views/mes/ProductStocktake/ProductStocktakeReport.vue'),
        meta: { title: '盘点报表', icon: 'document' }
      },
      {
        path: 'test-quality-inspection',
        name: 'TestQualityInspection',
        component: () => import('@/views/mes/QualityInspectionRecord/QualityInspectionRecord.vue'),
        meta: { title: '质检记录', icon: 'menu' }
      },
      {
        path: 'test-quality-inspection-report',
        name: 'TestQualityInspectionReport',
        component: () => import('@/views/mes/QualityInspectionRecord/QualityInspectionReport.vue'),
        meta: { title: '质检报表', icon: 'document' }
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
