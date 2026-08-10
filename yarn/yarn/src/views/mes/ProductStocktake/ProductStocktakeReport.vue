<template>
  <!-- [AI-2026-08] 盘点报表圆钢统一名称、厂商、材质、型号及导出列 -->
  <div class="app-container">
    <!-- 视图切换 Tab -->
    <el-card class="filter-card-compact" shadow="never">
      <el-row :gutter="16" align="middle">
        <el-col :span="4">
          <el-radio-group v-model="activeView" size="small" @change="handleViewChange">
            <el-radio-button value="daily">日推算</el-radio-button>
            <el-radio-button value="monthly">月台账</el-radio-button>
          </el-radio-group>
        </el-col>

        <!-- 日推算筛选 -->
        <template v-if="activeView === 'daily'">
          <el-col :span="14">
            <el-form :model="query" class="filter-form-flex" @submit.prevent>
              <el-form-item label="推算日" class="filter-form-item--date">
                <el-date-picker
                  v-model="query.calcDate"
                  type="date"
                  placeholder="推算日（默认今天）"
                  value-format="YYYY-MM-DD"
                  size="small"
                  class="filter-date"
                />
              </el-form-item>
              <el-form-item class="filter-form-item--actions">
                <el-button type="primary" size="small" :loading="loading" @click="handleSearch"
                  >查看</el-button
                >
              </el-form-item>
            </el-form>
            <div class="filter-hint">
              与小程序「智能推算」同源 · 推算日默认今天 · 盘点归属日一般为
              <strong>{{ stocktakeBaselineDate || '昨天' }}</strong>
            </div>
          </el-col>
        </template>

        <!-- 月台账筛选 -->
        <template v-if="activeView === 'monthly'">
          <el-col :span="9">
            <el-form :model="monthlyQuery" class="filter-form-flex" @submit.prevent>
              <el-form-item>
                <el-date-picker
                  v-model="monthlyQuery.yearMonth"
                  type="month"
                  placeholder="选择月份"
                  value-format="YYYY-MM"
                  size="small"
                  class="filter-date"
                />
              </el-form-item>
              <el-form-item>
                <el-select
                  v-model="categoryFilter"
                  placeholder="类别筛选"
                  size="small"
                  style="width: 120px"
                  @change="applyMonthlyFilter"
                >
                  <el-option
                    v-for="opt in categoryOptions"
                    :key="opt.value"
                    :label="opt.label"
                    :value="opt.value"
                  />
                </el-select>
              </el-form-item>
              <el-form-item class="filter-form-item--actions">
                <el-button
                  type="primary"
                  size="small"
                  :loading="loading"
                  @click="handleMonthlySearch"
                  >查询</el-button
                >
              </el-form-item>
            </el-form>
          </el-col>
          <el-col :span="5">
            <el-switch
              v-model="monthlyFilterActive"
              active-text="仅显示有业务"
              inactive-text="显示全部"
              size="small"
              @change="applyMonthlyFilter"
            />
          </el-col>
        </template>

        <el-col :span="activeView === 'daily' ? 6 : 6" style="text-align: right">
          <el-button size="small" @click="handleRefresh">
            <el-icon><Refresh /></el-icon> 刷新
          </el-button>
          <el-button type="success" size="small" @click="handleExport">导出Excel</el-button>
        </el-col>
      </el-row>
    </el-card>

    <!-- 日推算标题 -->
    <div v-if="activeView === 'daily'" class="report-header-pro">
      <h2 class="report-title">日推算看板</h2>
      <div class="report-meta">
        <span class="meta-label">推算日：</span>
        <span class="meta-value">{{ query.calcDate || todayDate }}</span>
        <span class="meta-label" style="margin-left: 16px">小程序盘点基准日：</span>
        <span class="meta-value">{{ stocktakeBaselineDate || '-' }}</span>
        <span v-if="lastRefreshTime" class="meta-label" style="margin-left: 16px">最后刷新：</span>
        <span v-if="lastRefreshTime" class="meta-value">{{ lastRefreshTime }}</span>
      </div>
      <div class="report-meta report-meta--sub">
        <span class="meta-label">昨日库存 → 当日入库/产出 − 当日消耗 → </span>
        <span class="meta-value meta-value--accent">当前库存</span>
        <span v-if="dailySummary.count" class="meta-label" style="margin-left: 16px">
          共 {{ dailySummary.count }} 条 · 库存合计
          {{ formatNumber(dailySummary.totalPredicted) }} t
        </span>
      </div>
    </div>

    <!-- 月台账标题 -->
    <div v-if="activeView === 'monthly'" class="report-header-pro">
      <h2 class="report-title">月度库存台账</h2>
      <div class="report-meta">
        <span class="meta-label">台账期间：</span>
        <span class="meta-value">{{ monthlyQuery.yearMonth || '本月' }}</span>
      </div>
    </div>

    <!-- ============ 日推算表格 ============ -->
    <el-card
      v-if="activeView === 'daily'"
      class="report-table-card report-table-card--daily"
      shadow="never"
    >
      <el-table
        :data="tableData"
        border
        class="pro-table daily-table"
        :header-cell-style="dailyHeaderStyle"
        v-loading="loading"
        :span-method="objectSpanMethod"
        stripe
      >
        <el-table-column prop="category" label="工序" align="center" class-name="col-process" />
        <el-table-column
          label="材料"
          align="center"
          class-name="col-material"
          show-overflow-tooltip
        >
          <template #default="{ row }">
            <div v-if="!row.isPlaceholder" class="material-line">
              <span class="mat-part mat-part--cat">{{
                row.y_material_name || row.material_name || '-'
              }}</span>
              <template v-if="row.y_material_manufacturer">
                <span class="mat-dot">·</span>
                <span class="mat-part">{{ row.y_material_manufacturer }}</span>
              </template>
              <template v-if="row.y_material_spec">
                <span class="mat-dot">·</span>
                <span class="mat-part">{{ row.y_material_spec }}</span>
              </template>
              <template v-if="row.y_material_model">
                <span class="mat-dot">·</span>
                <span class="mat-part mat-part--model">{{ row.y_material_model }}</span>
              </template>
            </div>
            <span v-else class="placeholder-text">-</span>
          </template>
        </el-table-column>
        <el-table-column label="规格" align="center" class-name="col-spec">
          <template #default="{ row }">
            <span v-if="!row.isPlaceholder" class="spec-tag">{{
              row.c_material_spec || row.c_product_spec || '-'
            }}</span>
            <span v-else class="placeholder-text">-</span>
          </template>
        </el-table-column>
        <el-table-column label="昨日库存" align="center" class-name="col-num">
          <template #default="{ row }">
            <span v-if="!row.isPlaceholder" class="num-cell">{{
              formatNumber(calcYesterdayStock(row))
            }}</span>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
        <el-table-column align="center" class-name="col-num">
          <template #header>
            <span class="col-header-multi">当日<br />入库/产出</span>
          </template>
          <template #default="{ row }">
            <span
              v-if="!row.isPlaceholder"
              :class="['num-cell', calcDayInOut(row) > 0 ? 'num-positive' : 'empty']"
            >
              {{ calcDayInOut(row) > 0 ? '+' : '' }}{{ formatNumber(calcDayInOut(row)) }}
            </span>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
        <el-table-column label="当日消耗" align="center" class-name="col-num">
          <template #default="{ row }">
            <el-tooltip
              v-if="!row.isPlaceholder && hasConsumeDetails(row)"
              placement="top"
              effect="dark"
              :show-after="200"
            >
              <template #content>
                <div class="consume-detail-tooltip">
                  <div
                    v-for="(item, idx) in row.today_consume_details"
                    :key="idx"
                    class="consume-detail-tooltip__line"
                  >
                    {{ formatConsumeDetailLine(item) }}
                  </div>
                </div>
              </template>
              <span :class="consumeCellClass(row, true)">
                {{ formatConsumeDisplay(row) }}
              </span>
            </el-tooltip>
            <span v-else-if="!row.isPlaceholder" :class="consumeCellClass(row, false)">
              {{ formatConsumeDisplay(row) }}
            </span>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
        <el-table-column label="当前库存" align="center" class-name="col-current">
          <template #default="{ row }">
            <div v-if="!row.isPlaceholder" class="current-stock">
              <span
                :class="[
                  'current-stock__val',
                  (row.today_predicted || 0) < 0 ? 'num-negative' : ''
                ]"
              >
                {{ formatNumber(row.today_predicted || 0) }}
              </span>
            </div>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
        <el-table-column label="合计" align="center" class-name="col-total">
          <template #default="{ row }">
            <span v-if="!row.isPlaceholder" class="cat-total-num">{{
              formatNumber(row.today_cat_total || row.category_total || 0)
            }}</span>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- ============ 月台账表格 ============ -->
    <el-card v-if="activeView === 'monthly'" class="report-table-card" shadow="never">
      <el-table
        :data="monthlyDisplayRows"
        border
        fit
        style="width: 100%"
        class="pro-table"
        :header-cell-style="{
          'text-align': 'center',
          'background-color': '#f8fafc',
          color: '#1e293b',
          'font-weight': 'bold',
          'font-size': '14px'
        }"
        :cell-style="{ 'text-align': 'center' }"
        v-loading="loading"
        :span-method="monthlySpanMethod"
        row-key="rowKey"
      >
        <!-- 工序（合并） -->
        <el-table-column label="工序" width="128">
          <template #default="{ row }">
            <span v-if="row._groupSpan > 0" class="process-name">{{ row.process_name }}</span>
          </template>
        </el-table-column>

        <!-- 品种 -->
        <el-table-column label="品种" min-width="96">
          <template #default="{ row }">
            <span class="info-val">{{ formatMonthlyMaterialLabel(row) }}</span>
          </template>
        </el-table-column>

        <!-- 物料型号 -->
        <el-table-column label="物料型号" min-width="96">
          <template #default="{ row }">
            <span v-if="row.c_material_spec && row.process_name !== '圆钢'" class="spec-tag">{{
              row.c_material_spec
            }}</span>
            <span v-else class="num-cell empty">-</span>
          </template>
        </el-table-column>

        <!-- 上月结余 -->
        <el-table-column label="上月结余" width="128">
          <template #default="{ row }">
            <span :class="['num-cell', row.begin_qty > 0 ? '' : 'empty']">{{
              formatNumber(row.begin_qty)
            }}</span>
          </template>
        </el-table-column>

        <!-- 累计产量/入库（悬停显示逐日明细） -->
        <el-table-column label="累计产量/入库" width="128">
          <template #default="{ row }">
            <el-popover
              v-if="row.output_total > 0 && row.daily && row.daily.length > 0"
              placement="bottom"
              :width="300"
              trigger="hover"
            >
              <template #reference>
                <span class="num-cell num-positive prod-hover"
                  >+{{ formatNumber(row.production_total) }}</span
                >
              </template>
              <div class="popover-daily">
                <div class="popover-title">逐日产量/入库明细</div>
                <div
                  v-for="d in row.daily.filter((d) => d.output > 0)"
                  :key="d.date"
                  class="popover-row"
                >
                  <span class="popover-date">{{ d.date }}</span>
                  <span class="popover-val">
                    <span class="popover-label-out">产出</span> +{{ formatNumber(d.output) }}
                  </span>
                </div>
              </div>
            </el-popover>
            <span v-else-if="row.production_total > 0" class="num-cell num-positive"
              >+{{ formatNumber(row.production_total) }}</span
            >
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>

        <!-- 累计消耗/出库（悬停显示逐日明细） -->
        <el-table-column label="累计消耗/出库" width="128">
          <template #default="{ row }">
            <el-popover
              v-if="row.total_consume > 0 && row.daily && row.daily.some((d) => d.consume > 0)"
              placement="bottom"
              :width="300"
              trigger="hover"
            >
              <template #reference>
                <span class="num-cell num-negative cons-hover"
                  >-{{ formatNumber(row.total_consume) }}</span
                >
              </template>
              <div class="popover-daily">
                <div class="popover-title">逐日消耗/出库明细</div>
                <div
                  v-for="d in row.daily.filter((d) => d.consume > 0)"
                  :key="d.date"
                  class="popover-row"
                >
                  <span class="popover-date">{{ d.date }}</span>
                  <span class="popover-val">
                    <span class="popover-label-con">消耗</span> -{{ formatNumber(d.consume) }}
                  </span>
                </div>
              </div>
            </el-popover>
            <span v-else-if="row.total_consume > 0" class="num-cell num-negative"
              >-{{ formatNumber(row.total_consume) }}</span
            >
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>

        <!-- 当前库存 -->
        <el-table-column label="当前库存" width="128">
          <template #default="{ row }">
            <div class="total-container">
              <span :class="['tonnage-num', row.current_qty < 0 ? 'num-negative' : '']">{{
                formatNumber(row.current_qty)
              }}</span>
              <span class="unit">t</span>
            </div>
          </template>
        </el-table-column>

        <!-- 合计（合并） -->
        <el-table-column label="合计" width="128">
          <template #default="{ row }">
            <div v-if="row._groupSpan > 0" class="total-container">
              <span class="total-num">{{ formatNumber(row.cat_total || 0) }}</span>
              <span class="unit">t</span>
            </div>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { autoCalc, monthlyLedger } from '@/api/mes/ProductStocktake/ProductStocktake'
import { Refresh } from '@element-plus/icons-vue'
import * as XLSX from 'xlsx'

export default {
  name: 'ProductStocktakeReport',
  components: { Refresh },
  data() {
    return {
      loading: false,
      activeView: 'daily',
      // 月台账
      monthlyQuery: { yearMonth: '' },
      monthlyFilterActive: true,
      categoryFilter: '溯源',
      categoryOptions: [
        { value: 'all', label: '全部类别' },
        { value: '溯源', label: '溯源' },
        { value: '凯亚', label: '凯亚' },
        { value: '易合', label: '易合' },
        { value: '高齿', label: '高齿' },
        { value: '马强济', label: '马强济' },
        { value: 'other', label: '其他' }
      ],
      monthlyRawRows: [],
      monthlyDisplayRows: [],
      monthlyGroupSpan: [],
      // 日推算
      query: { calcDate: '' },
      todayDate: '',
      lastRefreshTime: '',
      rawList: [],
      tableData: [],
      spanArr: [],
      categories: ['圆钢', '断钢', '穿孔', '球化', '矫直', '酸洗', '次成品', '半成品']
    }
  },
  computed: {
    stocktakeBaselineDate() {
      const calcDate = this.query.calcDate || this.todayDate
      if (!calcDate) return ''
      return this.offsetDate(calcDate, -1)
    },
    dailySummary() {
      const rows = (this.tableData || []).filter((r) => !r.isPlaceholder)
      return {
        count: rows.length,
        totalPredicted: rows.reduce((s, r) => s + parseFloat(r.today_predicted || 0), 0)
      }
    }
  },
  created() {
    const today = new Date().toISOString().split('T')[0]
    this.todayDate = today
    this.query.calcDate = today
    // 默认本月
    const now = new Date()
    this.monthlyQuery.yearMonth =
      now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0')
    this.loadData()
  },
  methods: {
    // ---- 视图切换 ----
    handleViewChange(val) {
      if (val === 'monthly') {
        this.loadMonthly()
      } else {
        this.loadData()
      }
    },

    // ---- 今日看板 ----
    async loadData() {
      this.loading = true
      try {
        const calcDate = this.query.calcDate || new Date().toISOString().split('T')[0]
        const res = await autoCalc({ calc_date: calcDate })
        let calcData = []
        if (res.code === 200 && Array.isArray(res.data)) {
          calcData = res.data
        } else if (res.code === 200 && res.data && Array.isArray(res.data.data)) {
          calcData = res.data.data
        }
        this.rawList = calcData
        this.processData()
        this.lastRefreshTime = this.formatDateTime(new Date())
      } catch (error) {
        console.error('加载报表数据失败:', error)
        this.$message.error('加载报表数据失败')
      } finally {
        this.loading = false
      }
    },

    processData() {
      const dataMap = {}
      this.categories.forEach((cat) => {
        dataMap[cat] = {
          items: [],
          subtotals: { yesterdayStock: 0, dayInOut: 0, dayConsume: 0, today_predicted: 0 }
        }
      })
      this.rawList.forEach((item) => {
        if (
          !item.y_material_name &&
          !item.material_name &&
          !item.y_material_spec &&
          !item.y_material_model &&
          !item.material_id
        ) {
          return
        }
        const cat = item.process_name || '其他'
        if (!dataMap[cat]) {
          dataMap[cat] = {
            items: [],
            subtotals: { yesterdayStock: 0, dayInOut: 0, dayConsume: 0, today_predicted: 0 }
          }
        }
        dataMap[cat].items.push(item)
        dataMap[cat].subtotals.yesterdayStock += this.calcYesterdayStock(item)
        dataMap[cat].subtotals.dayInOut += this.calcDayInOut(item)
        dataMap[cat].subtotals.dayConsume += parseFloat(item.today_consume || 0)
        dataMap[cat].subtotals.today_predicted += parseFloat(item.today_predicted || 0)
      })
      const result = []
      const spans = []
      this.categories.forEach((cat) => {
        const group = dataMap[cat]
        if (group.items.length === 0) {
          result.push({
            category: cat,
            y_material_name: '-',
            y_material_manufacturer: '-',
            material_name: '-',
            y_material_spec: '-',
            y_material_model: '-',
            c_material_spec: '-',
            baseline_qty: 0,
            current_qty: 0,
            consume_qty: 0,
            predicted_qty: 0,
            category_total: 0,
            isPlaceholder: true,
            _today_sub: { yesterdayStock: 0, dayInOut: 0, dayConsume: 0, predicted: 0 }
          })
          spans.push(1)
        } else {
          const ts = group.subtotals
          group.items.forEach((item) => {
            result.push({
              ...item,
              category: cat,
              category_total: ts.today_predicted,
              today_cat_total: ts.today_predicted,
              isPlaceholder: false,
              _today_sub: {
                yesterdayStock: ts.yesterdayStock,
                dayInOut: ts.dayInOut,
                dayConsume: ts.dayConsume,
                predicted: ts.today_predicted
              }
            })
          })
          spans.push(group.items.length)
        }
      })
      this.tableData = result
      this.generateSpanArr(spans)
    },

    generateSpanArr(spans) {
      this.spanArr = []
      spans.forEach((count) => {
        for (let i = 0; i < count; i++) {
          this.spanArr.push(i === 0 ? count : 0)
        }
      })
    },

    objectSpanMethod({ row, column, rowIndex, columnIndex }) {
      if (columnIndex === 0 || columnIndex === 7) {
        const _row = this.spanArr[rowIndex]
        const _col = _row > 0 ? 1 : 0
        return { rowspan: _row, colspan: _col }
      }
    },

    handleSearch() {
      this.loadData()
    },
    handleRefresh() {
      if (this.activeView === 'monthly') {
        this.loadMonthly()
      } else {
        this.loadData()
      }
    },
    handleExport() {
      if (this.activeView === 'monthly') {
        this.$message.info('导出功能开发中...')
      } else {
        this.exportDailyExcel()
      }
    },

    offsetDate(dateStr, days) {
      const d = new Date(`${dateStr}T12:00:00`)
      d.setDate(d.getDate() + days)
      return d.toISOString().split('T')[0]
    },

    formatDateTime(date) {
      const pad = (n) => String(n).padStart(2, '0')
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(
        date.getHours()
      )}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    },

    dailyHeaderStyle() {
      return {
        textAlign: 'center',
        backgroundColor: '#f1f5f9',
        color: '#334155',
        fontWeight: '600',
        fontSize: '13px',
        padding: '6px 0'
      }
    },

    calcYesterdayStock(row) {
      return parseFloat(row.begin_today ?? row.predicted_qty ?? row.baseline_qty ?? 0)
    },

    calcDayInOut(row) {
      return parseFloat(row.today_inbound || 0) + parseFloat(row.today_output || 0)
    },

    hasConsumeDetails(row) {
      return Array.isArray(row.today_consume_details) && row.today_consume_details.length > 0
    },

    formatConsumeDisplay(row) {
      const val = parseFloat(row.today_consume || 0)
      if (val > 0) {
        return `-${this.formatNumber(val)}`
      }
      return this.formatNumber(val)
    },

    formatConsumeDetailLine(item) {
      const date = item?.date || '-'
      const qty = this.formatNumber(item?.qty || 0)
      return `${date}  ${qty}`
    },

    consumeCellClass(row, hasDetail) {
      const val = parseFloat(row.today_consume || 0)
      return [
        'num-cell',
        val > 0 ? 'num-negative' : 'empty',
        hasDetail ? 'num-cell--has-detail' : ''
      ]
    },

    formatMonthlyMaterialLabel(row) {
      const name = (row.y_material_name || row.material_name || '').trim()
      const manufacturer = (row.y_material_manufacturer || '').trim()
      const spec = (row.y_material_spec || '').trim()
      const model = (row.y_material_model || '').trim()
      if (row.process_name === '圆钢') {
        return [name, manufacturer, spec, model].filter(Boolean).join('｜') || '-'
      }
      if ((row.process_name || '') !== '圆钢') {
        const cSpec = (row.c_material_spec || '').trim()
        if (cSpec) {
          return cSpec
        }
      }
      if (name && /[｜|]/.test(name)) {
        return name.replace(/\|/g, '｜')
      }
      return spec || model || name || '-'
    },

    formatMaterialLabel(row) {
      const parts = [
        row.y_material_name || row.material_name,
        row.y_material_manufacturer,
        row.y_material_spec,
        row.y_material_model
      ].filter(Boolean)
      if (parts.length >= 4) {
        return parts.join(' | ')
      }
      if (row.material_name && row.material_name.includes('｜')) {
        return [
          row.category || row.material_name.split('｜')[0],
          row.y_material_spec,
          row.y_material_model
        ]
          .filter(Boolean)
          .join(' | ')
      }
      return parts.join(' | ') || '-'
    },

    exportDailyExcel() {
      const rows = (this.tableData || []).filter((r) => !r.isPlaceholder)
      if (!rows.length) {
        this.$message.warning('暂无数据可导出')
        return
      }
      const sheetRows = rows.map((r) => ({
        工序: r.category || r.process_name,
        圆钢名称: r.y_material_name || r.material_name || '',
        圆钢厂商: r.y_material_manufacturer || '',
        圆钢材质: r.y_material_spec || '',
        圆钢型号: r.y_material_model || '',
        工序规格: r.c_material_spec || r.c_product_spec || '',
        昨日库存: this.formatNumber(this.calcYesterdayStock(r)),
        当日入库产出: this.formatNumber(this.calcDayInOut(r)),
        当日消耗: this.formatNumber(r.today_consume || 0),
        当前库存: this.formatNumber(r.today_predicted || 0)
      }))
      const ws = XLSX.utils.json_to_sheet(sheetRows)
      const wb = XLSX.utils.book_new()
      XLSX.utils.book_append_sheet(wb, ws, '日推算')
      XLSX.writeFile(wb, `盘点日推算_${this.query.calcDate || 'today'}.xlsx`)
    },

    // ---- 月台账方法 ----
    handleMonthlySearch() {
      this.loadMonthly()
    },

    async loadMonthly() {
      this.loading = true
      try {
        const res = await monthlyLedger({ year_month: this.monthlyQuery.yearMonth })
        if (res.code === 200 && res.data && Array.isArray(res.data.rows)) {
          this.monthlyRawRows = res.data.rows
        } else {
          this.monthlyRawRows = []
          if (res.data && res.data.error) {
            this.$message.warning(res.data.error)
          }
        }
        this.applyMonthlyFilter()
      } catch (error) {
        console.error('加载月台账失败:', error)
        this.$message.error('加载月台账失败')
      } finally {
        this.loading = false
      }
    },

    applyMonthlyFilter() {
      let rows = [...this.monthlyRawRows]
      // 类别过滤：按 category 字段精确匹配
      if (this.categoryFilter !== 'all') {
        const knownCats = ['溯源', '凯亚', '易合', '高齿', '马强济']
        rows = rows.filter((r) => {
          const cat = r.category || ''
          const name = r.material_name || ''
          if (this.categoryFilter === 'other') {
            return !knownCats.some((c) => cat.startsWith(c) || name.startsWith(c))
          }
          return cat.startsWith(this.categoryFilter) || name.startsWith(this.categoryFilter)
        })
      }
      // 业务过滤
      if (this.monthlyFilterActive) {
        rows = rows.filter((r) => r.has_activity === true || r.has_activity === 1)
      }
      this.buildMonthlyDisplay(rows)
    },

    buildMonthlyDisplay(rows) {
      const processOrder = ['圆钢', '断钢', '穿孔', '球化', '矫直', '酸洗', '次成品', '半成品']
      const groups = {}
      processOrder.forEach((p) => {
        groups[p] = []
      })

      rows.forEach((r) => {
        const p = r.process_name
        if (groups[p]) groups[p].push(r)
      })

      const display = []
      const spans = []
      let idx = 0

      processOrder.forEach((p) => {
        const items = groups[p]
        if (items.length === 0) return

        // 计算该工序合计
        const sub = { current_qty: 0 }
        items.forEach((item) => {
          sub.current_qty += parseFloat(item.current_qty || 0)
        })

        const n = items.length
        items.forEach((item, i) => {
          const prodTotal = parseFloat(item.total_inbound || 0) + parseFloat(item.total_output || 0)
          const outTotal = parseFloat(item.total_output || 0)
          display.push({
            ...item,
            rowKey: 'd_' + idx++,
            begin_qty: parseFloat(item.begin_qty || 0),
            total_inbound: parseFloat(item.total_inbound || 0),
            total_output: parseFloat(item.total_output || 0),
            production_total: prodTotal,
            output_total: outTotal,
            total_consume: parseFloat(item.total_consume || 0),
            current_qty: parseFloat(item.current_qty || 0),
            _groupSpan: i === 0 ? n : 0,
            cat_total: i === 0 ? sub.current_qty : 0
          })
          spans.push(i === 0 ? n : 0)
        })
      })

      this.monthlyGroupSpan = spans
      this.monthlyDisplayRows = display
    },

    monthlySpanMethod({ row, column, rowIndex, columnIndex }) {
      // 工序列（索引0）和合计划（索引7）合并
      if (columnIndex === 0 || columnIndex === 7) {
        const _row = this.monthlyGroupSpan[rowIndex] || 0
        if (_row > 0) return { rowspan: _row, colspan: 1 }
        return { rowspan: 0, colspan: 0 }
      }
      return { rowspan: 1, colspan: 1 }
    },

    // ---- 通用 ----
    formatNumber(num) {
      if (num === null || num === undefined) return '0.000'
      return Number(num).toFixed(3)
    }
  }
}
</script>

<style scoped>
:root {
  --color-primary: #00f0ff;
  --color-success: #00d084;
  --color-warning: #ff6b35;
  --color-error: #ff4757;
  --bg-pro: #0a1628;
}

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

.filter-form-flex {
  display: flex;
  gap: 12px;
  align-items: center;
}

.filter-form-item--date {
  margin-bottom: 0;
}
.filter-form-item--actions {
  margin-bottom: 0;
}
.filter-date {
  width: 240px;
}

.filter-hint {
  margin-top: 6px;
  font-size: 12px;
  color: #94a3b8;
  line-height: 1.4;
}

.filter-hint strong {
  color: #64748b;
  font-weight: 600;
}

.report-meta--sub {
  margin-top: 4px;
}

.meta-value--accent {
  color: #34d399;
  font-weight: 700;
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

.pro-table {
  border-radius: 8px;
}

/* 日推算表格：8 列等比铺满容器，无需横向滚动 */
.report-table-card--daily :deep(.el-card__body) {
  padding: 0;
  overflow: hidden;
}

.daily-table {
  width: 100%;
  table-layout: fixed;
}

:deep(.daily-table .el-table__inner-wrapper),
:deep(.daily-table .el-table__header-wrapper),
:deep(.daily-table .el-table__body-wrapper) {
  width: 100% !important;
  overflow-x: hidden !important;
}

:deep(.daily-table table) {
  width: 100% !important;
  table-layout: fixed;
}

/* 第1列 1/16，第2-7列各 1/8，第8列 3/16（收回第1列让出的一半） */
:deep(.daily-table col:nth-child(1)) {
  width: 6.25%;
}
:deep(.daily-table col:nth-child(2)),
:deep(.daily-table col:nth-child(3)),
:deep(.daily-table col:nth-child(4)),
:deep(.daily-table col:nth-child(5)),
:deep(.daily-table col:nth-child(6)),
:deep(.daily-table col:nth-child(7)) {
  width: 12.5%;
}
:deep(.daily-table col:nth-child(8)) {
  width: 18.75%;
}

:deep(.daily-table .el-table__header th) {
  border-bottom: 2px solid #e2e8f0 !important;
}

:deep(.daily-table .el-table__header .cell) {
  padding: 4px 2px !important;
  line-height: 1.25;
  white-space: normal;
  word-break: keep-all;
}

.col-header-multi {
  display: inline-block;
  line-height: 1.3;
  font-size: 13px;
}

:deep(.daily-table .el-table__body td) {
  padding: 3px 2px !important;
  vertical-align: middle;
}

:deep(.daily-table .el-table__body .cell) {
  padding: 0 2px !important;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
}

:deep(.daily-table .el-table__body .col-process) {
  background-color: #fafbfc;
  font-weight: 600;
  color: #475569;
  font-size: 13px;
}

:deep(.daily-table .el-table__body .col-material .cell) {
  text-align: center;
}

:deep(.daily-table .el-table__body .col-current) {
  background-color: #f0fdf4;
}

:deep(.daily-table .el-table__body .col-total) {
  background-color: #f8fafc;
}

.material-line {
  display: block;
  line-height: 1.3;
  font-size: 13px;
  color: #334155;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mat-part {
  font-weight: 500;
}
.mat-part--cat {
  color: #1e293b;
  font-weight: 600;
}
.mat-part--model {
  color: #0369a1;
  font-weight: 600;
}
.mat-dot {
  color: #cbd5e1;
  user-select: none;
}

:deep(.daily-table .num-cell) {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
  font-weight: 600;
}

:deep(.daily-table .num-cell.empty) {
  color: #cbd5e1;
  font-weight: 400;
}

:deep(.daily-table .spec-tag) {
  font-size: 12px;
  padding: 1px 5px;
}

.current-stock__val {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
  font-weight: 700;
  color: #059669;
}

.cat-total-num {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 18px;
  font-weight: 800;
  color: #0f766e;
  line-height: 1.2;
}

.info-container {
  display: flex;
  justify-content: center;
  width: 100%;
}
.info-group {
  display: flex;
  align-items: center;
  gap: 8px;
}
.info-sep {
  color: #94a3b8;
  font-weight: 300;
  font-size: 14px;
}
.info-val {
  color: #334155;
  font-weight: 500;
}
.model-highlight {
  color: #0369a1;
  font-weight: 700;
}

.spec-tag {
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  background: #e0f2fe;
  color: #0369a1;
  font-weight: 600;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
}

.num-cell {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
  font-weight: 600;
}
.num-cell.empty {
  color: #cbd5e1;
  font-weight: 400;
}
.num-cell--has-detail {
  cursor: help;
  border-bottom: 1px dashed rgba(220, 38, 38, 0.45);
}

.consume-detail-tooltip {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 12px;
  line-height: 1.65;
}
.consume-detail-tooltip__line {
  white-space: nowrap;
}
.num-positive {
  color: #059669;
}
.num-negative {
  color: #dc2626 !important;
}

.tonnage-num {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}

.total-container {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 4px;
}

.total-num {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 24px;
  font-weight: 800;
  color: #00d084;
  text-shadow: 0 0 10px rgba(0, 208, 132, 0.2);
}

.unit {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 600;
}
.placeholder-text {
  color: #cbd5e1;
}

:deep(.daily-table .el-table__row) {
  transition: background-color 0.15s;
}
:deep(.daily-table .el-table__row:hover > td) {
  background-color: #f8fafc !important;
}
:deep(.daily-table .el-table__row:hover > td.col-current) {
  background-color: #ecfdf5 !important;
}

/* 累计产量/入库悬停弹窗 */
.prod-hover {
  cursor: pointer;
  border-bottom: 1px dashed #059669;
}
.cons-hover {
  cursor: pointer;
  border-bottom: 1px dashed #dc2626;
}
.popover-daily {
  max-height: 240px;
  overflow-y: auto;
}
.popover-title {
  font-weight: 700;
  margin-bottom: 8px;
  color: #1e293b;
  font-size: 13px;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 6px;
}
.popover-row {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  font-size: 13px;
  border-bottom: 1px solid #f1f5f9;
}
.popover-date {
  color: #64748b;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
}
.popover-val {
  color: #059669;
  font-weight: 600;
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
}
.popover-label-in {
  color: #2563eb;
}
.popover-label-out {
  color: #059669;
}
.popover-label-con {
  color: #dc2626;
}

.process-name {
  font-weight: 700;
  color: #0f172a;
}
</style>
