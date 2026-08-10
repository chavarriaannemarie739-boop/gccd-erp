<template>
  <div class="energy-dashboard">
    <section class="energy-dashboard__hero">
      <div class="energy-dashboard__hero-text">
        <h1 class="energy-dashboard__title">能耗分析看板</h1>
        <p class="energy-dashboard__subtitle">
          基于抄表记录（<span class="energy-dashboard__accent">工序</span> /
          <span class="energy-dashboard__accent">设备</span>）双维度汇总 · 今日
          {{ meta.stat_date || '—' }} · 当月 {{ meta.stat_month || '—' }}
        </p>
      </div>
      <div class="energy-dashboard__hero-badge">
        <span>气体 / 电力</span>
      </div>
    </section>

    <el-row :gutter="16" class="energy-dashboard__kpis">
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--gas-today">
          <div class="kpi-card__label">当日气体消耗</div>
          <div class="kpi-card__value">{{ fmt(Goods.today_gas) }}</div>
          <div class="kpi-card__hint">单位：抄表累计差值</div>
        </div>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--power-today">
          <div class="kpi-card__label">当日电力消耗</div>
          <div class="kpi-card__value">{{ fmt(Goods.today_power) }}</div>
          <div class="kpi-card__hint">单位：抄表累计差值</div>
        </div>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--gas-month">
          <div class="kpi-card__label">当月气体消耗</div>
          <div class="kpi-card__value">{{ fmt(Goods.month_gas) }}</div>
          <div class="kpi-card__hint">本月累计</div>
        </div>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="6">
        <div class="kpi-card kpi-card--power-month">
          <div class="kpi-card__label">当月电力消耗</div>
          <div class="kpi-card__value">{{ fmt(Goods.month_power) }}</div>
          <div class="kpi-card__hint">本月累计</div>
        </div>
      </el-col>
    </el-row>

    <el-card class="energy-dashboard__tabs-card" shadow="never">
      <el-tabs v-model="dimensionTab" class="energy-tabs" @tab-change="onTabChange">
        <el-tab-pane label="工序维度" name="process">
          <p class="energy-dashboard__dim-desc">
            按 <strong>工序编码</strong> 汇总当日 / 当月气体与电力消耗，并展示
            <strong>工序名称</strong>（来自工序档案）。
          </p>
          <el-row :gutter="16">
            <el-col :xs="24" :lg="12">
              <div class="panel-head">
                <span class="panel-head__tag panel-head__tag--today">当日</span>
                <span class="panel-head__title">分工序能耗</span>
              </div>
              <el-table
                :data="thatDay"
                border
                stripe
                class="energy-table"
                v-loading="loading"
                empty-text="暂无当日分工序数据"
                max-height="420"
              >
                <el-table-column type="index" label="#" width="52" align="center" />
                <el-table-column prop="processes_code" label="工序编码" min-width="110" show-overflow-tooltip />
                <el-table-column prop="processes_name" label="工序名称" min-width="120" show-overflow-tooltip />
                <el-table-column prop="gas_num" label="气体消耗" min-width="110" align="right">
                  <template #default="{ row }">{{ fmt(row.gas_num) }}</template>
                </el-table-column>
                <el-table-column prop="power_num" label="电力消耗" min-width="110" align="right">
                  <template #default="{ row }">{{ fmt(row.power_num) }}</template>
                </el-table-column>
              </el-table>
            </el-col>
            <el-col :xs="24" :lg="12">
              <div class="panel-head">
                <span class="panel-head__tag panel-head__tag--month">当月</span>
                <span class="panel-head__title">分工序能耗</span>
              </div>
              <el-table
                :data="thecurrentMonth"
                border
                stripe
                class="energy-table"
                v-loading="loading"
                empty-text="暂无当月分工序数据"
                max-height="420"
              >
                <el-table-column type="index" label="#" width="52" align="center" />
                <el-table-column prop="processes_code" label="工序编码" min-width="110" show-overflow-tooltip />
                <el-table-column prop="processes_name" label="工序名称" min-width="120" show-overflow-tooltip />
                <el-table-column prop="gas_num" label="气体消耗" min-width="110" align="right">
                  <template #default="{ row }">{{ fmt(row.gas_num) }}</template>
                </el-table-column>
                <el-table-column prop="power_num" label="电力消耗" min-width="110" align="right">
                  <template #default="{ row }">{{ fmt(row.power_num) }}</template>
                </el-table-column>
              </el-table>
            </el-col>
          </el-row>

          <div class="chart-wrap">
            <div class="chart-wrap__head">
              <div>
                <div class="chart-wrap__title">当月工序 · 电力消耗 TOP</div>
                <p class="chart-wrap__desc">
                  按「当月」各工序电力消耗从高到低排列，条形越长用电量越大；悬停可查看数值。
                </p>
              </div>
              <el-tag v-if="chartUsesMock" type="warning" effect="light" size="small">示例数据预览</el-tag>
            </div>
            <el-alert
              v-if="chartUsesMock"
              class="chart-wrap__alert"
              type="info"
              :closable="false"
              show-icon
              title="暂无真实当月数据"
              description="已用示例工序数据展示条形图效果；当「当月分工序」表格有抄表汇总后，本图会自动改为真实 TOP。"
            />
            <div ref="processChartRef" class="chart-wrap__canvas" />
          </div>
        </el-tab-pane>

        <el-tab-pane label="设备维度" name="device">
          <p class="energy-dashboard__dim-desc">
            按 <strong>设备编码</strong> 汇总，展示 <strong>设备名称</strong> 及设备所属
            <strong>工序</strong>（来自设备档案与工序档案）。
          </p>
          <el-row :gutter="16">
            <el-col :xs="24" :lg="12">
              <div class="panel-head">
                <span class="panel-head__tag panel-head__tag--today">当日</span>
                <span class="panel-head__title">分设备能耗</span>
              </div>
              <el-table
                :data="devicesToday"
                border
                stripe
                class="energy-table"
                v-loading="loading"
                empty-text="暂无当日分设备数据"
                max-height="420"
              >
                <el-table-column type="index" label="#" width="52" align="center" />
                <el-table-column prop="device_code" label="设备编码" min-width="130" show-overflow-tooltip />
                <el-table-column prop="device_name" label="设备名称" min-width="120" show-overflow-tooltip />
                <el-table-column prop="processes_name" label="所属工序" min-width="100" show-overflow-tooltip />
                <el-table-column prop="gas_num" label="气体消耗" min-width="100" align="right">
                  <template #default="{ row }">{{ fmt(row.gas_num) }}</template>
                </el-table-column>
                <el-table-column prop="power_num" label="电力消耗" min-width="100" align="right">
                  <template #default="{ row }">{{ fmt(row.power_num) }}</template>
                </el-table-column>
              </el-table>
            </el-col>
            <el-col :xs="24" :lg="12">
              <div class="panel-head">
                <span class="panel-head__tag panel-head__tag--month">当月</span>
                <span class="panel-head__title">分设备能耗</span>
              </div>
              <el-table
                :data="devicesMonth"
                border
                stripe
                class="energy-table"
                v-loading="loading"
                empty-text="暂无当月分设备数据"
                max-height="420"
              >
                <el-table-column type="index" label="#" width="52" align="center" />
                <el-table-column prop="device_code" label="设备编码" min-width="130" show-overflow-tooltip />
                <el-table-column prop="device_name" label="设备名称" min-width="120" show-overflow-tooltip />
                <el-table-column prop="processes_name" label="所属工序" min-width="100" show-overflow-tooltip />
                <el-table-column prop="gas_num" label="气体消耗" min-width="100" align="right">
                  <template #default="{ row }">{{ fmt(row.gas_num) }}</template>
                </el-table-column>
                <el-table-column prop="power_num" label="电力消耗" min-width="100" align="right">
                  <template #default="{ row }">{{ fmt(row.power_num) }}</template>
                </el-table-column>
              </el-table>
            </el-col>
          </el-row>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script>
import * as echarts from 'echarts/core'
import { BarChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent
} from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { powerStat } from '@/api/mes/EnergyConsumption/EnergyConsumption'

echarts.use([BarChart, GridComponent, TooltipComponent, LegendComponent, TitleComponent, CanvasRenderer])

/** 无真实数据时用于预览条形图效果的虚拟工序 TOP（仅前端展示，不参与统计） */
const MOCK_PROCESS_POWER_TOP = [
  { processes_code: 'gx_0014', processes_name: '酸洗', power_num: 18560 },
  { processes_code: 'gx_0012', processes_name: '球化', power_num: 14230 },
  { processes_code: 'gx_0011', processes_name: '穿孔', power_num: 9680 },
  { processes_code: 'gx_0013', processes_name: '矫直', power_num: 8120 },
  { processes_code: 'gx_0010', processes_name: '断钢', power_num: 5340 },
  { processes_code: 'gx_0009', processes_name: '加热', power_num: 3210 }
]

export default {
  name: 'EnergyStatistics',
  data() {
    return {
      loading: true,
      dimensionTab: 'process',
      Goods: {},
      meta: {},
      thatDay: [],
      thecurrentMonth: [],
      devicesToday: [],
      devicesMonth: [],
      processChart: null,
      /** 条形图是否正在使用前端虚拟数据 */
      chartUsesMock: false
    }
  },
  // [AI-2026-07] keep-alive 白名单外首次进入不触发 activated，需 mounted 拉数
  mounted() {
    this.loadData()
  },
  activated() {
    this.loadData()
  },
  beforeUnmount() {
    this.disposeChart()
  },
  methods: {
    fmt(v) {
      if (v === null || v === undefined || v === '') return '—'
      const n = Number(v)
      if (!Number.isFinite(n)) return '—'
      return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 3 })
    },
    disposeChart() {
      window.removeEventListener('resize', this.resizeChart)
      if (this.processChart) {
        this.processChart.dispose()
        this.processChart = null
      }
    },
    async loadData() {
      this.loading = true
      try {
        const resp = await powerStat({})
        const d = resp.data || {}
        this.Goods = d
        this.meta = { stat_date: d.stat_date, stat_month: d.stat_month }
        this.thatDay = Array.isArray(d.processes_today_gas) ? d.processes_today_gas : []
        this.thecurrentMonth = Array.isArray(d.processes_month_gas) ? d.processes_month_gas : []
        this.devicesToday = Array.isArray(d.devices_today) ? d.devices_today : []
        this.devicesMonth = Array.isArray(d.devices_month) ? d.devices_month : []
        await this.$nextTick()
        this.renderProcessChart()
      } finally {
        this.loading = false
      }
    },
    onTabChange() {
      this.$nextTick(() => {
        if (this.dimensionTab === 'process') {
          this.renderProcessChart()
        }
        this.resizeChart()
      })
    },
    resizeChart() {
      if (this.processChart) {
        this.processChart.resize()
      }
    },
    renderProcessChart() {
      this.disposeChart()
      this.chartUsesMock = false
      const el = this.$refs.processChartRef
      if (!el) return

      let raw =
        this.thecurrentMonth && this.thecurrentMonth.length
          ? this.thecurrentMonth
          : this.thatDay && this.thatDay.length
            ? this.thatDay
            : []

      if (!raw.length) {
        raw = MOCK_PROCESS_POWER_TOP
        this.chartUsesMock = true
      }

      const sorted = [...raw].sort(
        (a, b) => (Number(b.power_num) || 0) - (Number(a.power_num) || 0)
      )
      const top = sorted.slice(0, 12)
      const labels = top.map((r) => r.processes_name || r.processes_code || '—')
      const powers = top.map((r) => Number(r.power_num) || 0)

      this.processChart = echarts.init(el)
      this.processChart.setOption({
        color: ['#6366f1'],
        tooltip: {
          trigger: 'axis',
          axisPointer: { type: 'shadow' },
          formatter: (items) => {
            const p = items[0]
            const idx = p.dataIndex
            const row = top[idx]
            const name = row.processes_name || '—'
            const code = row.processes_code || '—'
            const val = this.fmt(row.power_num)
            return `<div style="font-weight:600;margin-bottom:4px">${name}</div>
              <div style="color:#64748b;font-size:12px">工序编码：${code}</div>
              <div style="margin-top:6px">电力消耗：<b style="color:#4f46e5">${val}</b></div>`
          }
        },
        grid: { left: '2%', right: '12%', bottom: '4%', top: '4%', containLabel: true },
        xAxis: {
          type: 'value',
          name: '电力消耗',
          nameTextStyle: { color: '#64748b', fontSize: 11 },
          splitLine: { lineStyle: { type: 'dashed', color: '#e2e8f0' } },
          axisLabel: {
            color: '#64748b',
            formatter: (v) => (v >= 1000 ? `${(v / 1000).toFixed(1)}k` : String(v))
          }
        },
        yAxis: {
          type: 'category',
          data: labels,
          inverse: true,
          axisLine: { show: false },
          axisTick: { show: false },
          axisLabel: {
            color: '#334155',
            fontSize: 12,
            width: 148,
            overflow: 'truncate',
            ellipsis: '…'
          }
        },
        series: [
          {
            name: '电力消耗',
            type: 'bar',
            data: powers,
            barMaxWidth: 26,
            label: {
              show: true,
              position: 'right',
              color: '#475569',
              fontSize: 12,
              formatter: (p) => this.fmt(p.value)
            },
            itemStyle: {
              borderRadius: [0, 8, 8, 0],
              color: {
                type: 'linear',
                x: 0,
                y: 0,
                x2: 1,
                y2: 0,
                colorStops: [
                  { offset: 0, color: '#a5b4fc' },
                  { offset: 1, color: '#4f46e5' }
                ]
              }
            }
          }
        ]
      })
      window.addEventListener('resize', this.resizeChart)
    }
  }
}

</script>

<style scoped lang="scss">
.energy-dashboard {
  min-height: 100%;
  padding-bottom: 24px;
  background: linear-gradient(165deg, #f8fafc 0%, #eef2ff 45%, #f1f5f9 100%);
}

.energy-dashboard__hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 20px 8px;
  flex-wrap: wrap;
}

.energy-dashboard__title {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #0f172a;
}

.energy-dashboard__subtitle {
  margin: 8px 0 0;
  font-size: 13px;
  color: #64748b;
  line-height: 1.6;
  max-width: 720px;
}

.energy-dashboard__accent {
  color: #4f46e5;
  font-weight: 600;
}

.energy-dashboard__hero-badge {
  padding: 8px 14px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  color: #4338ca;
  background: rgba(99, 102, 241, 0.12);
  border: 1px solid rgba(99, 102, 241, 0.25);
  white-space: nowrap;
}

.energy-dashboard__kpis {
  padding: 0 20px 8px;
}

.kpi-card {
  border-radius: 14px;
  padding: 18px 18px 16px;
  color: #fff;
  min-height: 118px;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
  position: relative;
  overflow: hidden;
  margin-bottom: 16px;
}

.kpi-card::after {
  content: '';
  position: absolute;
  right: -20%;
  top: -40%;
  width: 120px;
  height: 120px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.12);
}

.kpi-card__label {
  font-size: 13px;
  opacity: 0.92;
  font-weight: 500;
}

.kpi-card__value {
  margin-top: 10px;
  font-size: 28px;
  font-weight: 800;
  letter-spacing: 0.02em;
  font-variant-numeric: tabular-nums;
}

.kpi-card__hint {
  margin-top: 8px;
  font-size: 11px;
  opacity: 0.75;
}

.kpi-card--gas-today {
  background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
}
.kpi-card--power-today {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}
.kpi-card--gas-month {
  background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
}
.kpi-card--power-month {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.energy-dashboard__tabs-card {
  margin: 0 20px 20px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(8px);
}

:deep(.energy-tabs .el-tabs__header) {
  margin: 0 0 12px;
}

:deep(.energy-tabs .el-tabs__item) {
  font-weight: 600;
  font-size: 15px;
}

.energy-dashboard__dim-desc {
  margin: 0 0 16px;
  padding: 10px 12px;
  font-size: 13px;
  color: #475569;
  line-height: 1.65;
  background: #f1f5f9;
  border-radius: 8px;
  border-left: 3px solid #6366f1;
}

.panel-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.panel-head__tag {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 6px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.panel-head__tag--today {
  background: #dbeafe;
  color: #1d4ed8;
}

.panel-head__tag--month {
  background: #e0e7ff;
  color: #4338ca;
}

.panel-head__title {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.energy-table {
  width: 100%;
  border-radius: 10px;
  overflow: hidden;
}

:deep(.energy-table .el-table__header th) {
  background: #f8fafc !important;
  color: #334155;
  font-weight: 600;
}

.chart-wrap {
  margin-top: 20px;
  padding: 16px;
  border-radius: 12px;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  border: 1px solid #e2e8f0;
}

.chart-wrap__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.chart-wrap__title {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.chart-wrap__desc {
  margin: 6px 0 0;
  font-size: 12px;
  color: #64748b;
  line-height: 1.5;
  max-width: 640px;
}

.chart-wrap__alert {
  margin-bottom: 12px;
}

.chart-wrap__canvas {
  width: 100%;
  height: 380px;
}
</style>
