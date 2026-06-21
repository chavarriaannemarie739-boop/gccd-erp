<template>
  <div class="app-container">
    <!-- 筛选条件 -->
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
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
            <el-icon><Refresh /></el-icon> 刷新
          </el-button>
          <el-button type="success" size="small" @click="handleExport">导出Excel</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 报表标题 -->
    <div class="report-header-pro">
      <h2 class="report-title">质检汇总报表</h2>
      <div class="report-meta">
        <span class="meta-label">报表期间：</span>
        <span class="meta-value" v-if="dateRange && dateRange.length === 2">
          {{ dateRange[0] }} 至 {{ dateRange[1] }}
        </span>
        <span class="meta-value" v-else>全量汇总数据</span>
      </div>
    </div>

    <!-- 主表格 -->
    <el-card class="report-table-card" shadow="never">
      <el-table
        :data="tableData"
        border
        fit
        style="width: 100%"
        class="pro-table"
        :header-cell-style="{ 'text-align': 'center', 'background-color': '#f8fafc', 'color': '#1e293b', 'font-weight': 'bold', 'font-size': '14px' }"
        :cell-style="{ 'text-align': 'center' }"
        v-loading="loading"
        :span-method="objectSpanMethod"
      >
        <el-table-column prop="process_name" label="工序" width="120" fixed="left" />
        <el-table-column prop="shift_text" label="班次" width="100" />
        <el-table-column label="型号" min-width="180">
          <template #default="{ row }">
            <div class="info-group">
              <span class="info-val">{{ row.c_material_spec }}</span>
              <span class="info-sep">|</span>
              <span class="info-val model-highlight">{{ row.c_material_model }}</span>
            </div>
          </template>
        </el-table-column>

        <!-- 动态不良品项列 -->
        <el-table-column
          v-for="defect in defectColumns"
          :key="defect"
          :label="defect"
          width="100"
        >
          <template #default="{ row }">
            <span :class="{ 'defect-num': (row.defects[defect] || 0) > 0 }">
              {{ row.defects[defect] || 0 }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="合计" width="100">
          <template #default="{ row }">
            <span class="total-num-small">{{ row.shift_total_defects }}</span>
          </template>
        </el-table-column>

        <el-table-column label="生产吨位" width="120">
          <template #default="{ row }">
            <div class="tonnage-container">
              <span class="tonnage-val">{{ formatNumber(row.production_tonnage) }}</span>
              <span class="unit">t</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip />
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { list as qcrList } from '@/api/mes/QualityInspectionRecord/QualityInspectionRecord'
import { list as prList } from '@/api/mes/EnergyConsumption/EnergyConsumption'
import { Refresh } from '@element-plus/icons-vue'

export default {
  name: 'QualityInspectionReport',
  components: { Refresh },
  data() {
    return {
      loading: false,
      dateRange: [],
      query: {
        check_start_date: '',
        check_end_date: ''
      },
      tableData: [],
      defectColumns: [],
      spanArr: [],
      // 目标工序
      targetProcesses: ['穿孔', '矫直', '酸洗']
    }
  },
  created() {
    const today = new Date().toISOString().split('T')[0]
    this.dateRange = [today, today]
    this.query.check_start_date = today
    this.query.check_end_date = today
    this.loadData()
  },
  methods: {
    handleDateChange(val) {
      if (val && val.length === 2) {
        this.query.check_start_date = val[0]
        this.query.check_end_date = val[1]
      } else {
        this.query.check_start_date = ''
        this.query.check_end_date = ''
      }
    },

    async loadData() {
      this.loading = true
      try {
        // 1. 获取质检记录
        const qcrRes = await qcrList({
          check_start_date: this.query.check_start_date,
          check_end_date: this.query.check_end_date,
          limit: 1000
        })

        // 2. 获取报工记录用于统计吨位
        const prRes = await prList({
          create_start_date: this.query.check_start_date,
          create_end_date: this.query.check_end_date,
          limit: 2000
        })

        const qcRecords = (qcrRes.data && qcrRes.data.list) || []
        const prodReports = (prRes.data && prRes.data.list) || []

        this.processData(qcRecords, prodReports)
      } catch (error) {
        console.error('加载数据失败:', error)
        this.$message.error('加载数据失败')
      } finally {
        this.loading = false
      }
    },

    processData(qcRecords, prodReports) {
      const defectSet = new Set()
      const rows = []

      // 1. 预处理报工数据：按 [日期|工序|班次] 汇总生产吨位 (不分型号，按班次汇总)
      const shiftTonnageMap = {}
      prodReports.forEach(pr => {
        const date = (pr.create_time || '').split(' ')[0]
        const proc = pr.processes_name || ''
        // 班次兼容处理: 0/1, "0"/"1", "白班"/"夜班"
        let shift = ''
        if (pr.class === 0 || pr.class === '0' || pr.class === '白班') shift = '白班'
        else if (pr.class === 1 || pr.class === '1' || pr.class === '夜班') shift = '夜班'
        
        if (date && proc && shift) {
          const key = `${date}|${proc}|${shift}`
          if (!shiftTonnageMap[key]) shiftTonnageMap[key] = 0
          shiftTonnageMap[key] += parseFloat(pr.report_num || 0)
        }
      })

      // 2. 预处理质检数据：按 [工序|日期|班次] 进行初步聚合
      // 结构: groupMap[proc][date][shift] = { models: [], shift_total_defects: 0, tonnage: 0, remark: '' }
      const groupMap = {}

      qcRecords.forEach(record => {
        const proc = record.process_name
        if (!this.targetProcesses.includes(proc)) return
        const date = record.check_date
        const remark = record.remark || ''

        if (!groupMap[proc]) groupMap[proc] = {}
        if (!groupMap[proc][date]) groupMap[proc][date] = { '白班': null, '夜班': null }

        if (record.items && record.items.length > 0) {
          record.items.forEach(item => {
            const spec = (item.c_material_spec || '').trim()
            const model = (item.c_material_model || '').trim()

            const shifts = [
              { type: '白班', qtyKey: 'day_qty' },
              { type: '夜班', qtyKey: 'night_qty' }
            ]

            shifts.forEach(shiftObj => {
              const rowDefects = {}
              let rowTotal = 0
              let hasDefects = false

              if (item.defects && item.defects.length > 0) {
                item.defects.forEach(d => {
                  const qty = parseInt(d[shiftObj.qtyKey] || 0)
                  if (qty > 0) {
                    const defectName = d.defect_text || '未分类'
                    defectSet.add(defectName)
                    rowDefects[defectName] = (rowDefects[defectName] || 0) + qty
                    rowTotal += qty
                    hasDefects = true
                  }
                })
              }

              // 即使没有不良品，只要对应的报工里有吨位，也可能需要显示（根据用户理解）
              const tonnageKey = `${date}|${proc}|${shiftObj.type}`
              const tonnage = shiftTonnageMap[tonnageKey] || 0

              if (hasDefects || tonnage > 0) {
                if (!groupMap[proc][date][shiftObj.type]) {
                  groupMap[proc][date][shiftObj.type] = {
                    models: [],
                    shift_total_defects: 0,
                    tonnage: tonnage,
                    remark: remark
                  }
                }
                
                groupMap[proc][date][shiftObj.type].models.push({
                  spec,
                  model,
                  defects: rowDefects,
                  row_total: rowTotal
                })
                groupMap[proc][date][shiftObj.type].shift_total_defects += rowTotal
              }
            })
          })
        }
      })

      // 3. 构建表格行并计算合并信息
      const tableData = []
      this.targetProcesses.forEach(proc => {
        const procGroup = groupMap[proc]
        if (!procGroup) return

        // 按日期排序
        const dates = Object.keys(procGroup).sort()
        dates.forEach(date => {
          const dateGroup = procGroup[date]
          // 固定班次排序：白班在上，夜班在下
          const shiftTypes = ['白班', '夜班']
          shiftTypes.forEach(shiftType => {
            const shiftData = dateGroup[shiftType]
            if (shiftData && shiftData.models.length > 0) {
              shiftData.models.forEach((m, idx) => {
                tableData.push({
                  process_name: proc,
                  date,
                  shift_text: shiftType,
                  c_material_spec: m.spec,
                  c_material_model: m.model,
                  defects: m.defects,
                  row_total: m.row_total, // 实际上用户要求合计跟班次合并，所以这里存 shift_total_defects
                  shift_total_defects: shiftData.shift_total_defects,
                  production_tonnage: shiftData.tonnage,
                  remark: shiftData.remark,
                  is_first_in_shift: idx === 0,
                  shift_row_count: shiftData.models.length
                })
              })
            }
          })
        })
      })

      this.defectColumns = Array.from(defectSet).sort()
      this.tableData = tableData
      this.generateSpanArr()
    },

    generateSpanArr() {
      // 只需要为合并列计算 spanArr
      // 1. 工序合并 (列0)
      this.spanArrProc = []
      let contactDotProc = 0
      
      // 2. 班次、合计、生产吨位合并 (列1, 4, 5)
      this.spanArrShift = []
      
      this.tableData.forEach((row, index) => {
        if (index === 0) {
          this.spanArrProc.push(1)
          contactDotProc = 0
        } else {
          if (row.process_name === this.tableData[index - 1].process_name && row.date === this.tableData[index - 1].date) {
            this.spanArrProc[contactDotProc] += 1
            this.spanArrProc.push(0)
          } else {
            this.spanArrProc.push(1)
            contactDotProc = index
          }
        }

        // 班次合并逻辑由 row.shift_row_count 和 row.is_first_in_shift 直接提供
        if (row.is_first_in_shift) {
          this.spanArrShift.push(row.shift_row_count)
        } else {
          this.spanArrShift.push(0)
        }
      })
    },

    objectSpanMethod({ row, column, rowIndex, columnIndex }) {
      const defectLen = this.defectColumns.length
      // 工序合并
      if (columnIndex === 0) {
        const _row = this.spanArrProc[rowIndex]
        const _col = _row > 0 ? 1 : 0
        return { rowspan: _row, colspan: _col }
      }
      
      // 班次 (1), 合计 (3 + defectLen), 生产吨位 (4 + defectLen) 合并
      if (columnIndex === 1 || columnIndex === (3 + defectLen) || columnIndex === (4 + defectLen)) {
        const _row = this.spanArrShift[rowIndex]
        const _col = _row > 0 ? 1 : 0
        return { rowspan: _row, colspan: _col }
      }
    },

    handleSearch() {
      this.loadData()
    },

    handleReset() {
      this.dateRange = []
      this.query = { check_start_date: '', check_end_date: '' }
      this.loadData()
    },

    handleRefresh() {
      this.loadData()
    },

    handleExport() {
      this.$message.info('导出功能开发中...')
    },

    formatNumber(num) {
      if (num === null || num === undefined) return '0.000'
      return Number(num).toFixed(3)
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
  background: linear-gradient(90deg, #3b82f6, #ef4444);
}

.report-title {
  color: #60a5fa;
  font-size: 20px;
  font-weight: 800;
  margin: 0;
  letter-spacing: 2px;
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

.pro-table {
  border-radius: 8px;
}

.info-group {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.info-sep {
  color: #cbd5e1;
  font-weight: 300;
}

.info-val {
  color: #334155;
  font-weight: 500;
}

.model-highlight {
  color: #1e40af;
  font-weight: 700;
}

.defect-num {
  color: #ef4444;
  font-weight: 700;
}

.total-num-small {
  font-weight: 800;
  color: #1e293b;
  background: #f1f5f9;
  padding: 2px 8px;
  border-radius: 4px;
}

.tonnage-container {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 2px;
}

.tonnage-val {
  font-family: 'JetBrains Mono', monospace;
  font-size: 16px;
  font-weight: 700;
  color: #059669;
}

.unit {
  font-size: 11px;
  color: #94a3b8;
}

:deep(.el-table .cell) {
  padding: 8px 4px;
}

:deep(.el-table__row:hover) {
  background-color: #f8fafc !important;
}

/* 固定列阴影优化 */
:deep(.el-table__fixed) {
  height: 100% !important;
}
</style>
