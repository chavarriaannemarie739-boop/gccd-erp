/**
 * [AI-2026-08] 仓库权限范围（与后端 WarehouseScopeService 对齐）
 */
import { useUserStore } from '@/store/modules/user'
import checkPermission from '@/utils/permission'

const ACTION_PERMS = {
  archive: {
    圆钢: 'warehouse.archive.roundSteel',
    配件: 'warehouse.archive.accessory',
    成品: 'warehouse.archive.product'
  },
  inventory: {
    圆钢: 'warehouse.inventory.roundSteel',
    配件: 'warehouse.inventory.accessory',
    成品: 'warehouse.inventory.product'
  },
  inbound: {
    圆钢: 'warehouse.inbound.roundSteel',
    配件: 'warehouse.inbound.accessory',
    成品: 'warehouse.inbound.product'
  },
  outbound: {
    圆钢: 'warehouse.outbound.roundSteel',
    配件: 'warehouse.outbound.accessory',
    成品: 'warehouse.outbound.product'
  }
}

/**
 * @returns {{ unlimited: boolean, archive: string[], inventory: string[], inbound: string[], outbound: string[] }}
 */
export function getWarehouseScopes() {
  const userStore = useUserStore()
  const scopes = userStore.user?.warehouse_scopes
  if (scopes && typeof scopes === 'object') {
    return {
      unlimited: !!scopes.unlimited,
      archive: Array.isArray(scopes.archive) ? scopes.archive : [],
      inventory: Array.isArray(scopes.inventory) ? scopes.inventory : [],
      inbound: Array.isArray(scopes.inbound) ? scopes.inbound : [],
      outbound: Array.isArray(scopes.outbound) ? scopes.outbound : []
    }
  }
  const isSuper = Number(userStore.user?.is_super) === 1
  if (isSuper) {
    return {
      unlimited: true,
      archive: ['圆钢', '配件', '成品'],
      inventory: ['圆钢', '配件', '成品'],
      inbound: ['圆钢', '配件', '成品'],
      outbound: ['圆钢', '配件', '成品']
    }
  }
  return {
    unlimited: false,
    archive: [],
    inventory: [],
    inbound: [],
    outbound: []
  }
}

/**
 * @param {'archive'|'inventory'|'inbound'|'outbound'} action
 * @param {'圆钢'|'配件'|'成品'} type
 */
export function canWarehouse(action, type) {
  const scopes = getWarehouseScopes()
  if (scopes.unlimited) return true
  if ((scopes[action] || []).includes(type)) return true
  const perm = ACTION_PERMS[action]?.[type]
  return perm ? checkPermission([perm]) : false
}

/**
 * @param {'inventory'|'inbound'|'outbound'} action
 * @param {Array<{ type: string, name: string, label: string }>} tabs
 */
export function visibleWarehouseTabs(action, tabs) {
  return (tabs || []).filter((t) => canWarehouse(action, t.type))
}

/**
 * @param {Array<{ type: string, name: string }>} visibleTabs
 * @param {string} currentName
 */
export function pickDefaultTab(visibleTabs, currentName) {
  if (!visibleTabs.length) return currentName || ''
  if (visibleTabs.some((t) => t.name === currentName)) return currentName
  return visibleTabs[0].name
}
