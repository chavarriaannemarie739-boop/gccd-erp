<template>
  <div class="app-container pickling-page">
    <section class="summary-hero" aria-label="酸洗车间能耗汇总">
      <div class="summary-hero__head">
        <div>
          <h2 class="summary-hero__title">酸洗车间 · 能耗汇总</h2>
          <p class="summary-hero__sub">
            统计区间：
            <span class="summary-hero__range">{{ dateRangeText }}</span>
          </p>
        </div>
        <div class="summary-hero__total">
          <span class="summary-hero__total-label">三项合计</span>
          <span class="summary-hero__total-value">{{ formatTonnage(grandTotal) }}</span>
          <span class="summary-hero__total-unit">公斤</span>
        </div>
      </div>
      <div class="summary-cards">
        <div class="stat-card stat-card--phosphating">
          <div class="stat-card__icon" aria-hidden="true">P</div>
          <div class="stat-card__body">
            <div class="stat-card__label">磷化</div>
            <div class="stat-card__value">
              {{ formatTonnage(phosphating_total) }}
              <span class="stat-card__unit">公斤</span>
            </div>
          </div>
        </div>
        <div class="stat-card stat-card--saponification">
          <div class="stat-card__icon" aria-hidden="true">S</div>
          <div class="stat-card__body">
            <div class="stat-card__label">皂化</div>
            <div class="stat-card__value">
              {{ formatTonnage(saponification_total) }}
              <span class="stat-card__unit">公斤</span>
            </div>
          </div>
        </div>
        <div class="stat-card stat-card--alkali">
          <div class="stat-card__icon" aria-hidden="true">A</div>
          <div class="stat-card__body">
            <div class="stat-card__label">碱</div>
            <div class="stat-card__value">
              {{ formatTonnage(alkali_total) }}
              <span class="stat-card__unit">公斤</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <el-card class="filter-card" shadow="never">
      <el-form class="filter-form" @submit.prevent>
        <el-form-item class="filter-form__date">
          <el-date-picker
            v-model="order_times"
            type="daterange"
            unlink-panels
            clearable
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="default"
            class="filter-form__picker"
            @change="handleDateChange"
          />
        </el-form-item>
        <el-form-item class="filter-form__actions">
          <el-button v-preventReClick type="primary" @click="handleSearch">搜索</el-button>
          <el-button v-preventReClick @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <div class="contenter">
      <el-card>
        <div class="toolbar">
          <div>
            <el-button
              type="danger"
              :disabled="type"
              v-preventReClick
              @click="handleDeleteRecords"
            >
              删除
            </el-button>
          </div>
          <div class="toolbar-gap">
            <el-button type="success" v-preventReClick :loading="exporting" @click="exportTable">
              导出
            </el-button>
          </div>
        </div>
        <el-table
          ref="tableDataRefss"
          :data="tableData"
          border
          fit
          style="width: 100%"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
          v-loading="loading"
          :row-key="getRowKeys"
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" align="center" :reserve-selection="true" width="40" />
          <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
          <el-table-column prop="class" label="班次" show-overflow-tooltip>
            <template #default="{ row }">
              <span v-if="row.class === 0">白班</span>
              <span v-if="row.class === 1">夜班</span>
            </template>
          </el-table-column>
          <el-table-column prop="phosphating" label="磷化公斤数" show-overflow-tooltip />
          <el-table-column prop="saponification" label="皂化公斤数" show-overflow-tooltip />
          <el-table-column prop="alkali" label="碱公斤数" show-overflow-tooltip />
          <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
        </el-table>
        <el-pagination
          style="margin-top: 10px"
          :page-sizes="[30, 60, 100, 200]"
          :current-page="query.page"
          :page-size="query.limit"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          background
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </el-card>
    </div>
    <router-view />
  </div>
</template>

<script>
import * as XLSX from 'xlsx'
import {
  powerlist,
  deletePowerRecord as deletePowerRecordApi,
  getPowerConsumptionStats
} from '@/api/mes/EnergyConsumption/EnergyConsumption'

const PICKLING_PROCESS = '酸洗'

export default {
  name: 'PicklingWorkshop',
  components: {},
  data() {
    return {
      type: true,
      selectList: [],
      order_times: null,
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        processes_name: PICKLING_PROCESS,
        create_start_date: '',
        create_end_date: ''
      },
      start_date: '',
      end_date: '',
      saponification_total: 0,
      phosphating_total: 0,
      alkali_total: 0,
      statsDateRange: { start_date: '不限', end_date: '不限' },
      loading: true,
      exporting: false
    }
  },
  computed: {
    grandTotal() {
      return (
        Number(this.phosphating_total || 0) +
        Number(this.saponification_total || 0) +
        Number(this.alkali_total || 0)
      )
    },
    dateRangeText() {
      const s = this.statsDateRange?.start_date ?? '不限'
      const e = this.statsDateRange?.end_date ?? '不限'
      if (s === '不限' && e === '不限') return '全部时间'
      return `${s} ～ ${e}`
    }
  },
  activated() {
    this.refreshStatsAndList()
  },
  methods: {
    formatTonnage(v) {
      const n = Number(v)
      if (Number.isNaN(n)) return '0'
      return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 3 })
    },
    syncDatesToQuery() {
      if (this.order_times && this.order_times.length === 2) {
        const [a, b] = this.order_times
        this.start_date = a
        this.end_date = b
        this.query.create_start_date = a
        this.query.create_end_date = b
      } else {
        this.start_date = ''
        this.end_date = ''
        this.query.create_start_date = ''
        this.query.create_end_date = ''
      }
    },
    async fetchPowerConsumptionStats() {
      const resp = await getPowerConsumptionStats({
        start_date: this.start_date,
        end_date: this.end_date,
        processes_name: PICKLING_PROCESS
      })
      const d = resp.data || {}
      this.alkali_total = d.alkali_total ?? 0
      this.saponification_total = d.saponification_total ?? 0
      this.phosphating_total = d.phosphating_total ?? 0
      if (d.date_range) {
        this.statsDateRange = d.date_range
      }
    },
    async powerlist() {
      this.loading = true
      try {
        const resp = await powerlist(this.query)
        this.tableData = resp.data.list
        this.total = resp.data.count
      } finally {
        this.loading = false
      }
    },
    async refreshStatsAndList() {
      this.syncDatesToQuery()
      await Promise.all([this.fetchPowerConsumptionStats(), this.powerlist()])
    },
    handleDateChange() {
      this.query.page = 1
      this.refreshStatsAndList()
    },
    handleSearch() {
      this.query.page = 1
      this.refreshStatsAndList()
    },
    getRowKeys(row) {
      return row.id
    },
    handleSelectionChange(val) {
      this.selectList = val
      this.type = this.selectList.length === 0
    },
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    handleSizeChange(val) {
      this.query.limit = val
      this.query.page = 1
      this.powerlist()
    },
    handleCurrentChange(val) {
      this.query.page = val
      this.powerlist()
    },
    handleResetting() {
      this.order_times = null
      this.syncDatesToQuery()
      this.statsDateRange = { start_date: '不限', end_date: '不限' }
      this.query.page = 1
      this.refreshStatsAndList()
    },
    async exportTable() {
      this.exporting = true
      try {
        const resp = await powerlist({
          ...this.query,
          page: 1,
          limit: 99999
        })
        const rows = resp.data.list || []
        const sumCol = (key) =>
          rows.reduce((acc, row) => {
            const v = Number(row[key])
            return acc + (Number.isFinite(v) ? v : 0)
          }, 0)
        const totalPhosphating = sumCol('phosphating')
        const totalSaponification = sumCol('saponification')
        const totalAlkali = sumCol('alkali')
        /** 导出用数值：避免千分位，保留最多三位小数 */
        const exportNum = (n) => {
          const x = Number(n)
          if (!Number.isFinite(x)) return ''
          const t = x.toFixed(3).replace(/\.?0+$/, '')
          return t === '' ? '0' : t
        }
        const meta = [['统计区间', this.dateRangeText], []]
        const header = ['序号', '班次', '磷化公斤数', '皂化公斤数', '碱公斤数', '创建时间']
        const dataRows = rows.map((row, index) => [
          index + 1,
          row.class === 0 ? '白班' : row.class === 1 ? '夜班' : '',
          row.phosphating ?? '',
          row.saponification ?? '',
          row.alkali ?? '',
          row.create_time ?? ''
        ])
        const footerRow = [
          '',
          '合计',
          exportNum(totalPhosphating),
          exportNum(totalSaponification),
          exportNum(totalAlkali),
          ''
        ]
        const sheetData = [...meta, header, ...dataRows, footerRow]
        const ws = XLSX.utils.aoa_to_sheet(sheetData)
        const wb = XLSX.utils.book_new()
        XLSX.utils.book_append_sheet(wb, ws, '酸洗车间')
        XLSX.writeFile(wb, `酸洗车间能耗.xlsx`)
        ElMessage.success('导出成功')
      } finally {
        this.exporting = false
      }
    },
    handleDeleteRecords() {
      ElMessageBox.confirm('是否删除该产品', '温馨提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
        .then(async () => {
          const params = {
            AdminToken: '',
            ids: []
          }
          this.selectList.forEach((item) => {
            params.ids.push(item.id)
          })
          await deletePowerRecordApi(params)
          this.refreshStatsAndList()
          ElMessage({
            type: 'success',
            message: '删除成功'
          })
          this.type = true
        })
        .catch(() => {})
    },
    handlePush_down() {
      const tmpPath = {
        path: '/mes/productionPlan/PlanningManagement/ProductionplanpushDown'
      }
      this.$router.push(tmpPath)
    }
  }
}
</script>

<style scoped>
.pickling-page {
  --pickling-bg: linear-gradient(135deg, #0f172a 0%, #1e3a5f 45%, #0c4a6e 100%);
  --pickling-card: rgba(255, 255, 255, 0.08);
  --pickling-border: rgba(255, 255, 255, 0.12);
}

.summary-hero {
  margin-top: 10px;
  padding: 20px 22px 22px;
  border-radius: 14px;
  background: var(--pickling-bg);
  color: #e2e8f0;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.35);
}

.summary-hero__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.summary-hero__title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: #f8fafc;
}

.summary-hero__sub {
  margin: 6px 0 0;
  font-size: 13px;
  color: #94a3b8;
}

.summary-hero__range {
  color: #e2e8f0;
  font-weight: 500;
}

.summary-hero__total {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 10px 16px;
  border-radius: 10px;
  background: rgba(15, 23, 42, 0.45);
  border: 1px solid var(--pickling-border);
}

.summary-hero__total-label {
  font-size: 12px;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.summary-hero__total-value {
  font-size: 26px;
  font-weight: 700;
  color: #fbbf24;
  line-height: 1;
}

.summary-hero__total-unit {
  font-size: 13px;
  color: #cbd5e1;
}

.summary-cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

@media (max-width: 960px) {
  .summary-cards {
    grid-template-columns: 1fr;
  }
}

.stat-card {
  display: flex;
  align-items: stretch;
  gap: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  background: var(--pickling-card);
  border: 1px solid var(--pickling-border);
  backdrop-filter: blur(8px);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.stat-card__icon {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  font-weight: 700;
  font-size: 16px;
  color: #fff;
}

.stat-card--phosphating .stat-card__icon {
  background: linear-gradient(145deg, #3b82f6, #1d4ed8);
}

.stat-card--saponification .stat-card__icon {
  background: linear-gradient(145deg, #a855f7, #6d28d9);
}

.stat-card--alkali .stat-card__icon {
  background: linear-gradient(145deg, #22c55e, #15803d);
}

.stat-card__label {
  font-size: 13px;
  color: #cbd5e1;
  margin-bottom: 6px;
}

.stat-card__value {
  font-size: 22px;
  font-weight: 700;
  color: #f8fafc;
  line-height: 1.2;
}

.stat-card__unit {
  margin-left: 4px;
  font-size: 13px;
  font-weight: 500;
  color: #94a3b8;
}

.filter-card {
  margin-top: 14px;
  border-radius: 10px;
}

.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
}

.filter-form__date {
  margin-bottom: 0;
}

.filter-form__picker {
  width: 280px;
}

.filter-form__actions {
  margin-bottom: 0;
}

.contenter {
  margin-top: 14px;
}

.toolbar {
  margin-bottom: 20px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 8px;
}

.toolbar-gap {
  margin-left: 12px;
}
</style>
