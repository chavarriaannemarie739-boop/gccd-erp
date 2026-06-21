<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.device_name"
            placeholder="设备名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.reason"
            placeholder="维修原因（关键词）"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="报修开始（必选）"
            end-placeholder="报修结束（必选）"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
            @change="handleDateChange"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button type="primary" size="small" :loading="loading" @click="handleSearch">搜索</el-button>
          <el-button size="small" @click="handleReset">重置</el-button>
          <el-button size="small" @click="handleRefresh">
            <el-icon><Refresh /></el-icon>
            刷新
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <div class="report-header-pro">
      <h2 class="report-title">设备维修报表</h2>
      <div class="report-meta">
        <span class="meta-label">统计期间：</span>
        <span v-if="dateRange && dateRange.length === 2" class="meta-value">
          {{ dateRange[0] }} 至 {{ dateRange[1] }}
        </span>
        <span v-else class="meta-value">请先选择报修时间范围后点击「搜索」加载数据</span>
      </div>
    </div>

    <el-card class="report-table-card" shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        fit
        class="pro-table"
        style="width: 100%"
        :header-cell-style="headerCellStyle"
        :cell-style="{ 'text-align': 'center' }"
        :span-method="repairSpanMethod"
      >
        <el-table-column label="工序名称" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            {{ displayProcessName(row) }}
          </template>
        </el-table-column>
        <el-table-column prop="device_name" label="设备名称" min-width="120" show-overflow-tooltip />
        <el-table-column label="维修人" width="100" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.repair_name || '—' }}
          </template>
        </el-table-column>
        <el-table-column prop="reason" label="维修原因" min-width="140" show-overflow-tooltip />
        <el-table-column label="维修项" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            {{ formatRepairItems(row.repair_list) }}
          </template>
        </el-table-column>
        <el-table-column label="维修过程" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            {{ formatRepairProcess(row.detail) }}
          </template>
        </el-table-column>
        <el-table-column label="维修时长" width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ formatRepairDuration(row) }}
          </template>
        </el-table-column>
        <el-table-column label="维修时间" width="168" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.repair_time && String(row.repair_time).trim() ? row.repair_time : '—' }}
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.limit"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          @size-change="getList"
          @current-change="getList"
        />
      </div>
    </el-card>
  </div>
</template>

<script>
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { repairlist } from '@/api/mes/EquipmentLedger/EquipmentLedger'

const formatRepairItems = (raw) => {
  if (raw == null || raw === '') return '—'
  const parts = String(raw)
    .split(';')
    .map((s) => s.trim())
    .filter(Boolean)
  return parts.length ? parts.join('、') : '—'
}

const formatRepairProcess = (detail) => {
  if (!Array.isArray(detail) || detail.length === 0) return '—'
  const lines = detail
    .map((d) => (d && d.detail != null ? String(d.detail).trim() : ''))
    .filter(Boolean)
  return lines.length ? lines.join('；') : '—'
}

export default {
  name: 'RepairReport',
  components: { Refresh },
  data() {
    return {
      loading: false,
      dateRange: [],
      query: {
        page: 1,
        limit: 20,
        device_name: '',
        reason: '',
        create_start_date: '',
        create_end_date: ''
      },
      tableData: [],
      /** 与 tableData 同行：工序名称相同的首行 rowspan，其余行为 0（仅合并工序列，数据不合并） */
      mergeSpanRows: [],
      total: 0,
      headerCellStyle: {
        textAlign: 'center',
        backgroundColor: '#f8fafc',
        color: '#1e293b',
        fontWeight: 'bold',
        fontSize: '14px'
      }
    }
  },
  methods: {
    formatRepairItems,
    formatRepairProcess,
    displayProcessName(row) {
      const name = (row && (row.track_name || row.processes_name)) ? String(row.track_name || row.processes_name).trim() : ''
      if (name) return name
      const code = row && row.processes_code ? String(row.processes_code).trim() : ''
      return code || '—'
    },
    /** 仅按工序名称分组（同一工序多设备、多条报修合并为一个工序单元格） */
    processMergeKey(row) {
      return String(this.displayProcessName(row)).trim() || '—'
    },
    sortForVisualMerge(rows) {
      return [...rows].sort((a, b) => {
        const d = this.processMergeKey(a).localeCompare(this.processMergeKey(b), 'zh-CN')
        if (d !== 0) return d
        return String(b.repair_time || '').localeCompare(String(a.repair_time || ''))
      })
    },
    buildMergeSpanRows(sortedRows) {
      const n = sortedRows.length
      const spans = Array(n).fill(1)
      let i = 0
      while (i < n) {
        const k = this.processMergeKey(sortedRows[i])
        let j = i + 1
        while (j < n && this.processMergeKey(sortedRows[j]) === k) j++
        const len = j - i
        spans[i] = len
        for (let t = i + 1; t < j; t++) spans[t] = 0
        i = j
      }
      return spans
    },
    repairSpanMethod({ columnIndex, rowIndex }) {
      // 仅合并「工序名称」列(0)；设备名称及后续列每行独立
      if (columnIndex === 0) {
        const rs = this.mergeSpanRows[rowIndex] ?? 0
        if (rs > 0) return { rowspan: rs, colspan: 1 }
        return { rowspan: 0, colspan: 0 }
      }
      return { rowspan: 1, colspan: 1 }
    },
    formatRepairDuration(row) {
      const t = row && row.time
      if (t === 0 || t === '0' || t == null || t === '') return '—'
      return String(t)
    },
    handleDateChange(val) {
      if (val && val.length === 2) {
        this.query.create_start_date = val[0]
        this.query.create_end_date = val[1]
      } else {
        this.query.create_start_date = ''
        this.query.create_end_date = ''
        this.tableData = []
        this.mergeSpanRows = []
        this.total = 0
      }
    },
    handleSearch() {
      if (!this.query.create_start_date || !this.query.create_end_date) {
        ElMessage.warning('请先选择报修时间范围后再查询')
        return
      }
      this.query.page = 1
      this.getList()
    },
    handleReset() {
      this.query.device_name = ''
      this.query.reason = ''
      this.query.page = 1
      this.query.limit = 20
      this.dateRange = []
      this.query.create_start_date = ''
      this.query.create_end_date = ''
      this.getList()
    },
    handleRefresh() {
      if (!this.query.create_start_date || !this.query.create_end_date) {
        ElMessage.warning('请先选择报修时间范围后再刷新')
        return
      }
      this.getList()
    },
    async getList() {
      if (!this.query.create_start_date || !this.query.create_end_date) {
        this.tableData = []
        this.mergeSpanRows = []
        this.total = 0
        return
      }
      this.loading = true
      try {
        const resp = await repairlist({ ...this.query })
        const payload = resp && resp.data ? resp.data : {}
        const list = Array.isArray(payload.list) ? payload.list : []
        const sorted = this.sortForVisualMerge(list)
        this.tableData = sorted
        this.mergeSpanRows = this.buildMergeSpanRows(sorted)
        this.total = Number(payload.count) || 0
      } catch (e) {
        console.error('加载维修报表失败', e)
        ElMessage.error('加载维修报表失败')
        this.tableData = []
        this.mergeSpanRows = []
        this.total = 0
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.app-container {
  padding: 24px;
  background-color: #f1f5f9;
  min-height: calc(100vh - 84px);
}

.filter-card-compact {
  margin-bottom: 24px;
  border-radius: 12px;
  border: none;
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
}

.report-header-pro {
  background-color: #0a1628;
  padding: 12px 20px;
  border-radius: 12px 12px 0 0;
  text-align: center;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.report-header-pro::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, #00f0ff, #00d084);
}

.report-title {
  color: #00f0ff;
  font-size: 20px;
  font-weight: 800;
  margin: 0;
  letter-spacing: 1px;
}

.report-meta {
  display: flex;
  justify-content: center;
  align-items: center;
}

.meta-label {
  color: #64748b;
  font-size: 12px;
}

.meta-value {
  color: #f8fafc;
  font-size: 12px;
  font-weight: 500;
}

.report-table-card {
  border-radius: 0 0 12px 12px;
  border: none;
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
}

.pagination-wrap {
  margin-top: 16px;
  text-align: right;
}

:deep(.el-table .cell) {
  padding: 8px 10px;
}

:deep(.el-table__row:hover) {
  background-color: #f8fafc !important;
}
</style>
