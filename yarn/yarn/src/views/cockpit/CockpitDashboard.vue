<template>
  <div
    ref="dashboardRootRef"
    class="dashboard-root"
    :class="{
      'dashboard-root--fullscreen': fullscreenActive || standalone,
      'dashboard-root--standalone': standalone
    }"
  >
  <div ref="viewportRef" class="cockpit-viewport">
    <div class="cockpit-scale-shell" :style="shellStyle">
      <div
        ref="innerRef"
        class="cockpit cockpit--design"
        :class="{ 'cockpit--fullscreen': fullscreenActive || standalone }"
        :style="cockpitTransformStyle"
      >
        <!-- 参观/汇报场景：先总览 KPI，再生产结果，再成本与设备 -->
        <header class="hero-header">
          <div class="hero-header__left">
            <span class="hero-header__mark" aria-hidden="true" />
            <h1 class="hero-header__title">生产总览</h1>
          </div>
          <div class="hero-header__right">
            <button
              v-if="!standalone"
              type="button"
              class="hero-header__fs"
              :aria-pressed="fullscreenActive"
              @click="toggleFullscreen"
            >
              {{ fullscreenActive ? '退出全屏' : '全屏' }}
            </button>
            <span class="hero-header__clock">{{ clock }}</span>
          </div>
        </header>

        <section class="kpi-strip" aria-label="关键指标">
          <div class="kpi-card">
            <div class="kpi-card__label">在制订单</div>
            <div class="kpi-card__value">{{ kpi.inProducing }}</div>
            <div class="kpi-card__hint">状态为生产中</div>
          </div>
          <div class="kpi-card kpi-card--accent">
            <div class="kpi-card__label">设备正常运行</div>
            <div class="kpi-card__value">{{ kpi.deviceOk }}<span class="kpi-card__slash">/{{ kpi.deviceTotal }}</span></div>
            <div class="kpi-card__hint">主数据台账</div>
          </div>
          <div class="kpi-card kpi-card--warn">
            <div class="kpi-card__label">本月不良累计</div>
            <div class="kpi-card__value">{{ fmtNum(kpi.defectTotal) }}</div>
            <div class="kpi-card__hint">报工 + 质检</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-card__label">今日工序产出合计</div>
            <div class="kpi-card__value">{{ fmtNum(kpi.dayOutputSum) }}</div>
            <div class="kpi-card__hint">数采区 TOP 工序汇总</div>
          </div>
        </section>

        <div class="layout-main">
          <section class="panel panel--hero panel--plans">
            <div class="panel__head">
              <h2 class="panel__title">生产执行与交付</h2>
            </div>
            <div class="panel__body panel__body--fixed">
              <table class="data-table data-table--plans">
                <thead>
                  <tr>
                    <th class="col-name">计划名称</th>
                    <th class="col-mat">物料</th>
                    <th class="col-code">单号</th>
                    <th class="col-st">状态</th>
                    <th class="col-pct">进度</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(p, idx) in cockpitPlansRows"
                    :key="p.__pad ? 'plan-pad-' + idx + '-' + rotPlans.tick : (p?.production_plan_code ?? 'x') + '-' + idx + '-' + rotPlans.tick"
                    :class="{ 'tr--pad': p.__pad, 'tr--cockpit-flow': rotPlans.hasMore && !p.__pad }"
                  >
                    <template v-if="p.__pad">
                      <td colspan="5" class="muted tr--pad-cell">&nbsp;</td>
                    </template>
                    <template v-else>
                      <td class="col-name">{{ p?.production_plan_name || '—' }}</td>
                      <td class="col-mat">
                        <div class="plan-mat">
                          <div class="plan-mat__row">
                            <span class="plan-mat__k">圆钢</span>
                            <span class="plan-mat__v">{{ p?.steel_display || '—' }}</span>
                          </div>
                          <div class="plan-mat__row">
                            <span class="plan-mat__k">成品</span>
                            <span class="plan-mat__v">{{ p?.product_display || '—' }}</span>
                          </div>
                        </div>
                      </td>
                      <td class="col-code mono">{{ p?.production_plan_code ?? '—' }}</td>
                      <td class="col-st">
                        <span :class="['badge', planBadgeClass(p?.status)]">{{ p?.status_label ?? '—' }}</span>
                      </td>
                      <td class="col-pct">
                        <div class="pct-cell">
                          <div class="pct-cell__bar">
                            <div class="pct-cell__fill" :style="{ width: Math.min(100, Number(p?.progress_percent) || 0) + '%' }" />
                          </div>
                          <span class="pct-cell__txt">{{ p?.progress_percent ?? 0 }}%</span>
                        </div>
                      </td>
                    </template>
                  </tr>
                  <tr v-if="!cockpitPlansRows.length">
                    <td colspan="5" class="muted">暂无计划数据</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <div class="layout-main__aside">
            <section class="panel panel--defect">
              <div class="panel__head">
                <div>
                  <h2 class="panel__title">质量风险（当月）</h2>
                  <p class="panel__lead">报工 / 质检不良按分类占比（双图）</p>
                </div>
              </div>
              <div class="panel__body panel__body--chart panel__body--defect-split">
                <div class="defect-chart-card">
                  <div class="defect-chart-card__cap">报工不良（按分类）</div>
                  <div ref="defectReportPieRef" class="chart chart--pie chart--pie-sm" />
                </div>
                <div class="defect-chart-card">
                  <div class="defect-chart-card__cap">质检不良（按分类）</div>
                  <div ref="defectQcPieRef" class="chart chart--pie chart--pie-sm" />
                </div>
              </div>
              <ul class="legend-list legend-list--inline legend-list--defect-foot">
                <li>
                  <span class="dot dot--a" />
                  报工合计 {{ fmtNum(board.defect_month_meta?.report_defective_sum) }}
                </li>
                <li>
                  <span class="dot dot--b" />
                  质检合计 {{ fmtNum(board.defect_month_meta?.qc_defective_sum) }}
                </li>
              </ul>
            </section>
          </div>
        </div>

        <div class="layout-bottom">
          <section class="panel panel--energy">
            <div class="panel__head">
              <h2 class="panel__title">工序能耗（成本）</h2>
            </div>
            <div class="energy-split">
              <div class="energy-col">
                <div class="energy-col__cap">昨日 {{ board.energy?.yesterday_label }}</div>
                <div class="panel__body panel__body--fixed panel__body--energy-table">
                  <table class="data-table data-table--dense">
                    <thead>
                      <tr>
                        <th>工序</th>
                        <th>电 (kWh)</th>
                        <th>天然气</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in energyYesterdayRows" :key="'y-' + idx + '-' + (r?.processes_name ?? '')">
                        <td>{{ r?.processes_name ?? '—' }}</td>
                        <td class="mono">{{ fmtEnergyPower(r) }}</td>
                        <td class="mono">{{ fmtNum(r?.gas) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div ref="energyChartYesterdayRef" class="chart chart--bar" />
              </div>
              <div class="energy-col">
                <div class="energy-col__cap">本月 {{ board.energy?.month_label }}</div>
                <div class="panel__body panel__body--fixed panel__body--energy-table">
                  <table class="data-table data-table--dense">
                    <thead>
                      <tr>
                        <th>工序</th>
                        <th>电 (kWh)</th>
                        <th>天然气</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, idx) in energyMonthRows" :key="'m-' + idx + '-' + (r?.processes_name ?? '')">
                        <td>{{ r?.processes_name ?? '—' }}</td>
                        <td class="mono">{{ fmtEnergyPower(r) }}</td>
                        <td class="mono">{{ fmtNum(r?.gas) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div ref="energyChartMonthRef" class="chart chart--bar" />
              </div>
            </div>
          </section>

          <div class="layout-bottom__devices-pulse">
            <section class="panel panel--pulse">
              <div class="panel__head">
                <div>
                  <h2 class="panel__title">现场产出脉冲</h2>
                  <p class="panel__lead">当日报工 TOP 工序，证明产线在动</p>
                </div>
              </div>
              <div class="pulse-row">
                <div v-for="(x, i) in board.realtime_outputs" :key="i" class="pulse-card">
                  <div class="pulse-card__label">{{ x?.processes_name || '—' }}</div>
                  <div class="pulse-card__value">{{ fmtNum(x?.qty) }}</div>
                </div>
              </div>
            </section>

            <section class="panel panel--devices">
              <div class="panel__head">
                <h2 class="panel__title">设备健康</h2>
              </div>
              <div class="panel__body panel__body--fixed">
                <table class="data-table data-table--dense">
                  <thead>
                    <tr>
                      <th>设备名称</th>
                      <th>状态</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(d, idx) in cockpitDeviceRows"
                      :key="d.__pad ? 'dev-pad-' + idx + '-' + rotDevices.tick : (d?.device_code ?? 'x') + '-' + idx + '-' + rotDevices.tick"
                      :class="{ 'tr--pad': d.__pad, 'tr--cockpit-flow': rotDevices.hasMore && !d.__pad }"
                    >
                      <template v-if="d.__pad">
                        <td colspan="2" class="muted tr--pad-cell">&nbsp;</td>
                      </template>
                      <template v-else>
                        <td>{{ d?.device_name || '—' }}</td>
                        <td>
                          <span :class="['badge', d?.status_code === 0 ? 'badge--ok' : 'badge--warn']">
                            {{ d?.status_label ?? '—' }}
                          </span>
                        </td>
                      </template>
                    </tr>
                    <tr v-if="!cockpitDeviceRows.length">
                      <td colspan="2" class="muted">暂无设备数据</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  </div>
  </div>
  <div v-if="standalone && loadError" class="cockpit-error">
    <p class="cockpit-error__text">{{ loadErrorMessage }}</p>
    <button type="button" class="cockpit-error__btn" @click="load">重新连接</button>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import * as echarts from 'echarts'
import { useRotatingWindow } from '@/composables/useRotatingWindow'

const props = defineProps({
  /** 独立大屏 / TV：免登录 Kiosk 接口，默认全屏布局 */
  standalone: {
    type: Boolean,
    default: false
  }
})

/** 驾驶舱表格固定可见行数（设备区按你的要求压到 5～6 行，这里取 6） */
const VISIBLE_COCKPIT_ROWS = 6
const visibleRows = VISIBLE_COCKPIT_ROWS

const board = shallowRef({
  updated_at: '',
  devices: [],
  energy: { yesterday: [], month: [], yesterday_label: '', month_label: '' },
  defect_pie_month: [],
  defect_report_pie_by_category: [],
  defect_qc_pie_by_category: [],
  defect_month_meta: {},
  plans: [],
  realtime_outputs: []
})

const designWidth = 1920

const dashboardRootRef = ref(null)
const viewportRef = ref(null)
const innerRef = ref(null)
const fullscreenActive = ref(false)
const loadError = ref(false)
const loadErrorMessage = ref('数据加载失败')
const scaleFactor = ref(1)
const innerPixelHeight = ref(900)

const shellStyle = computed(() => {
  const s = scaleFactor.value
  const h = innerPixelHeight.value
  return {
    width: `${Math.round(designWidth * s * 1000) / 1000}px`,
    height: `${Math.round(h * s * 1000) / 1000}px`
  }
})

const cockpitTransformStyle = computed(() => ({
  width: `${designWidth}px`,
  transform: `scale(${scaleFactor.value})`,
  transformOrigin: 'top left'
}))

const clock = ref('')
const defectReportPieRef = ref(null)
const defectQcPieRef = ref(null)
const energyChartYesterdayRef = ref(null)
const energyChartMonthRef = ref(null)
let pieReportChart
let pieQcChart
let barChartY
let barChartM
let clockId
let pollId
let resizeObs
let fitDebounceTimer = null
let roViewport = null
let roInner = null

const energyYesterdayRows = computed(() => board.value.energy?.yesterday || [])
const energyMonthRows = computed(() => board.value.energy?.month || [])
const plansList = computed(() => board.value.plans || [])
const devicesList = computed(() => board.value.devices || [])

const rotPlans = useRotatingWindow(plansList, VISIBLE_COCKPIT_ROWS, 4500)
const rotDevices = useRotatingWindow(devicesList, VISIBLE_COCKPIT_ROWS, 3800)

/** 不足固定行数时用占位行垫满，保证驾驶舱表格高度稳定 */
function padCockpitRows(displaySlice, visibleCount) {
  const list = Array.isArray(displaySlice)
    ? displaySlice.filter((x) => x != null && typeof x === 'object' && !Array.isArray(x))
    : []
  if (!list.length) return []
  const out = [...list]
  while (out.length < visibleCount) {
    out.push({ __pad: true })
  }
  return out.slice(0, visibleCount)
}

const cockpitPlansRows = computed(() => padCockpitRows(rotPlans.displayItems.value, VISIBLE_COCKPIT_ROWS))
const cockpitDeviceRows = computed(() => padCockpitRows(rotDevices.displayItems.value, VISIBLE_COCKPIT_ROWS))

const kpi = computed(() => {
  const plans = board.value.plans || []
  const inProducing = plans.filter((p) => p.status === 1).length
  const devs = board.value.devices || []
  const deviceOk = devs.filter((d) => d.status_code === 0).length
  const dm = board.value.defect_month_meta || {}
  const defectTotal =
    (Number(dm.report_defective_sum) || 0) + (Number(dm.qc_defective_sum) || 0)
  const rt = board.value.realtime_outputs || []
  const dayOutputSum = rt.reduce((s, x) => s + (Number(x.qty) || 0), 0)
  return {
    inProducing,
    deviceOk,
    deviceTotal: devs.length,
    defectTotal,
    dayOutputSum
  }
})

function fitDashboard() {
  const vp = viewportRef.value
  const inner = innerRef.value
  if (!vp || !inner) return
  const pad = fullscreenActive.value || props.standalone ? 2 : 4
  const rect = vp.getBoundingClientRect()
  const aw = Math.max(80, rect.width - pad * 2)
  const ah = Math.max(80, rect.height - pad * 2)
  innerPixelHeight.value = inner.offsetHeight || inner.scrollHeight
  const ih = innerPixelHeight.value
  if (ih < 8) return
  const sx = aw / designWidth
  const sy = ah / ih
  /**
   * 默认始终按「可视宽度」缩放（整块横向铺满主内容区，避免两侧大留白）。
   * 仅当按宽缩放后纵向超出极大（小屏仍可读）时才退回 min(sx,sy)，由 .cockpit-viewport 承担纵向滚动。
   */
  let s = sx
  const scaledH = ih * s
  const extremeScrollRatio = fullscreenActive.value || props.standalone ? 6 : 5.2
  if (scaledH > ah * extremeScrollRatio) {
    s = Math.min(sx, sy)
  }
  s = Math.max(0.2, Math.min(s, 8))
  scaleFactor.value = Math.round(s * 10000) / 10000
  nextTick(() => {
    pieReportChart?.resize()
    pieQcChart?.resize()
    barChartY?.resize()
    barChartM?.resize()
  })
}

function scheduleFit() {
  if (fitDebounceTimer) clearTimeout(fitDebounceTimer)
  fitDebounceTimer = setTimeout(() => {
    fitDebounceTimer = null
    fitDashboard()
  }, 80)
}

const onWinResize = () => scheduleFit()

const getFullscreenElement = () =>
  document.fullscreenElement ||
  document.webkitFullscreenElement ||
  document.mozFullScreenElement ||
  null

const syncFullscreenState = () => {
  fullscreenActive.value = Boolean(getFullscreenElement())
  nextTick(() => scheduleFit())
}

const toggleFullscreen = async () => {
  const el = dashboardRootRef.value
  if (!el) return
  try {
    if (getFullscreenElement()) {
      if (document.exitFullscreen) await document.exitFullscreen()
      else if (document.webkitExitFullscreen) await document.webkitExitFullscreen()
      else if (document.mozCancelFullScreen) await document.mozCancelFullScreen()
    } else {
      if (el.requestFullscreen) await el.requestFullscreen()
      else if (el.webkitRequestFullscreen) await el.webkitRequestFullscreen()
      else if (el.mozRequestFullScreen) await el.mozRequestFullScreen()
    }
  } catch (e) {
    console.warn('全屏切换失败', e)
  }
  syncFullscreenState()
}

const fmtNum = (n) => {
  if (n === undefined || n === null || n === '') return '—'
  const x = Number(n)
  if (Number.isNaN(x)) return '—'
  return x.toLocaleString('zh-CN', { maximumFractionDigits: 2 })
}

/** 酸洗工序仅展示用气，电显示为 — */
const fmtEnergyPower = (row) => {
  if (!row || row.show_power === false) return '—'
  return fmtNum(row.power_kwh)
}

const planBadgeClass = (st) => {
  if (st === 2) return 'badge--ok'
  if (st === 1) return 'badge--accent'
  return 'badge--muted'
}

const tickClock = () => {
  const d = new Date()
  clock.value = d.toLocaleString('zh-CN', { hour12: false })
}

const DEFECT_PIE_COLORS = [
  '#3b82f6',
  '#f59e0b',
  '#22c55e',
  '#a855f7',
  '#ec4899',
  '#14b8a6',
  '#eab308',
  '#f97316',
  '#6366f1',
  '#0ea5e9'
]

function buildDefectCategoryPieOption(title, seriesData) {
  const data = Array.isArray(seriesData) ? seriesData : []
  const total = data.reduce((s, x) => s + (Number(x.value) || 0), 0)
  const showLegend = data.length > 1 && data.length <= 10
  return {
    backgroundColor: 'transparent',
    title: title
      ? {
          text: title,
          left: 'center',
          top: 2,
          textStyle: { color: '#bae6fd', fontSize: 12, fontWeight: 600 }
        }
      : { show: false },
    tooltip: {
      trigger: 'item',
      formatter: '{b}: {c} ({d}%)',
      textStyle: { fontSize: 12 }
    },
    legend: showLegend
      ? {
          type: 'scroll',
          orient: 'horizontal',
          bottom: 0,
          textStyle: { color: '#93c5fd', fontSize: 10 },
          pageTextStyle: { color: '#93c5fd' }
        }
      : { show: false },
    series: [
      {
        type: 'pie',
        radius: ['32%', '58%'],
        center: ['50%', '52%'],
        avoidLabelOverlap: true,
        itemStyle: { borderRadius: 5, borderColor: '#071018', borderWidth: 1 },
        label: {
          color: '#e0f2fe',
          fontSize: 11,
          formatter: (p) => (total > 0 ? `${p.name}\n${p.percent.toFixed(0)}%` : '')
        },
        data: total > 0 ? data : [{ name: '无数据', value: 1 }],
        color: total > 0 ? DEFECT_PIE_COLORS : ['rgba(255,255,255,0.08)']
      }
    ]
  }
}

const renderDefectCharts = () => {
  const reportData = board.value.defect_report_pie_by_category || []
  const qcData = board.value.defect_qc_pie_by_category || []
  if (defectReportPieRef.value) {
    if (!pieReportChart) {
      pieReportChart = echarts.init(defectReportPieRef.value)
    }
    pieReportChart.setOption(buildDefectCategoryPieOption('', reportData), true)
  }
  if (defectQcPieRef.value) {
    if (!pieQcChart) {
      pieQcChart = echarts.init(defectQcPieRef.value)
    }
    pieQcChart.setOption(buildDefectCategoryPieOption('', qcData), true)
  }
}

function renderEnergyBar(el, rows, existing) {
  if (!el) return existing
  const safe = Array.isArray(rows)
    ? rows.filter((r) => r != null && typeof r === 'object' && !Array.isArray(r))
    : []
  const names = safe.map((r) => r.processes_name || r.processes_code || '—')
  const power = safe.map((r) =>
    r && r.show_power === false ? 0 : Number(r.power_kwh) || 0
  )
  const gas = safe.map((r) => Number(r.gas) || 0)
  let chart = existing
  if (!chart) {
    chart = echarts.init(el)
  }
  chart.setOption({
    backgroundColor: 'transparent',
    tooltip: { trigger: 'axis' },
    grid: { left: 4, right: 8, top: 22, bottom: 4, containLabel: true },
    legend: {
      data: ['用电', '用气'],
      textStyle: { color: '#e0f2fe', fontSize: 13 },
      top: 0
    },
    xAxis: {
      type: 'category',
      data: names,
      axisLabel: { color: '#e0f2fe', fontSize: 13, rotate: names.some((n) => n.length > 5) ? 22 : 0 }
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: 'rgba(255,255,255,0.08)' } },
      axisLabel: { color: '#e0f2fe', fontSize: 13 }
    },
    series: [
      { name: '用电', type: 'bar', data: power, barMaxWidth: 16, itemStyle: { color: '#38bdf8', borderRadius: [3, 3, 0, 0] } },
      { name: '用气', type: 'bar', data: gas, barMaxWidth: 16, itemStyle: { color: '#a78bfa', borderRadius: [3, 3, 0, 0] } }
    ]
  })
  return chart
}

const renderBarPair = () => {
  const yRows = energyYesterdayRows.value || []
  const mRows = energyMonthRows.value || []
  barChartY = renderEnergyBar(energyChartYesterdayRef.value, yRows, barChartY)
  barChartM = renderEnergyBar(energyChartMonthRef.value, mRows, barChartM)
}

/**
 * 统一接口字段：兼容 data.devices / data.device_list，避免代理或旧版只返回其一
 */
function normalizeDashboardPayload(raw) {
  if (!raw || typeof raw !== 'object') {
    return { ...board.value }
  }
  const plans = Array.isArray(raw.plans) ? raw.plans : []
  let devices = Array.isArray(raw.devices) ? raw.devices : []
  if (!devices.length && Array.isArray(raw.device_list)) {
    devices = raw.device_list.map((d) => ({
      device_name: d?.device_name ?? '',
      device_code: d?.device_code ?? '',
      status_label: d?.device_status ?? d?.status_label ?? '—',
      status_code: Number(d?.status_code ?? 0)
    }))
  }
  const defectReportPieByCategory = Array.isArray(raw.defect_report_pie_by_category)
    ? raw.defect_report_pie_by_category
    : []
  const defectQcPieByCategory = Array.isArray(raw.defect_qc_pie_by_category)
    ? raw.defect_qc_pie_by_category
    : []

  return {
    ...raw,
    plans,
    devices,
    defect_report_pie_by_category: defectReportPieByCategory,
    defect_qc_pie_by_category: defectQcPieByCategory
  }
}

function mapProductionRowToPlan(row) {
  if (!row || typeof row !== 'object') return null
  const st = Number(row.status)
  const stNorm = Number.isFinite(st) ? st : 0
  return {
    production_plan_code: row.production_plan_code ?? '',
    production_plan_name: row.production_plan_name ?? '—',
    status: stNorm,
    status_label: stNorm === 2 ? '已完成' : stNorm === 1 ? '生产中' : '未开始',
    plan_qty: Number(row.plan_num) || 0,
    done_qty: 0,
    progress_percent: stNorm === 2 ? 100 : 0,
    steel_display: row.steel_display ?? '',
    product_display: row.product_display ?? ''
  }
}

/** 聚合接口仍缺计划/设备时，直连生产列表与 getDevice 补齐（与后台兜底互为备份） */
async function mergeSparseDashboardPayload(normalized) {
  if (props.standalone) {
    return normalizeDashboardPayload(normalized)
  }
  const base = normalized && typeof normalized === 'object' ? { ...normalized } : {}
  let plans = Array.isArray(base.plans) ? [...base.plans] : []
  let devices = Array.isArray(base.devices) ? [...base.devices] : []

  if (!plans.length) {
    try {
      const { list: fetchProductionList } = await import(
        '@/api/mes/ProductionOrderList/ProductionOrderList'
      )
      const pr = await fetchProductionList({ page: 1, limit: 50 })
      if (pr?.code === 200 && Array.isArray(pr.data?.list) && pr.data.list.length) {
        plans = pr.data.list.map(mapProductionRowToPlan).filter(Boolean)
      }
    } catch (e) {
      console.warn('[驾驶舱] 生产计划列表兜底失败', e)
    }
  }
  if (!devices.length) {
    try {
      const { getDevice } = await import('@/api/mes/DataDashboard/DataDashboard')
      const dr = await getDevice({})
      if (dr?.code === 200 && dr.data && Array.isArray(dr.data.device_list) && dr.data.device_list.length) {
        devices = dr.data.device_list.map((d) => ({
          device_name: d?.device_name ?? '',
          device_code: d?.device_code ?? '',
          status_label: d?.device_status ?? d?.status_label ?? '—',
          status_code: Number(d?.status_code ?? 0)
        }))
      }
    } catch (e) {
      console.warn('[驾驶舱] getDevice 兜底失败', e)
    }
  }
  return normalizeDashboardPayload({ ...base, plans, devices })
}

const load = async () => {
  try {
    loadError.value = false
    let resp
    if (props.standalone) {
      const { dashboardBoardKiosk } = await import('@/api/mes/DataDashboard/kioskDashboard')
      resp = await dashboardBoardKiosk({})
    } else {
      const { ElMessage } = await import('element-plus')
      const { dashboardBoard } = await import('@/api/mes/DataDashboard/DataDashboard')
      resp = await dashboardBoard({})
      if (resp.code === 200 && resp.data) {
        const normalized = normalizeDashboardPayload(resp.data)
        board.value = await mergeSparseDashboardPayload(normalized)
        await nextTick()
        renderDefectCharts()
        renderBarPair()
        await nextTick()
        scheduleFit()
        return
      }
      ElMessage.warning(resp?.msg || '驾驶舱接口未返回数据')
      return
    }
    if (resp.code === 200 && resp.data) {
      const normalized = normalizeDashboardPayload(resp.data)
      board.value = await mergeSparseDashboardPayload(normalized)
      if (import.meta.env.DEV) {
        const p = board.value.plans?.length ?? 0
        const d = board.value.devices?.length ?? 0
        if (!p || !d) {
          console.warn('[驾驶舱] 本次接口 plans=', p, 'devices=', d, '若本地库有数据仍为空，请确认请求已打到当前后端并已重启 PHP')
        }
      }
      await nextTick()
      renderDefectCharts()
      renderBarPair()
      await nextTick()
      scheduleFit()
    } else {
      const msg = resp?.msg || '驾驶舱接口未返回数据'
      if (props.standalone) {
        loadError.value = true
        loadErrorMessage.value = msg
      }
    }
  } catch (e) {
    console.error(e)
    if (props.standalone) {
      loadError.value = true
      loadErrorMessage.value = '驾驶舱数据加载失败，请检查网络与 Kiosk 密钥'
    } else {
      const { ElMessage } = await import('element-plus')
      ElMessage.error(
        '驾驶舱数据加载失败：请确认已启动后端、Vite 代理指向 gccd_server，并强刷页面(Cmd+Shift+R)'
      )
    }
  }
}

watch(energyYesterdayRows, () => {
  nextTick(() => {
    renderBarPair()
    scheduleFit()
  })
})

watch(energyMonthRows, () => {
  nextTick(() => {
    renderBarPair()
    scheduleFit()
  })
})

watch(fullscreenActive, () => {
  nextTick(() => scheduleFit())
})

onMounted(() => {
  if (props.standalone) {
    fullscreenActive.value = true
  }
  tickClock()
  clockId = setInterval(tickClock, 1000)
  rotPlans.start()
  rotDevices.start()
  load()
  pollId = setInterval(load, 60000)
  // 老旧 WebView（车间电视等）可能没有 ResizeObserver，需降级到 window.resize + 定时兜底
  const hasResizeObserver = typeof ResizeObserver !== 'undefined'
  if (hasResizeObserver) {
    resizeObs = new ResizeObserver(() => {
      pieReportChart?.resize()
      pieQcChart?.resize()
      barChartY?.resize()
      barChartM?.resize()
    })
    nextTick(() => {
      if (defectReportPieRef.value) resizeObs.observe(defectReportPieRef.value)
      if (defectQcPieRef.value) resizeObs.observe(defectQcPieRef.value)
      if (energyChartYesterdayRef.value) resizeObs.observe(energyChartYesterdayRef.value)
      if (energyChartMonthRef.value) resizeObs.observe(energyChartMonthRef.value)
    })
    roViewport = new ResizeObserver(() => scheduleFit())
    roInner = new ResizeObserver(() => scheduleFit())
  }
  window.addEventListener('resize', onWinResize, { passive: true })
  document.addEventListener('fullscreenchange', syncFullscreenState)
  document.addEventListener('webkitfullscreenchange', syncFullscreenState)
  document.addEventListener('mozfullscreenchange', syncFullscreenState)
  nextTick(() => {
    if (roViewport && viewportRef.value) roViewport.observe(viewportRef.value)
    if (roInner && innerRef.value) roInner.observe(innerRef.value)
    scheduleFit()
    requestAnimationFrame(() => scheduleFit())
  })
  // 无 ResizeObserver 时，多次延时重算缩放，保证首屏自适应铺满
  if (!hasResizeObserver) {
    setTimeout(() => scheduleFit(), 400)
    setTimeout(() => scheduleFit(), 1200)
    setTimeout(() => scheduleFit(), 3000)
  }
})

onUnmounted(() => {
  document.removeEventListener('fullscreenchange', syncFullscreenState)
  document.removeEventListener('webkitfullscreenchange', syncFullscreenState)
  document.removeEventListener('mozfullscreenchange', syncFullscreenState)
  window.removeEventListener('resize', onWinResize)
  if (clockId) clearInterval(clockId)
  if (pollId) clearInterval(pollId)
  if (resizeObs) resizeObs.disconnect()
  if (roViewport) roViewport.disconnect()
  if (roInner) roInner.disconnect()
  if (fitDebounceTimer) clearTimeout(fitDebounceTimer)
  if (pieReportChart) {
    pieReportChart.dispose()
    pieReportChart = null
  }
  if (pieQcChart) {
    pieQcChart.dispose()
    pieQcChart = null
  }
  if (barChartY) {
    barChartY.dispose()
    barChartY = null
  }
  if (barChartM) {
    barChartM.dispose()
    barChartM = null
  }
})
</script>

<style scoped>
/* 填满路由区域（与 layout 主内容区高度一致），避免用错 vh 导致缩放失效 */
.dashboard-root {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  width: 100%;
  height: 100%;
  min-height: 0;
  box-sizing: border-box;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-size-adjust: 100%;
}

.dashboard-root--standalone {
  position: fixed;
  inset: 0;
  width: 100%;
  height: 100%;
  max-height: 100dvh;
  background: #04080f;
  z-index: 0;
}

.cockpit-error {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  background: #04080f;
  color: #f0f9ff;
}

.cockpit-error__text {
  margin: 0;
  font-size: 18px;
  color: rgba(255, 255, 255, 0.85);
}

.cockpit-error__btn {
  cursor: pointer;
  padding: 10px 24px;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 600;
  color: #fff;
  background: rgba(56, 189, 248, 0.35);
  border: 1px solid rgba(56, 189, 248, 0.5);
}

.dashboard-root:fullscreen,
.dashboard-root:-webkit-full-screen {
  position: fixed;
  inset: 0;
  width: 100%;
  height: 100%;
  max-height: 100dvh;
  background: #04080f;
}

.dashboard-root--fullscreen .cockpit-viewport {
  padding: max(2px, env(safe-area-inset-top, 0px)) max(2px, env(safe-area-inset-right, 0px))
    max(2px, env(safe-area-inset-bottom, 0px)) max(2px, env(safe-area-inset-left, 0px));
}

.cockpit-viewport {
  width: 100%;
  height: 100%;
  min-height: 0;
  overflow-x: auto;
  overflow-y: auto;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  box-sizing: border-box;
  padding: clamp(4px, 1vw, 12px);
  background: radial-gradient(ellipse 120% 80% at 50% 0%, rgba(14, 116, 144, 0.18), transparent 55%),
    linear-gradient(165deg, #03060c 0%, #0a1628 42%, #050c14 100%);
}

.cockpit-scale-shell {
  position: relative;
  flex-shrink: 0;
  box-sizing: content-box;
}

.cockpit {
  padding: clamp(8px, 1vw, 14px) clamp(10px, 1.2vw, 16px) clamp(10px, 1.2vw, 16px);
  background: linear-gradient(165deg, #070f1a 0%, #0c1f33 48%, #081422 100%);
  color: #f0f9ff;
  box-sizing: border-box;
}

.cockpit--design {
  min-height: unset;
}

.cockpit--fullscreen {
  padding: 8px 12px 10px;
}
.cockpit--fullscreen .hero-header {
  margin-bottom: 8px;
  padding: 8px 12px;
}
.cockpit--fullscreen .kpi-strip {
  margin-bottom: 8px;
}
.cockpit--fullscreen .layout-main {
  margin-bottom: 8px;
}

.hero-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px 14px;
  margin-bottom: clamp(10px, 1.5vw, 14px);
  padding: clamp(10px, 1.5vw, 16px) clamp(12px, 2vw, 18px);
  border-radius: 10px;
  background: linear-gradient(95deg, rgba(14, 165, 233, 0.14), rgba(99, 102, 241, 0.08));
  border: 1px solid rgba(56, 189, 248, 0.28);
}

.hero-header__left {
  display: flex;
  gap: 12px;
  align-items: center;
}

.hero-header__mark {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: conic-gradient(from 200deg, #38bdf8, #6366f1, #22d3ee, #38bdf8);
  box-shadow: 0 0 20px rgba(56, 189, 248, 0.4);
}

.hero-header__title {
  margin: 0;
  font-size: clamp(20px, 1.55vw + 12px, 30px);
  font-weight: 700;
  letter-spacing: 0.06em;
  line-height: 1.2;
  color: #ffffff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.45);
}

.hero-header__right {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: flex-end;
  gap: 10px 12px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.82);
  margin-left: auto;
}

.hero-header__fs {
  flex-shrink: 0;
  cursor: pointer;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  color: #ffffff;
  background: rgba(56, 189, 248, 0.28);
  border: 1px solid rgba(56, 189, 248, 0.45);
  transition: background 0.2s ease, border-color 0.2s ease;
}
.hero-header__fs:hover {
  background: rgba(56, 189, 248, 0.28);
  border-color: rgba(56, 189, 248, 0.55);
}

.hero-header__clock {
  display: block;
  font-size: clamp(15px, 1vw + 12px, 20px);
  font-variant-numeric: tabular-nums;
  color: #ffffff;
  font-weight: 600;
}

.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 168px), 1fr));
  gap: clamp(8px, 1.2vw, 12px);
  margin-bottom: clamp(10px, 1.5vw, 14px);
}

.kpi-card {
  border-radius: 10px;
  padding: clamp(10px, 1.2vw, 14px) clamp(11px, 1.3vw, 14px);
  border: 1px solid rgba(56, 189, 248, 0.22);
  background: rgba(6, 16, 28, 0.82);
}
.kpi-card--accent {
  border-color: rgba(56, 189, 248, 0.35);
  background: linear-gradient(135deg, rgba(14, 165, 233, 0.12), rgba(6, 16, 28, 0.8));
}
.kpi-card--warn {
  border-color: rgba(251, 191, 36, 0.25);
  background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), rgba(6, 16, 28, 0.8));
}

.kpi-card__label {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.68);
  margin-bottom: 6px;
  font-weight: 600;
}
.kpi-card__value {
  font-size: clamp(22px, 1.5vw + 14px, 34px);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: #ffffff;
  line-height: 1.15;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
}
.kpi-card__slash {
  font-size: 17px;
  font-weight: 500;
  opacity: 0.7;
}
.kpi-card__hint {
  margin-top: 6px;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.55);
}

.layout-main {
  display: grid;
  grid-template-columns: minmax(0, 1.22fr) minmax(0, 0.88fr);
  gap: clamp(8px, 1.2vw, 12px);
  margin-bottom: clamp(8px, 1.2vw, 12px);
  align-items: stretch;
  min-height: 0;
}

.layout-main__aside {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 0;
}

.layout-bottom {
  display: grid;
  grid-template-columns: minmax(0, 1.12fr) minmax(0, 0.92fr);
  gap: clamp(8px, 1.2vw, 12px);
  align-items: stretch;
  min-height: 0;
}

.layout-bottom__devices-pulse {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: clamp(8px, 1.2vw, 12px);
  min-width: 0;
  min-height: 0;
  align-items: stretch;
}

.panel__body--energy-table {
  flex: 0 0 auto;
}

.panel {
  border-radius: 10px;
  border: 1px solid rgba(56, 189, 248, 0.16);
  background: rgba(6, 18, 32, 0.72);
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.panel--hero {
  min-height: 0;
}

.panel__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(56, 189, 248, 0.1);
  flex-shrink: 0;
}

.panel__title {
  margin: 0;
  font-size: clamp(16px, 0.75vw + 12px, 20px);
  font-weight: 600;
  color: #f0f9ff;
  line-height: 1.3;
}

.panel__lead {
  margin: 6px 0 0;
  font-size: clamp(13px, 0.45vw + 11px, 16px);
  line-height: 1.45;
  color: rgba(255, 255, 255, 0.62);
  max-width: min(54ch, 100%);
}

.panel__body {
  padding: 8px 10px 10px;
  flex: 1;
  min-height: 0;
}

.panel__body--fixed {
  overflow: hidden;
  flex: 1;
}

.panel__body--chart {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 4px;
  padding-bottom: 8px;
}

.panel--defect {
  flex: 1;
  min-height: 0;
}

.panel--pulse {
  flex: 1;
  min-height: 0;
  min-width: 0;
}

.panel--pulse .pulse-row {
  flex: 1;
  min-height: 0;
  align-content: start;
  overflow: auto;
}

.energy-split {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: clamp(8px, 1.2vw, 12px);
  padding: 0 clamp(8px, 1.2vw, 12px) clamp(8px, 1.2vw, 12px);
}

.energy-col__cap {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 2px 4px;
  font-size: 15px;
  font-weight: 600;
  color: #ffffff;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 15px;
  table-layout: fixed;
}
.data-table th,
.data-table td {
  padding: clamp(6px, 0.9vw, 9px) clamp(7px, 1vw, 11px);
  text-align: left;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.data-table th {
  color: #e0f2fe;
  font-weight: 700;
}
.data-table td {
  color: rgba(255, 255, 255, 0.92);
}

@keyframes cockpit-row-flow {
  from {
    opacity: 0.2;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.data-table .tr--cockpit-flow {
  animation: cockpit-row-flow 0.52s ease-out;
}

.data-table .tr--pad-cell {
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  opacity: 0.35;
}

.data-table--plans .col-name {
  width: 16%;
}
.data-table--plans .col-mat {
  width: 26%;
  white-space: normal;
  vertical-align: top;
  line-height: 1.28;
  font-size: 13px;
}
.data-table--plans .col-code {
  width: 22%;
}
.data-table--plans .col-st {
  width: 12%;
}
.data-table--plans .col-pct {
  width: 24%;
}

.plan-mat {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.plan-mat__row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 6px;
}
.plan-mat__k {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  color: rgba(125, 211, 252, 0.95);
}
.plan-mat__v {
  flex: 1;
  min-width: 0;
  color: rgba(255, 255, 255, 0.88);
  word-break: break-all;
}

.panel__body--defect-split {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px 10px;
  align-items: stretch;
  padding: 6px 8px 4px;
}
.defect-chart-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border-radius: 8px;
  background: rgba(6, 16, 28, 0.45);
  border: 1px solid rgba(56, 189, 248, 0.15);
  overflow: hidden;
}
.defect-chart-card__cap {
  flex-shrink: 0;
  text-align: center;
  font-size: 12px;
  font-weight: 600;
  color: #bae6fd;
  padding: 6px 4px 2px;
}
.chart--pie-sm {
  height: clamp(128px, 18vh, 200px);
  min-height: 0;
  flex: 1;
}
.legend-list--defect-foot {
  padding: 6px 10px 8px;
  justify-content: center;
}

.data-table--dense th,
.data-table--dense td {
  padding: 7px 9px;
  font-size: 14px;
}

.pct-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.pct-cell__bar {
  flex: 1;
  height: 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.1);
  overflow: hidden;
  min-width: 0;
}
.pct-cell__fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, #0ea5e9, #6366f1);
  transition: width 0.35s ease;
}
.pct-cell__txt {
  flex-shrink: 0;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.9);
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.mono {
  font-variant-numeric: tabular-nums;
}

.badge {
  display: inline-block;
  padding: 4px 11px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
}
.badge--ok {
  background: rgba(34, 197, 94, 0.18);
  color: #86efac;
  border: 1px solid rgba(34, 197, 94, 0.32);
}
.badge--warn {
  background: rgba(251, 191, 36, 0.12);
  color: #fcd34d;
  border: 1px solid rgba(251, 191, 36, 0.3);
}
.badge--accent {
  background: rgba(56, 189, 248, 0.14);
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.32);
}
.badge--muted {
  background: rgba(148, 163, 184, 0.1);
  color: #cbd5e1;
}

.chart {
  width: 100%;
}
.chart--pie {
  height: clamp(140px, 20vh, 220px);
  min-height: 132px;
}
.chart--bar {
  height: clamp(110px, 15vh, 170px);
  min-height: 100px;
  margin-top: 4px;
}

.legend-list {
  list-style: none;
  margin: 0;
  padding: 0 6px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.9);
  font-weight: 600;
}
.legend-list--inline {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.legend-list li {
  display: flex;
  align-items: center;
  gap: 6px;
}
.dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}
.dot--a {
  background: #3b82f6;
}
.dot--b {
  background: #f59e0b;
}

.pulse-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 128px), 1fr));
  gap: clamp(6px, 1vw, 10px);
  padding: clamp(8px, 1.2vw, 12px) clamp(10px, 1.4vw, 14px);
}

.pulse-card {
  border-radius: 8px;
  padding: 10px 8px;
  text-align: center;
  background: linear-gradient(160deg, rgba(56, 189, 248, 0.12), rgba(99, 102, 241, 0.05));
  border: 1px solid rgba(56, 189, 248, 0.2);
  overflow: hidden;
}
.pulse-card__label {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.68);
  margin-bottom: 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-weight: 500;
}
.pulse-card__value {
  font-size: clamp(19px, 1.2vw + 12px, 28px);
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: #7dd3fc;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
}

.muted {
  color: rgba(255, 255, 255, 0.55);
  text-align: center;
  font-size: 15px;
}

@media (max-width: 1280px) {
  .layout-main,
  .layout-bottom {
    grid-template-columns: 1fr;
  }
  .layout-bottom__devices-pulse {
    grid-template-columns: 1fr;
  }
  .layout-main__aside {
    flex-direction: row;
    flex-wrap: wrap;
  }
  .layout-main__aside .panel {
    flex: 1 1 min(300px, 100%);
    min-width: 0;
  }
}

@media (max-width: 768px) {
  .hero-header__right {
    width: 100%;
    margin-left: 0;
    justify-content: space-between;
  }
  .data-table th,
  .data-table td {
    white-space: normal;
    word-break: break-word;
  }
  .layout-main__aside {
    flex-direction: column;
  }
}

@media (max-width: 520px) {
  .kpi-strip {
    grid-template-columns: 1fr 1fr;
  }
}

/*
 * 独立大屏 / 车间电视（standalone）强制使用弹性布局，不依赖 CSS Grid。
 * 原因：部分电视自带 WebView 老旧（或谎报 grid 支持），网格会塌成一列。
 * 此处仅作用于 .dashboard-root--standalone，后台首页 #/dashboard 不受影响；
 * 现代浏览器下弹性布局与网格视觉一致。clamp()/min() 同步给固定值兜底老内核。
 */
.dashboard-root--standalone .cockpit-viewport {
  padding: 8px;
}
.dashboard-root--standalone .cockpit {
  padding: 12px 16px 16px;
}

.dashboard-root--standalone .kpi-strip {
  display: flex;
  flex-wrap: wrap;
  margin-left: -6px;
  margin-right: -6px;
  margin-bottom: 12px;
}
.dashboard-root--standalone .kpi-strip > .kpi-card {
  flex: 1 1 0;
  min-width: 180px;
  margin: 0 6px 12px;
}
.dashboard-root--standalone .kpi-card {
  padding: 12px 14px;
}
.dashboard-root--standalone .kpi-card__value {
  font-size: 30px;
}

.dashboard-root--standalone .layout-main {
  display: flex;
  flex-direction: row;
  align-items: stretch;
  margin-bottom: 12px;
}
.dashboard-root--standalone .layout-main > .panel--plans {
  flex: 1.22 1 0;
  min-width: 0;
  margin-right: 12px;
}
.dashboard-root--standalone .layout-main > .layout-main__aside {
  flex: 0.88 1 0;
  min-width: 0;
}
.dashboard-root--standalone .layout-main__aside {
  display: flex;
  flex-direction: column;
}
.dashboard-root--standalone .layout-main__aside > .panel + .panel {
  margin-top: 10px;
}

.dashboard-root--standalone .layout-bottom {
  display: flex;
  flex-direction: row;
  align-items: stretch;
}
.dashboard-root--standalone .layout-bottom > .panel--energy {
  flex: 1.12 1 0;
  min-width: 0;
  margin-right: 12px;
}
.dashboard-root--standalone .layout-bottom > .layout-bottom__devices-pulse {
  flex: 0.92 1 0;
  min-width: 0;
}

.dashboard-root--standalone .layout-bottom__devices-pulse {
  display: flex;
  flex-direction: row;
  align-items: stretch;
}
.dashboard-root--standalone .layout-bottom__devices-pulse > .panel {
  flex: 1 1 0;
  min-width: 0;
}
.dashboard-root--standalone .layout-bottom__devices-pulse > .panel + .panel {
  margin-left: 12px;
}

.dashboard-root--standalone .energy-split {
  display: flex;
  flex-direction: row;
  padding: 0 12px 12px;
}
.dashboard-root--standalone .energy-split > * {
  flex: 1 1 0;
  min-width: 0;
}
.dashboard-root--standalone .energy-split > * + * {
  margin-left: 12px;
}

.dashboard-root--standalone .panel__title {
  font-size: 20px;
}
.dashboard-root--standalone .panel__lead {
  font-size: 14px;
  max-width: 100%;
}
.dashboard-root--standalone .data-table th,
.dashboard-root--standalone .data-table td {
  padding: 7px 10px;
}
</style>
