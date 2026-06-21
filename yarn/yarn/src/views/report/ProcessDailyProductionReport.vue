<template>
  <div class="app-container">
    <el-card shadow="never">
      <el-form class="filter-form-flex" @submit.prevent>
        <el-form-item label="统计日期" class="filter-form-item--date-wide">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="default"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button type="primary" :loading="loading" @click="handleSearch">查询</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="success" :loading="exportLoading" @click="handleExport">
            <el-icon class="el-icon--left"><Download /></el-icon>
            导出 Excel
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <div class="report-header">
      <h2 class="report-title">工序每日生产报表</h2>
      <p v-if="dateRange?.length === 2" class="report-period">
        统计期间：{{ dateRange[0] }} 至 {{ dateRange[1] }}
      </p>
      <p v-else class="report-period">请选择日期范围后查询</p>
    </div>

    <el-card shadow="never">
      <el-table
        :data="flatRows"
        v-loading="loading"
        border
        stripe
        style="width: 100%"
        max-height="640"
        :row-class-name="rowClassName"
        :header-cell-style="{ textAlign: 'center', background: '#f5f7fa' }"
      >
        <el-table-column prop="format_date" label="日期" min-width="120" align="center" />
        <el-table-column prop="weekday" label="星期" width="72" align="center" />
        <el-table-column prop="processes_code" label="工序编码" min-width="110" align="center" />
        <el-table-column prop="device_name" label="设备名称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="report_num" label="生产数量" min-width="100" align="right" />
        <el-table-column prop="defective_num" label="不良品数量" min-width="100" align="right" />
        <el-table-column prop="material_id" label="物料ID" min-width="90" align="center" />
        <el-table-column prop="used_quantity" label="用料数量" min-width="100" align="right" />
      </el-table>
    </el-card>

    <el-card v-if="summary" class="summary-card" shadow="never">
      <template #header>汇总统计</template>
      <el-descriptions :column="4" border size="small">
        <el-descriptions-item label="总生产数量">{{ formatQty2Dash(summary.total_report_num) }}</el-descriptions-item>
        <el-descriptions-item label="总不良品数量">{{ formatQty2Dash(summary.total_defective_num) }}</el-descriptions-item>
        <el-descriptions-item label="统计天数">{{ summary.total_days ?? '—' }}</el-descriptions-item>
        <el-descriptions-item label="日均产量">{{ formatQty2Dash(summary.daily_average) }}</el-descriptions-item>
      </el-descriptions>
    </el-card>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { getProcessDailyReport, exportProcessDailyReportApi } from '@/api/report/dailyReport'

/** 数量保留两位小数（表格单元格，空则空字符串） */
function formatQty2(v) {
  if (v === '' || v === null || v === undefined) return ''
  const n = Number(v)
  if (Number.isNaN(n)) return ''
  return n.toFixed(2)
}

/** 汇总区：无数据时显示 — */
function formatQty2Dash(v) {
  if (v === '' || v === null || v === undefined) return '—'
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return n.toFixed(2)
}

function buildFlatRows(list) {
  if (!Array.isArray(list)) return []
  const rows = []
  for (const item of list) {
    const devices = Array.isArray(item.devices) ? item.devices : []
    const materials = Array.isArray(item.materials) ? item.materials : []
    const maxR = Math.max(devices.length, materials.length, 1)
    for (let i = 0; i < maxR; i++) {
      const dev = devices[i]
      const mat = materials[i]
      rows.push({
        rowKind: 'data',
        format_date: i === 0 ? item.format_date ?? '' : '',
        weekday: i === 0 ? item.weekday ?? '' : '',
        processes_code: i === 0 ? item.processes_code ?? '' : '',
        device_name: dev?.device_name ?? '',
        report_num: dev != null ? formatQty2(dev.report_num) : '',
        defective_num: dev != null ? formatQty2(dev.defective_num) : '',
        material_id: mat?.material_id ?? '',
        used_quantity: mat != null ? formatQty2(mat.used_quantity) : ''
      })
    }
    rows.push({
      rowKind: 'subtotal',
      format_date: '小计',
      weekday: '',
      processes_code: '',
      device_name: '',
      report_num: formatQty2(item.total_report_num),
      defective_num: formatQty2(item.total_defective_num),
      material_id: '',
      used_quantity: ''
    })
  }
  return rows
}

const dateRange = ref([])
const loading = ref(false)
const exportLoading = ref(false)
const rawList = ref([])
const summary = ref(null)

const flatRows = computed(() => buildFlatRows(rawList.value))

function rowClassName({ row }) {
  return row.rowKind === 'subtotal' ? 'row-subtotal' : ''
}

function getRangeOrWarn() {
  if (!dateRange.value || dateRange.value.length !== 2) {
    ElMessage.warning('请选择日期范围')
    return null
  }
  return { start_date: dateRange.value[0], end_date: dateRange.value[1] }
}

async function handleSearch() {
  const range = getRangeOrWarn()
  if (!range) return
  loading.value = true
  summary.value = null
  try {
    const res = await getProcessDailyReport(range)
    if (res.code === 200 && res.data) {
      rawList.value = res.data.list || []
      summary.value = res.data.summary ?? null
      if (!rawList.value.length) {
        ElMessage.info('该区间内暂无报工明细')
      }
    } else {
      ElMessage.warning(res?.msg || '查询失败')
      rawList.value = []
    }
  } catch (e) {
    console.error(e)
    ElMessage.error(e?.message || '加载失败')
    rawList.value = []
  } finally {
    loading.value = false
  }
}

function handleReset() {
  dateRange.value = []
  rawList.value = []
  summary.value = null
}

function openExportUrl(res) {
  const d = res?.data
  if (!d) return false
  const href = d.download_url || d.url
  if (href) {
    window.open(href, '_blank')
    return true
  }
  return false
}

async function handleExport() {
  const range = getRangeOrWarn()
  if (!range) return
  exportLoading.value = true
  try {
    const res = await exportProcessDailyReportApi(range)
    if (res.code === 200 && openExportUrl(res)) {
      ElMessage.success(res.msg || '导出成功')
    } else {
      ElMessage.warning(res?.msg || '导出失败')
    }
  } catch (e) {
    console.error(e)
    ElMessage.error(e?.message || '导出失败')
  } finally {
    exportLoading.value = false
  }
}
</script>

<style scoped>
.app-container {
  padding: 10px;
}
.report-header {
  margin: 12px 0;
  text-align: center;
  padding: 12px;
  background: linear-gradient(135deg, #2b5876 0%, #4e4376 100%);
  border-radius: 8px;
  color: #fff;
}
.report-title {
  margin: 0 0 6px;
  font-size: 18px;
  font-weight: 600;
}
.report-period {
  margin: 0;
  font-size: 13px;
  opacity: 0.9;
}
.summary-card {
  margin-top: 12px;
}
:deep(.row-subtotal) {
  font-weight: 600;
  background: #faf6f0 !important;
}
.filter-form-flex {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
}
.filter-form-item--date-wide {
  flex: 1;
  min-width: 280px;
  margin-bottom: 0;
}
.filter-form-item--actions {
  margin-bottom: 0;
}
</style>
