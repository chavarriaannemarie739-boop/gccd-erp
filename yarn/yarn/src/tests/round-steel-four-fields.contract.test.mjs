// [AI-2026-08] 圆钢四字段静态契约：名称、厂商、材质、型号。
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const directFieldFiles = [
  'views/mes/RoundSteelList/RoundSteelList.vue',
  'views/mes/RoundSteelList/components/CreateMaterials.vue',
  'views/mes/RoundSteelList/components/EditMaterials.vue',
  'views/mes/RoundSteelList/components/ViewMaterials.vue',
  'views/mes/ProductionOrderList/components/NewProductionPlan.vue',
  'views/mes/ProductionOrderList/components/EditingAndProduction.vue',
  'views/mes/ProductionOrderList/components/ViewProductionPlan.vue',
  'views/mes/WarehouseManagement/MaterialInventory/MaterialInventory.vue',
  'views/mes/WarehouseManagement/WarehousingManagement/InboundList/InboundList.vue',
  'views/mes/WarehouseManagement/WarehousingManagement/InboundList/components/CreateInventory.vue',
  'views/mes/WarehouseManagement/OutboundManagement/OutboundList/OutboundList.vue',
  'views/mes/WarehouseManagement/OutboundManagement/OutboundList/components/NewOutbound.vue'
]

const businessFieldFiles = [
  'views/mes/ProductionOrderList/ProductionOrderList.vue',
  'views/mes/ProductionOrderList/components/OrderStatus.vue',
  'views/mes/ProductionOrderList/WorkReport/WorkReport.vue',
  'views/mes/WarehouseManagement/DeliveryNote/DeliveryNote.vue',
  'views/mes/WarehouseManagement/DeliveryNote/components/AddShippingOrder.vue',
  'views/mes/ProductStocktake/ProductStocktake.vue',
  'views/mes/ProductStocktake/ProductStocktakeReport.vue',
  'views/mes/QualityInspectionRecord/QualityInspectionRecord.vue'
]

const sourceRoot = new URL('../', import.meta.url)
const readSource = async (file) => readFile(new URL(file, sourceRoot), 'utf8')

test('圆钢直读页面包含名称、厂商、材质、型号字段', async () => {
  for (const file of directFieldFiles) {
    const source = await readSource(file)
    for (const field of ['material_name', 'manufacturer', 'material_spec', 'material_model']) {
      assert.match(source, new RegExp(`\\b${field}\\b`), `${file} 缺少 ${field}`)
    }
  }
})

test('圆钢业务页面包含四个正确拼写的业务字段', async () => {
  for (const file of businessFieldFiles) {
    const source = await readSource(file)
    for (const field of [
      'y_material_name',
      'y_material_manufacturer',
      'y_material_spec',
      'y_material_model'
    ]) {
      assert.match(source, new RegExp(`\\b${field}\\b`), `${file} 缺少 ${field}`)
    }
  }
})

test('目标页面不再出现圆钢规格标签或错误字段拼写', async () => {
  for (const file of [...directFieldFiles, ...businessFieldFiles]) {
    const source = await readSource(file)
    assert.doesNotMatch(source, /圆钢规格/, `${file} 仍含“圆钢规格”`)
    assert.doesNotMatch(source, /\by_mateial_/, `${file} 仍含 y_mateial_*`)
    assert.match(source, /\[AI-2026-08\]/, `${file} 缺少改动边界注释`)
  }
})

test('流转卡后台预览按名称厂商材质型号统一分隔展示', async () => {
  const source = await readSource(
    'views/mes/ProductionOrderList/components/FlowCardPrintDialog.vue'
  )
  for (const field of [
    'y_material_name',
    'y_material_manufacturer',
    'y_material_spec',
    'y_material_model'
  ]) {
    assert.match(source, new RegExp(`\\b${field}\\b`), `流转卡预览缺少 ${field}`)
  }
  assert.match(source, /join\(' \/ '\)/, '流转卡预览未使用统一清晰分隔符')
})

test('生产计划 Excel 导出包含圆钢四字段且规格标签语义正确', async () => {
  const source = await readSource('views/mes/ProductionOrderList/ProductionOrderList.vue')
  const apiSource = await readSource('api/mes/ProductionOrderList/ProductionOrderList.js')
  assert.match(source, /圆钢名称/)
  assert.match(source, /圆钢厂商/)
  assert.match(source, /圆钢材质/)
  assert.match(source, /圆钢型号/)
  assert.match(source, /成品规格/)
  assert.match(source, /成品型号/)
  assert.match(source, /XLSX\.utils\.json_to_sheet/)
  assert.doesNotMatch(`${source}\n${apiSource}`, /Message\/exportExcel/)
})

test('发货单和质量记录圆钢列包含厂商且材质标签正确', async () => {
  for (const file of [
    'views/mes/WarehouseManagement/DeliveryNote/DeliveryNote.vue',
    'views/mes/QualityInspectionRecord/QualityInspectionRecord.vue'
  ]) {
    const source = await readSource(file)
    assert.match(source, /圆钢厂商/)
    assert.match(source, /圆钢材质/)
    assert.doesNotMatch(source, /圆钢规格/)
  }
})
