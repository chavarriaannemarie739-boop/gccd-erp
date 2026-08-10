// [AI-2026-08] 最低库存阈值仅允许在库存管理按物料与仓库范围维护。
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const sourceRoot = new URL('../', import.meta.url)
const readSource = async (file) => readFile(new URL(file, sourceRoot), 'utf8')

const archiveFiles = [
  'views/mes/RoundSteelList/RoundSteelList.vue',
  'views/mes/RoundSteelList/components/CreateMaterials.vue',
  'views/mes/RoundSteelList/components/EditMaterials.vue',
  'views/mes/AccessoryList/AccessoryList.vue',
  'views/mes/AccessoryList/components/CreateMaterials.vue',
  'views/mes/AccessoryList/components/EditMaterials.vue'
]

test('圆钢和配件档案页面不再维护最低库存', async () => {
  for (const file of archiveFiles) {
    const source = await readSource(file)
    assert.doesNotMatch(source, /min_stock_qty|最低库存/, `${file} 仍包含最低库存入口`)
  }

  const api = await readSource('api/mes/MaterialManagement/MaterialManagement.js')
  assert.doesNotMatch(api, /batchUpdateMinStockQty/, '档案 API 仍暴露批量阈值接口')
})

test('库存页面仅在配件和圆钢范围提供批量设置入口', async () => {
  const source = await readSource(
    'views/mes/WarehouseManagement/MaterialInventory/MaterialInventory.vue'
  )

  assert.match(source, /批量设置最低库存/)
  assert.match(source, /batchUpdateStockThreshold/)
  assert.match(source, /isStockTab/)
  assert.match(source, /new Set|Map\(/, '库存页面未按 material_id 去重')
  assert.match(source, /min_stock_qty/)
  assert.match(source, /库存状态/)
  assert.match(source, /仅显示库存预警/)
  assert.doesNotMatch(source, /ref="tableDataRefss"/, '配件/圆钢表格不应共享同名 ref')
  assert.match(source, /clearCurrentStockSelection/)
})

test('库存阈值接口提交物料集合、仓库范围和阈值', async () => {
  const api = await readSource('api/mes/WarehouseManagement/WarehouseManagement.js')
  const inventory = await readSource(
    'views/mes/WarehouseManagement/MaterialInventory/MaterialInventory.vue'
  )

  assert.match(api, /export function batchUpdateStockThreshold/)
  assert.match(api, /batchUpdateStockThreshold/)
  assert.match(inventory, /material_ids/)
  assert.match(inventory, /warehouse/)
  assert.match(inventory, /min_stock_qty/)
})
