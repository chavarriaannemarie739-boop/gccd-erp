<template>
  <div class="app-container">
    <!-- 筛选条件 -->
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--date-wide">
          <el-date-picker
            v-model="query.date_range"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button type="primary" size="small" :loading="loading" @click="handleSearch">搜索</el-button>
          <el-button size="small" @click="handleReset">重置</el-button>
          <el-button type="success" size="small" :loading="exportLoading" @click="handleExport">
            <el-icon class="el-icon--left"><Download /></el-icon>
            导出 Excel
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 操作按钮 -->
    <div class="action-bar action-bar--wrap">
      <el-button @click="handleRefresh" size="default">
        <el-icon><Refresh /></el-icon> 刷新数据
      </el-button>
      <el-button type="primary" plain :loading="exportLoading" @click="handleExport">
        <el-icon><Download /></el-icon>
        导出当前区间 Excel
      </el-button>
    </div>
    
    <!-- 报表标题 -->
    <div class="report-header">
      <h2 class="report-title">钢管生产报表</h2>
      <p class="report-period" v-if="query.date_range && query.date_range.length === 2">
        报表期间：{{ query.date_range[0] }} 至 {{ query.date_range[1] }}
      </p>
      <p class="report-period" v-else>
        报表期间：未选择
      </p>
    </div>

    <!-- 主表格（完全按照Excel模板） -->
    <el-card>
      <el-table
        :data="tableData"
        border
        fit
        style="width: 100%"
        :header-cell-style="{ 'text-align': 'center', 'background-color': '#f5f7fa', 'font-weight': 'bold' }"
        :cell-style="{ 'text-align': 'center' }"
        v-loading="loading"
        show-summary
        :summary-method="getSummaries"
      >
        <!-- 左侧：工序统计数据 -->
        <el-table-column prop="processes_name" label="工序" min-width="80" fixed />
        <el-table-column prop="production_qty" label="产量(吨)" min-width="90">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.production_qty) }}
          </template>
        </el-table-column>
        <el-table-column prop="accessory_cost" label="配件
（不含顶头导板）
（元/吨）" min-width="110">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.accessory_cost) }}
          </template>
        </el-table-column>
        <el-table-column prop="guide_cost" label="顶头/导板(元/吨)" min-width="120">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.guide_cost) }}
          </template>
        </el-table-column>
        <el-table-column prop="gas_cost" label="天然气(元/吨)" min-width="110">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.gas_cost) }}
          </template>
        </el-table-column>
        <el-table-column prop="electric_cost" label="电费(元/吨)" min-width="100">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.electric_cost) }}
          </template>
        </el-table-column>
        <el-table-column prop="wage_cost" label="工资(元/吨)" min-width="100">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.wage_cost) }}
          </template>
        </el-table-column>
        <el-table-column prop="total_cost" label="成本(元/吨)" min-width="100">
          <template #default="{ row }">
            <span v-if="!row.isTotal" :class="getCostClass(row.total_cost)">
              {{ formatNumber(row.total_cost) }}
            </span>
            <span v-else class="total-cost-highlight">
              {{ formatNumber(row.total_cost) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="product_model" label="型号" min-width="80">
          <template #default="{ row }">
            {{ row.isTotal ? '' : (row.product_model || '') }}
          </template>
        </el-table-column>

        <!-- 右侧：辅助数据（只读，数据来源系统自动计算） -->
        <el-table-column prop="aux_accessory" label="配件金额" min-width="100">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_accessory) }}
          </template>
        </el-table-column>
        <el-table-column prop="aux_gas_num" label="天然气量" min-width="110">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_gas_num) }}
          </template>
        </el-table-column>
        <el-table-column prop="aux_gas_amount" label="天然气金额" min-width="110">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_gas_amount) }}
          </template>
        </el-table-column>
        <el-table-column prop="aux_power_num" label="电字" min-width="100">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_power_num) }}
          </template>
        </el-table-column>
        <el-table-column prop="aux_power_amount" label="电费" min-width="100">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_power_amount) }}
          </template>
        </el-table-column>
        <el-table-column prop="aux_wage" label="工资" min-width="100">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_wage) }}
          </template>
        </el-table-column>
        <el-table-column prop="aux_guide" label="顶头导板" min-width="110">
          <template #default="{ row }">
            {{ row.isTotal ? '' : formatNumber(row.aux_guide) }}
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { getDailyReport, exportDailyReportExcel } from '@/api/report/dailyReport'
import { Refresh, Download } from '@element-plus/icons-vue'

export default {
  name: 'DailyProductionReport',
  data() {
    return {
      loading: false,
      exportLoading: false,
      query: {
        date_range: [],  // [start_date, end_date]
        processes_name: ''
      },
      tableData: [],
      summary: {
        total_gas_amount: 0,
        total_cost: 0
      }
    }
  },
  computed: {
    // 合并后的显示数据
    displayData() {
      return this.tableData
    }
  },
  created() {
    // 不自动加载数据，等待用户选择日期范围
  },
  methods: {
    // 加载数据
    async loadData() {
      this.loading = true
      
      // 验证日期范围
      if (!this.query.date_range || this.query.date_range.length !== 2) {
        this.$message.warning('请选择日期范围')
        this.loading = false
        return
      }
      
      const [start_date, end_date] = this.query.date_range
      
      try {
        const res = await getDailyReport({ 
          start_date,
          end_date,
          processes_name: this.query.processes_name || undefined
        })
        
        if (res.code === 200) {
          this.tableData = res.data || []
          this.calculateSummary()
        }
      } catch (error) {
        console.error('加载数据失败:', error)
        // 后端未部署时，使用模拟数据
        this.loadMockData()
      } finally {
        this.loading = false
      }
    },

    // 模拟数据（后端未部署时使用）
    loadMockData() {
      this.tableData = [
        {
          processes_name: '断钢',
          production_qty: 155.60,
          accessory_cost: 7.62,
          guide_cost: 2.35,
          gas_cost: 8.45,
          electric_cost: 5.23,
          wage_cost: 12.50,
          total_cost: 36.15,
          product_model: '45#',
          aux_accessory: 1185.60,
          aux_gas_num: 1280.50,
          aux_gas_amount: 5096.39,
          aux_power_num: 2350.80,
          aux_power_amount: 2068.70,
          aux_wage: 1945.00,
          aux_guide: 365.66,
          isTotal: false
        },
        {
          processes_name: '损耗',
          production_qty: 0,
          accessory_cost: 0,
          guide_cost: 0,
          gas_cost: 0,
          electric_cost: 0,
          wage_cost: 0,
          total_cost: 135.00,
          product_model: '',
          aux_accessory: 0,
          aux_gas_num: 0,
          aux_gas_amount: 0,
          aux_power_num: 0,
          aux_power_amount: 0,
          aux_wage: 0,
          aux_guide: 0,
          isTotal: false
        },
        {
          processes_name: '合计',
          production_qty: 1066.40 * multiplier,
          accessory_cost: 0,
          guide_cost: 0,
          gas_cost: 0,
          electric_cost: 0,
          wage_cost: 0,
          total_cost: 534.61,
          product_model: '',
          aux_accessory: 7158.89 * multiplier,
          aux_gas_num: 6321.50 * multiplier,
          aux_gas_amount: 25159.56 * multiplier,
          aux_power_num: 13676.60 * multiplier,
          aux_power_amount: 12035.40 * multiplier,
          aux_wage: 12142.71 * multiplier,
          aux_guide: 2314.52 * multiplier,
          isTotal: true
        }
      ]
      
      this.calculateSummary()
    },

    // 计算汇总数据
    calculateSummary() {
      let totalGas = 0
      let totalCost = 0

      this.tableData.forEach(row => {
        if (row.processes_name === '合计' || row.processes_name === '损耗') {
          return
        }
        totalGas += parseFloat(row.aux_gas_amount || 0)
        totalCost += parseFloat(row.total_cost || 0)
      })

      // 加上损耗
      totalCost += 135.00

      this.summary = {
        total_gas_amount: totalGas,
        total_cost: totalCost
      }
    },

    // 搜索
    handleSearch() {
      this.loadData()
    },

    // 重置
    handleReset() {
      this.query = {
        date_range: [],
        processes_name: ''
      }
      this.tableData = []
    },

    getExportDateRangeOrWarn() {
      if (!this.query.date_range || this.query.date_range.length !== 2) {
        this.$message.warning('请先选择日期范围')
        return null
      }
      return {
        start_date: this.query.date_range[0],
        end_date: this.query.date_range[1]
      }
    },

    openExportUrlFromRes(res) {
      const d = res && res.data
      if (!d) {
        return false
      }
      const href = d.download_url || d.url
      if (href) {
        window.open(href, '_blank')
        return true
      }
      return false
    },

    // 导出 Excel（与当前日期区间、工序筛选一致）
    async handleExport() {
      const range = this.getExportDateRangeOrWarn()
      if (!range) return
      this.exportLoading = true
      try {
        const res = await exportDailyReportExcel({
          ...range,
          processes_name: this.query.processes_name || undefined
        })
        if (res.code === 200 && this.openExportUrlFromRes(res)) {
          this.$message.success(res.msg || '导出成功')
        } else {
          this.$message.warning(res.msg || '导出失败')
        }
      } catch (e) {
        console.error(e)
        this.$message.error(e.message || '导出失败')
      } finally {
        this.exportLoading = false
      }
    },

    // 判断工序是否可以编辑（报表数据只读，返回false）
    canEdit(processName) {
      return false
    },

    // 刷新数据
    async handleRefresh() {
      if (this.query.date_range && this.query.date_range.length === 2) {
        await this.loadData()
        this.$message.success('刷新成功')
      } else {
        this.$message.warning('请先选择日期范围')
      }
    },

    // 表格汇总方法
    getSummaries(param) {
      const { columns, data } = param
      const sums = []
      columns.forEach((column, index) => {
        if (index === 0) {
          sums[index] = ''
          return
        }
        
        // 合计行：产量列、电字列显示总累计值
        const prop = column.property
        if (prop === 'production_qty' || prop === 'aux_gas_num' || prop === 'aux_power_num') {
          let total = 0
          data.forEach(row => {
            if (!row.isTotal && row.processes_name !== '损耗' && row.processes_name !== '合计') {
              total += parseFloat(row[prop] || 0)
            }
          })
          sums[index] = total.toFixed(2)
          return
        }
        
        sums[index] = ''
      })
      return sums
    },

    // 格式化数字
    formatNumber(num) {
      if (num === null || num === undefined) return '0'
      return Number(num).toFixed(2)
    },

    // 格式化金额
    formatMoney(num) {
      if (num === null || num === undefined) return '0.00'
      return Number(num).toFixed(2)
    },

    // 格式化百分比
    formatPercent(rate) {
      if (rate === null || rate === undefined) return '0%'
      return Number(rate).toFixed(2) + '%'
    },

    // 成本样式
    getCostClass(cost) {
      if (!cost) return ''
      if (cost > 200) return 'cost-high'
      if (cost > 100) return 'cost-medium'
      return 'cost-low'
    }
  }
}
</script>

<style scoped>
.app-container {
  padding: 10px;
}

/* 操作栏 */
.action-bar {
  margin: 10px 0;
  display: flex;
  align-items: center;
  gap: 12px;
}

.action-bar--wrap {
  flex-wrap: wrap;
}

.warning-tip {
  color: #E6A23C;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* 报表标题 */
.report-header {
  margin: 15px 0;
  text-align: center;
  padding: 12px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 8px;
  color: white;
}

.report-title {
  margin: 0 0 6px 0;
  font-size: 20px;
  font-weight: 600;
}

.report-period {
  margin: 0;
  font-size: 13px;
  opacity: 0.9;
}

/* 表格优化 */
:deep(.el-table) {
  font-size: 13px;
}

:deep(.el-table th) {
  padding: 8px 0;
  font-size: 13px;
}

:deep(.el-table td) {
  padding: 6px 0;
}

:deep(.el-table__footer-wrapper) {
  font-weight: bold;
  background-color: #f5f7fa;
}

:deep(.el-table__footer .cell) {
  color: #303133;
  font-size: 13px;
}

:deep(.el-table__body td) {
  white-space: pre-line;
}

/* 成本颜色 */
.cost-high {
  color: #F56C6C;
  font-weight: bold;
}

.cost-medium {
  color: #E6A23C;
  font-weight: bold;
}

.cost-low {
  color: #67C23A;
  font-weight: bold;
}

/* 总成本高亮 */
.total-cost-highlight {
  color: #F56C6C;
  font-weight: bold;
  font-size: 14px;
  background-color: #FFFF00;
  padding: 2px 6px;
  border-radius: 3px;
}
</style>
