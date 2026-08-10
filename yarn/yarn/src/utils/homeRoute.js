/**
 * [AI-2026-08] 按已授权动态菜单解析登录后默认首页（无权限时不进驾驶舱）
 */

const SKIP_PATHS = new Set(['/login', '/redirect', '/_sys', '/404', '/401', '/setting'])

function normalizePath(path) {
  if (!path) return ''
  return path.startsWith('/') ? path : `/${path}`
}

function joinPath(parent, child) {
  if (!child) return normalizePath(parent)
  if (child.startsWith('/')) return child
  const base = normalizePath(parent).replace(/\/$/, '')
  return `${base}/${child}`.replace(/\/+/g, '/')
}

function isVisibleRoute(route) {
  if (!route || route.meta?.hidden) return false
  const path = normalizePath(route.path)
  if (SKIP_PATHS.has(path)) return false
  if (path.startsWith('/redirect')) return false
  return true
}

function collectLeaves(routes, parentPath = '', out = []) {
  for (const route of routes || []) {
    if (!isVisibleRoute(route)) continue
    const fullPath = route.path?.startsWith('/')
      ? normalizePath(route.path)
      : joinPath(parentPath, route.path)
    const children = (route.children || []).filter(isVisibleRoute)
    if (children.length) {
      collectLeaves(children, fullPath, out)
    } else if (fullPath && fullPath !== '/') {
      out.push({ path: fullPath, name: route.name, title: route.meta?.title })
    }
  }
  return out
}

/**
 * @param {Array} routes permissionStore.routes（含静态+动态）
 * @returns {string}
 */
export function findDefaultHomePath(routes) {
  const leaves = collectLeaves(routes)
  if (!leaves.length) return '/404'
  const dashboard = leaves.find(
    (item) =>
      item.name === 'Dashboard' ||
      item.path === '/dashboard' ||
      item.path === '/dashboard/index' ||
      item.path.startsWith('/dashboard/')
  )
  return dashboard?.path || leaves[0].path
}

/**
 * @param {Array} routes
 * @returns {boolean}
 */
export function hasDashboardAccess(routes) {
  return collectLeaves(routes).some(
    (item) =>
      item.name === 'Dashboard' ||
      item.path === '/dashboard' ||
      item.path === '/dashboard/index' ||
      item.path.startsWith('/dashboard/')
  )
}
