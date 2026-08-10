import { defineStore } from 'pinia'
import { constantRoutes } from '@/router'
import { store } from '@/store'

const modules = import.meta.glob('../../views/**/**.vue')
const Layout = () => import('@/layout/index.vue')
const ParentView = () => import('@/components/ParentView/index.vue')
const Fallback404 = () => import('@/views/system/components/System404.vue')

/**
 * 解析动态路由组件：空 component 的中间目录用 ParentView，否则回退到 System404
 */
function resolveRouteComponent(route) {
  if (route.component?.toString() === 'Layout') {
    return Layout
  }
  const compPath = String(route.component || '').trim()
  if (!compPath) {
    return route.children?.length ? ParentView : Fallback404
  }
  return modules[`../../views/${compPath}.vue`] || Fallback404
}

/** 与 constantRoutes 顶层 path 一致时视为重复（静态路由已注册，后端菜单再下发会导致双菜单、同 index 联动高亮） */
function normalizeTopPath(path) {
  if (path == null || path === '') return '/'
  return path.startsWith('/') ? path : `/${path}`
}

const CONSTANT_TOP_PATHS = new Set(constantRoutes.map((r) => normalizeTopPath(r.path)))

function dropDynamicOverlappingConstants(routes) {
  return routes.filter((r) => !CONSTANT_TOP_PATHS.has(normalizeTopPath(r.path)))
}

/** 动态菜单内部同一 path 重复（如库表重复插入） */
function dedupeDynamicRoutesByPath(routes) {
  const seen = new Set()
  return routes.filter((r) => {
    const key = normalizeTopPath(r.path)
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function cloneMenuTree(menus) {
  if (!Array.isArray(menus)) return []
  try {
    return typeof structuredClone === 'function'
      ? structuredClone(menus)
      : JSON.parse(JSON.stringify(menus))
  } catch {
    return menus
  }
}

/**
 * 是否「设备维修报表」同一页面（与菜单标题无关，避免误删其它含「维修」字样的菜单）
 */
function isRepairReportPageRoute(node) {
  if (!node || typeof node !== 'object') return false
  const comp = String(node.component || '')
    .replace(/\\/g, '/')
    .toLowerCase()
  if (!comp || comp === 'layout') return false
  if (comp.includes('equipmentledger/repairreport')) return true
  if (/\/repairreport(\.vue)?$/i.test(comp)) return true
  const path = String(node.path || '').toLowerCase()
  if (path === 'repair-maintenance-report') return true
  const n = String(node.name || '')
  if (n === 'RepairMaintenanceReport' || n === 'RepairReport') return true
  return false
}

/**
 * 整棵树里只保留第一个「维修报表页」菜单（库表重复、或不同父级下各有一条时去重）
 * 不再前端注入兜底菜单，避免与 system_menu 重复两条。
 */
function pruneDuplicateRepairReportMenuEntries(routes) {
  let seen = false
  const walk = (arr) => {
    if (!Array.isArray(arr) || arr.length === 0) return
    for (let i = 0; i < arr.length; ) {
      const node = arr[i]
      if (isRepairReportPageRoute(node)) {
        if (seen) {
          arr.splice(i, 1)
          continue
        }
        seen = true
      }
      if (node.children?.length) walk(node.children)
      i++
    }
  }
  walk(routes)
}

/** 左侧一级菜单顺序（按路由顶层 path）；未列入的项排在后面 */
const PRIMARY_MENU_PATH_ORDER = [
  '/dashboard',
  '/ProductionOrderList',
  '/WarehouseManagement',
  '/inventory-check',
  '/report',
  '/EnergyConsumption',
  '/EquipmentLedger',
  '/BasicSettings',
  '/system'
]

function sortRoutesByPrimaryMenu(routes) {
  const orderOf = (path) => {
    const p = normalizeTopPath(path)
    const i = PRIMARY_MENU_PATH_ORDER.indexOf(p)
    return i === -1 ? PRIMARY_MENU_PATH_ORDER.length : i
  }
  return [...routes].sort((a, b) => {
    const d = orderOf(a.path) - orderOf(b.path)
    if (d !== 0) return d
    return String(a.path || '').localeCompare(String(b.path || ''))
  })
}

/**
 * 递归生成异步(动态)路由
 * @param routes 接口返回的异步(动态)路由
 * @returns 异步(动态)路由
 */
const filterAsyncRoutes = (routes) => {
  const asyncRoutes = []
  routes.forEach((route) => {
    const tmpRoute = { ...route } // ES6扩展运算符复制新对象
    if (!route.name) {
      tmpRoute.name = route.path
    }
    tmpRoute.component = resolveRouteComponent(tmpRoute)

    if (tmpRoute.children) {
      tmpRoute.children = filterAsyncRoutes(tmpRoute.children)
    }

    asyncRoutes.push(tmpRoute)
  })

  return asyncRoutes
}

// setup
export const usePermissionStore = defineStore('permission', () => {
  // state
  const routes = ref([])

  // actions
  function setRoutes(newRoutes) {
    routes.value = sortRoutesByPrimaryMenu(constantRoutes.concat(newRoutes))
  }
  /**
   * 生成动态路由
   */
  function generateRoutes(menus) {
    return new Promise((resolve, reject) => {
      const menusDraft = cloneMenuTree(menus)
      pruneDuplicateRepairReportMenuEntries(menusDraft)
      const accessedRoutes = dedupeDynamicRoutesByPath(
        dropDynamicOverlappingConstants(filterAsyncRoutes(menusDraft))
      )
      setRoutes(accessedRoutes)
      resolve(accessedRoutes)
    })
  }
  /**
   * 混合模式左侧菜单
   */
  const mixLeftMenu = ref([])
  function getMixLeftMenu(activeTop) {
    routes.value.forEach((item) => {
      if (item.path === activeTop) {
        mixLeftMenu.value = item.children || []
      }
    })
  }
  return { routes, setRoutes, generateRoutes, getMixLeftMenu, mixLeftMenu }
})

// 非setup
export function usePermissionStoreHook() {
  return usePermissionStore(store)
}
