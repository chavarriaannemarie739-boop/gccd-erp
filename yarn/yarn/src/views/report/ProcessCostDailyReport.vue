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
      <h2 class="report-title">工序成本日报表</h2>
      <p class="report-period">{{ periodText }}</p>
    </div>

    <el-card shadow="never">
      <el-table
        :data="statistics"
        v-loading="loading"
        border
        stripe
        style="width: 100%"
        max-height="640"
        :header-cell-style="{ textAlign: 'center', background: '#f5f7fa' }"
      >
        <el-table-column prop="processes_code" label="工序编码" min-width="100" align="center" />
        <el-table-column prop="processes_name" label="工序名称" min-width="100" show-overflow-tooltip />
        <el-table-column prop="total_tonnage" label="报工总吨位" min-width="100" align="right" />
        <el-table-column prop="total_defective" label="不良品数量" min-width="100" align="right" />
        <el-table-column prop="task_count" label="工单数量" width="90" align="center" />
        <el-table-column prop="total_wage" label="工人工资总额(元)" min-width="120" align="right" />
        <el-table-column prop="worker_count" label="工人数" width="80" align="center" />
        <el-table-column prop="wage_per_ton" label="吨位工资成本(元/吨)" min-width="130" align="right" />
        <el-table-column prop="accessory_quantity" label="配件数量" min-width="90" align="right" />
        <el-table-column prop="accessory_cost" label="配件成本(元)" min-width="100" align="right" />
        <el-table-column prop="accessory_per_ton" label="吨位配件成本(元/吨)" min-width="130" align="right" />
        <el-table-column prop="total_cost" label="总成本(元)" min-width="100" align="right" />
        <el-table-column prop="total_cost_per_ton" label="吨位总成本(元/吨)" min-width="130" align="right" />
      </el-table>
    </el-card>

    <el-card v-if="summaryRow" class="summary-card" shadow="never">
      <template #header>汇总</template>
      <el-descriptions :column="3" border size="small">
        <el-descriptions-item label="总吨位">{{ summaryRow.total_tonnage }}</el-descriptions-item>
        <el-descriptions-item label="工资总额(元)">{{ summaryRow.total_wage }}</el-descriptions-item>
        <el-descriptions-item label="配件成本(元)">{{ summaryRow.total_accessory_cost }}</el-descriptions-item>
        <el-descriptions-item label="总成本(元)" :span="3">{{ summaryRow.total_cost }}</el-descriptions-item>
      </el-descriptions>
    </el-card>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import {
  getProcessCostDailyReport,
  exportProcessCostDailyReportApi
} from '@/api/report/dailyReport'

const dateRange = ref([])
const loading = ref(false)
const exportLoading = ref(false)
const payload = ref(null)

const statistics = computed(() => (Array.isArray(payload.value?.statistics) ? payload.value.statistics : []))
const summaryRow = computed(() => payload.value?.summary ?? null)
const periodText = computed(() => {
  if (payload.value?.start_date != null && payload.value?.end_date != null) {
    return `统计期间：${payload.value.start_date} 至 ${payload.value.end_date}`
  }
  if (dateRange.value?.length === 2) {
    return `已选：${dateRange.value[0]} 至 ${dateRange.value[1]}（点击查询加载数据）`
  }
  return '请选择日期范围后查询'
})

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
  try {
    const res = await getProcessCostDailyReport(range)
    if (res.code === 200 && res.data) {
      payload.value = res.data
      if (!statistics.value.length) {
        ElMessage.info('该区间内暂无工序成本统计')
      }
    } else {
      ElMessage.warning(res?.msg || '查询失败')
      payload.value = null
    }
  } catch (e) {
    console.error(e)
    ElMessage.error(e?.message || '加载失败')
    payload.value = null
  } finally {
    loading.value = false
  }
}

function handleReset() {
  dateRange.value = []
  payload.value = null
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
    const res = await exportProcessCostDailyReportApi(range)
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
  background: linear-gradient(135deg, #134e5e 0%, #71b280 100%);
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
  opacity: 0.92;
}
.summary-card {
  margin-top: 12px;
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
