import assert from 'node:assert/strict'
import test from 'node:test'

import { parseVueFilters } from './audit-search-registry.mjs'

test('从实际 API import 识别列表函数', () => {
  const source = `
    <template>
      <el-form class="filter-form">
        <el-input v-model="query.manufacturer" placeholder="厂商" />
      </el-form>
    </template>
    <script>
    import { ElMessage } from 'element-plus'
    import { listGoods } from '@/api/mes/WarehouseManagement/RepositoryList'
    import { list, dele } from '@/api/mes/WarehouseManagement/WarehouseManagement'
    export default {
      data() {
        return { query: { manufacturer: '' } }
      }
    }
    </script>
  `

  const parsed = parseVueFilters(source, 'InboundList.vue')

  assert.equal(parsed.api, 'mes/WarehouseManagement/WarehouseManagement')
  assert.equal(parsed.listImport, 'list, dele')
})
