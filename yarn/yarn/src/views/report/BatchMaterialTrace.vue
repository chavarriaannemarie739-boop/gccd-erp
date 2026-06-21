<template>
  <div class="app-container batch-trace-page">
    <el-card shadow="never" class="filter-card-compact">
      <el-form class="filter-form-flex" @submit.prevent="handleSearch">
        <el-form-item class="filter-form-item--field" style="flex: 1; max-width: 420px">
          <el-input
            v-model="keyword"
            placeholder="输入生产计划单号、领料单号或出库单号"
            clearable
            size="small"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button type="primary" size="small" :loading="loading" @click="handleSearch">查询</el-button>
          <el-button size="small" @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <p class="hint">
      说明：展示与关键字关联的<strong>领料申请</strong>与<strong>出库明细</strong>；出库确认后会有「物料批次」及按入库单价估算的<strong>本行材料金额</strong>（用于核对，最终以财务规则为准）。
    </p>

    <el-card v-if="result" shadow="never" class="block-card">
      <template #header>
        <span>生产计划（若关键字为计划单号且存在）</span>
      </template>
      <el-empty v-if="!result.production" description="未匹配到生产计划主表记录（可能关键字为领料/出库单号）" />
      <el-descriptions v-else border :column="2" size="small">
        <el-descriptions-item label="计划单号">
          {{ result.production.production_plan_code || '—' }}
        </el-descriptions-item>
        <el-descriptions-item label="创建时间">
          {{ result.production.create_time || '—' }}
        </el-descriptions-item>
      </el-descriptions>
    </el-card>

    <el-card v-if="result" shadow="never" class="block-card">
      <template #header>
        <span>领料申请（receiving）</span>
      </template>
      <el-table :data="result.receivings" border size="small" max-height="360" empty-text="无记录">
        <el-table-column prop="receiving_code" label="领料单号" min-width="140" show-overflow-tooltip />
        <el-table-column prop="relation_code" label="关联计划/单号" min-width="140" show-overflow-tooltip />
        <el-table-column prop="material_name" label="物料" min-width="120" show-overflow-tooltip />
        <el-table-column prop="material_num" label="申请数量" width="100" align="right" />
        <el-table-column prop="status" label="状态" width="80" align="center" />
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
        <el-table-column prop="create_time" label="时间" width="165" />
      </el-table>
    </el-card>

    <el-card v-if="result" shadow="never" class="block-card">
      <template #header>
        <span>出库明细（wms_out）</span>
      </template>
      <el-table :data="result.outbounds" border size="small" max-height="480" empty-text="无记录">
        <el-table-column prop="out_code" label="出库单号" min-width="130" show-overflow-tooltip />
        <el-table-column prop="relation_code" label="关联单号" min-width="120" show-overflow-tooltip />
        <el-table-column prop="material_name" label="物料" min-width="100" show-overflow-tooltip />
        <el-table-column prop="out_num" label="出库数量" width="90" align="right" />
        <el-table-column prop="material_batch" label="物料批次" min-width="120" show-overflow-tooltip />
        <el-table-column prop="status" label="状态" width="80" align="center">
          <template #default="{ row }">
            <span>{{ row.status === 1 || row.status === '1' ? '已出库' : '待审核' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="in_unit_price" label="入库单价" width="100" align="right" />
        <el-table-column prop="line_material_cost" label="本行材料金额" width="120" align="right" />
        <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip />
        <el-table-column prop="create_time" label="时间" width="165" />
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { traceMaterialFlow } from '@/api/mes/WarehouseManagement/OutboundManagement'

export default {
  name: 'BatchMaterialTrace',
  data() {
    return {
      keyword: '',
      loading: false,
      result: null
    }
  },
  methods: {
    async handleSearch() {
      const k = (this.keyword || '').trim()
      if (!k) {
        ElMessage.warning('请输入查询关键字')
        return
      }
      this.loading = true
      try {
        const resp = await traceMaterialFlow({ keyword: k })
        this.result = resp.data || resp
        if (
          (!this.result.receivings || this.result.receivings.length === 0) &&
          (!this.result.outbounds || this.result.outbounds.length === 0)
        ) {
          ElMessage.info('未查到领料或出库记录，可换计划单号/领料单号/出库单号再试')
        }
      } catch (e) {
        this.result = null
      } finally {
        this.loading = false
      }
    },
    handleReset() {
      this.keyword = ''
      this.result = null
    }
  }
}
</script>

<style scoped>
.batch-trace-page {
  padding: 10px;
}
.hint {
  margin: 12px 0;
  font-size: 13px;
  color: var(--el-text-color-secondary);
  line-height: 1.6;
}
.block-card {
  margin-bottom: 14px;
}
.block-card :deep(.el-card__header) {
  font-weight: 600;
}
</style>
