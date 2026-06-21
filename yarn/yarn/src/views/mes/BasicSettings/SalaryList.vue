<template>
  <div class="salary-dashboard">
    <section class="salary-dashboard__hero">
      <div class="salary-dashboard__hero-text">
        <h1 class="salary-dashboard__title">月度工资汇总</h1>
        <p class="salary-dashboard__subtitle">
          按员工、按月份筛选 · 区间自动汇总 · 支持
          <span class="salary-dashboard__accent">单人单月</span> 与
          <span class="salary-dashboard__accent">多人跨月</span> 对比
        </p>
      </div>
      <div class="salary-dashboard__hero-badge">MES / 工资</div>
    </section>

    <el-row :gutter="16" class="salary-dashboard__kpis">
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--sum">
          <div class="kpi-card__label">筛选范围内工资合计</div>
          <div class="kpi-card__value">{{ fmtMoney(summary.total_wage_sum) }}</div>
          <div class="kpi-card__hint">与下方条件一致的全量汇总</div>
        </div>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--users">
          <div class="kpi-card__label">涉及人数</div>
          <div class="kpi-card__value">{{ summary.distinct_user_count ?? '—' }}</div>
          <div class="kpi-card__hint">去重后的员工数</div>
        </div>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--rows">
          <div class="kpi-card__label">工资记录条数</div>
          <div class="kpi-card__value">{{ summary.record_count ?? '—' }}</div>
          <div class="kpi-card__hint">每人每月一条</div>
        </div>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--avg">
          <div class="kpi-card__label">人均（估算）</div>
          <div class="kpi-card__value">{{ avgPerUser }}</div>
          <div class="kpi-card__hint">合计 ÷ 人数</div>
        </div>
      </el-col>
    </el-row>

    <el-card class="salary-dashboard__filter" shadow="never">
      <div class="filter-toolbar">
        <el-form :model="query" class="filter-form" inline @submit.prevent>
          <el-form-item label="员工姓名">
            <el-input
              v-model="query.username"
              clearable
              placeholder="支持模糊匹配"
              style="width: 160px"
              @keyup.enter="onSearch"
            />
          </el-form-item>
          <el-form-item label="统计月份">
            <el-date-picker
              v-model="periodRange"
              type="monthrange"
              unlink-panels
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              value-format="YYYY-MM"
              style="width: 260px"
            />
          </el-form-item>
          <el-form-item label="或指定">
            <el-select
              v-model="query.year"
              clearable
              placeholder="年"
              style="width: 100px"
              @change="onYearClearMonth"
            >
              <el-option v-for="y in yearOptions" :key="y" :label="y + '年'" :value="String(y)" />
            </el-select>
            <el-select
              v-model="query.month"
              clearable
              placeholder="月"
              :disabled="!query.year"
              style="width: 88px; margin-left: 8px"
            >
              <el-option
                v-for="m in 12"
                :key="m"
                :label="m + '月'"
                :value="String(m).padStart(2, '0')"
              />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="onSearch">查询</el-button>
            <el-button @click="onReset">重置</el-button>
            <el-button-group class="quick-presets">
              <el-button @click="presetMonths(1)">近1月</el-button>
              <el-button @click="presetMonths(3)">近3月</el-button>
              <el-button @click="presetMonths(6)">近6月</el-button>
            </el-button-group>
          </el-form-item>
        </el-form>
        <div class="filter-hint">
          <el-icon><InfoFilled /></el-icon>
          优先使用「统计月份」区间；若未选区间，则按右侧「年+月」精确到单月（可不选月表示整年）。
        </div>
      </div>
    </el-card>

    <el-row :gutter="16" class="salary-dashboard__main">
      <el-col :xs="24" :lg="10">
        <el-card shadow="never" class="chart-card">
          <template #header>
            <span class="chart-card__title">月度工资趋势</span>
            <span class="chart-card__sub">（当前筛选条件下各月汇总）</span>
          </template>
          <div ref="trendChartRef" class="trend-chart"></div>
        </el-card>
      </el-col>
      <el-col :xs="24" :lg="14">
        <el-card shadow="never" class="table-card">
          <template #header>
            <div class="table-card__head">
              <span>明细列表</span>
              <div class="table-card__actions">
                <span class="table-card__hint">汇总由报工生成；维护单价请去「计件定额表」</span>
                <el-button type="primary" plain size="small" @click="goPieceWageSettings">
                  计件单价设置
                </el-button>
              </div>
            </div>
          </template>
          <el-table
            ref="tableDataRefss"
            :data="tableData"
            border
            stripe
            class="salary-table"
            v-loading="loading"
            :row-key="getRowKeys"
            max-height="480"
            empty-text="暂无数据，请调整筛选或确认已生成当月工资"
            @selection-change="handleSelectionChange"
          >
            <el-table-column type="selection" width="42" align="center" :reserve-selection="true" />
            <el-table-column
              type="index"
              :index="handleIndex"
              label="#"
              width="52"
              align="center"
            />
            <el-table-column prop="name" label="员工" min-width="100" show-overflow-tooltip />
            <el-table-column label="工资" min-width="110" align="right">
              <template #default="{ row }">
                <span class="num-strong">{{ fmtMoney(row.wage) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="所属年月" min-width="110" align="center">
              <template #default="{ row }">
                {{ formatYm(row) }}
              </template>
            </el-table-column>
            <el-table-column
              prop="update_time"
              label="更新时间"
              min-width="160"
              show-overflow-tooltip
            />
            <el-table-column fixed="right" label="操作" width="100" align="center">
              <template #default="{ row }">
                <el-button type="primary" link @click="ViewproductionPlan(row)">详情</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination
            class="salary-pagination"
            :page-sizes="[20, 50, 100, 200]"
            :current-page="query.page"
            :page-size="query.limit"
            layout="total, sizes, prev, pager, next, jumper"
            :total="total"
            background
            @size-change="handleSizeChange"
            @current-change="handleCurrentChange"
          />
        </el-card>
      </el-col>
    </el-row>

    <el-dialog
      v-if="processVisibleGoods"
      v-model="processVisibleGoods"
      title="工资详情"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <SalaryDetails :view="view" v-model:is-show="processVisibleGoods" />
    </el-dialog>
    <router-view />
  </div>
</template>

<script>
import * as echarts from 'echarts/core'
import { BarChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, TitleComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { InfoFilled } from '@element-plus/icons-vue'
import { list } from '@/api/mes/SalaryList/SalaryList'
import SalaryDetails from './components/SalaryDetails.vue'

echarts.use([BarChart, GridComponent, TooltipComponent, TitleComponent, CanvasRenderer])

const pad2 = (n) => String(n).padStart(2, '0')
const ymFromDate = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`

export default {
  name: 'SalaryList',
  components: {
    SalaryDetails,
    InfoFilled
  },
  data() {
    const y = new Date().getFullYear()
    return {
      view: {},
      selectList: [],
      processVisibleGoods: false,
      periodRange: null,
      tableData: [],
      total: 0,
      query: {
        page: 1,
        limit: 20,
        username: '',
        year: '',
        month: '',
        period_start: '',
        period_end: ''
      },
      summary: {
        total_wage_sum: 0,
        record_count: 0,
        distinct_user_count: 0,
        monthly_trend: []
      },
      trendChart: null,
      loading: false,
      yearOptions: [y, y - 1, y - 2, y - 3]
    }
  },
  computed: {
    avgPerUser() {
      const u = this.summary.distinct_user_count
      const t = Number(this.summary.total_wage_sum)
      if (!u || u <= 0 || !Number.isFinite(t)) return '—'
      return this.fmtMoney(t / u)
    }
  },
  activated() {
    this.list()
  },
  beforeUnmount() {
    this.disposeChart()
    window.removeEventListener('resize', this.onResizeChart)
  },
  methods: {
    fmtMoney(v) {
      if (v === null || v === undefined || v === '') return '—'
      const n = Number(v)
      if (!Number.isFinite(n)) return '—'
      return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    formatYm(row) {
      if (!row) return '—'
      const y = row.year ?? ''
      const m = row.month != null ? String(row.month).padStart(2, '0') : ''
      if (!y && !m) return '—'
      return `${y}-${m}`
    },
    getRowKeys(row) {
      return row.id
    },
    handleSelectionChange(val) {
      this.selectList = val
    },
    onResizeChart() {
      if (this.trendChart) this.trendChart.resize()
    },
    disposeChart() {
      if (this.trendChart) {
        this.trendChart.dispose()
        this.trendChart = null
      }
    },
    renderTrendChart() {
      this.disposeChart()
      const el = this.$refs.trendChartRef
      if (!el) return
      const trend = Array.isArray(this.summary.monthly_trend) ? this.summary.monthly_trend : []
      const x = trend.map((t) => t.ym)
      const series = trend.map((t) => t.total)
      this.trendChart = echarts.init(el)
      this.trendChart.setOption({
        color: ['#409eff'],
        grid: { left: 48, right: 16, top: 28, bottom: 40 },
        title: {
          text: trend.length ? '' : '无数据',
          left: 'center',
          top: 'middle',
          textStyle: { color: '#909399', fontSize: 14, fontWeight: 'normal' }
        },
        tooltip: {
          trigger: 'axis',
          valueFormatter: (v) => (Number.isFinite(Number(v)) ? Number(v).toFixed(2) : v)
        },
        xAxis: {
          type: 'category',
          data: x,
          axisLabel: { rotate: x.length > 8 ? 35 : 0, fontSize: 11 }
        },
        yAxis: {
          type: 'value',
          axisLabel: { formatter: (v) => (v >= 10000 ? (v / 10000).toFixed(1) + '万' : v) }
        },
        series: [
          {
            name: '工资合计',
            type: 'bar',
            data: series,
            barMaxWidth: 36,
            itemStyle: { borderRadius: [4, 4, 0, 0] }
          }
        ]
      })
      window.addEventListener('resize', this.onResizeChart)
    },
    buildQueryPayload() {
      const q = { ...this.query }
      if (Array.isArray(this.periodRange) && this.periodRange.length === 2) {
        q.period_start = this.periodRange[0]
        q.period_end = this.periodRange[1]
        q.year = ''
        q.month = ''
      } else {
        q.period_start = ''
        q.period_end = ''
      }
      return q
    },
    async list() {
      this.loading = true
      try {
        const payload = this.buildQueryPayload()
        const resp = await list(payload)
        const d = resp.data || {}
        this.tableData = d.list || []
        this.total = d.count != null ? d.count : 0
        if (d.summary) {
          this.summary = {
            total_wage_sum: d.summary.total_wage_sum ?? 0,
            record_count: d.summary.record_count ?? 0,
            distinct_user_count: d.summary.distinct_user_count ?? 0,
            monthly_trend: d.summary.monthly_trend || []
          }
        }
        await this.$nextTick()
        this.renderTrendChart()
      } finally {
        this.loading = false
      }
    },
    onYearClearMonth() {
      if (!this.query.year) this.query.month = ''
    },
    onSearch() {
      this.query.page = 1
      this.list()
    },
    onReset() {
      this.query = {
        page: 1,
        limit: 20,
        username: '',
        year: '',
        month: '',
        period_start: '',
        period_end: ''
      }
      this.periodRange = null
      this.list()
    },
    presetMonths(n) {
      const end = new Date()
      const start = new Date()
      start.setMonth(start.getMonth() - (n - 1))
      this.periodRange = [ymFromDate(start), ymFromDate(end)]
      this.query.year = ''
      this.query.month = ''
      this.query.page = 1
      this.list()
    },
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    handleSizeChange(val) {
      this.query.limit = val
      this.list()
    },
    handleCurrentChange(val) {
      this.query.page = val
      this.list()
    },
    /** 本页是员工月度汇总；计件单价在「计件定额表」里维护（原错误复用了 SalaryAddition 弹窗） */
    goPieceWageSettings() {
      this.$router.push({ name: 'SalaryManagement' }).catch((err) => {
        if (err?.name === 'NavigationDuplicated') return
        ElMessage.warning('无法跳转：请确认左侧菜单中有「计件定额表」且账号有权限')
      })
    },
    ViewproductionPlan(row) {
      this.view = row
      this.processVisibleGoods = true
    }
  }
}
</script>

<style scoped>
.salary-dashboard {
  padding: 0 4px 24px;
}

.salary-dashboard__hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
  padding: 20px 22px;
  border-radius: 12px;
  background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 48%, #1e1b4b 100%);
  color: #e2e8f0;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.35);
}

.salary-dashboard__title {
  margin: 0 0 8px;
  font-size: 22px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.salary-dashboard__subtitle {
  margin: 0;
  font-size: 13px;
  line-height: 1.6;
  color: #94a3b8;
  max-width: 640px;
}

.salary-dashboard__accent {
  color: #38bdf8;
  font-weight: 500;
}

.salary-dashboard__hero-badge {
  flex-shrink: 0;
  padding: 8px 14px;
  border-radius: 999px;
  font-size: 12px;
  background: rgba(56, 189, 248, 0.15);
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.35);
}

.salary-dashboard__kpis {
  margin-bottom: 16px;
}

.kpi-card {
  border-radius: 10px;
  padding: 16px 18px;
  min-height: 112px;
  border: 1px solid var(--el-border-color-lighter);
  background: var(--el-bg-color);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.kpi-card__label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin-bottom: 8px;
}

.kpi-card__value {
  font-size: 24px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  letter-spacing: 0.02em;
}

.kpi-card__hint {
  margin-top: 8px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.kpi-card--sum {
  border-color: rgba(64, 158, 255, 0.35);
  background: linear-gradient(145deg, rgba(64, 158, 255, 0.08), var(--el-bg-color));
}

.kpi-card--users .kpi-card__value {
  color: #67c23a;
}

.kpi-card--rows .kpi-card__value {
  color: #e6a23c;
}

.kpi-card--avg .kpi-card__value {
  color: #909399;
  font-size: 20px;
}

.salary-dashboard__filter {
  margin-bottom: 16px;
  border-radius: 10px;
}

.filter-toolbar {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.filter-form {
  flex-wrap: wrap;
  align-items: center;
}

.filter-form :deep(.el-form-item) {
  margin-bottom: 8px;
}

.quick-presets {
  margin-left: 8px;
}

.filter-hint {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  line-height: 1.5;
}

.salary-dashboard__main {
  align-items: stretch;
}

.chart-card,
.table-card {
  border-radius: 10px;
  height: 100%;
}

.chart-card__title {
  font-weight: 600;
}

.chart-card__sub {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  font-weight: normal;
}

.trend-chart {
  width: 100%;
  height: 320px;
}

.table-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: 12px;
  flex-wrap: wrap;
}

.table-card__actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.table-card__hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  max-width: 280px;
  line-height: 1.4;
}

.num-strong {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: var(--el-color-primary);
}

.salary-pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
