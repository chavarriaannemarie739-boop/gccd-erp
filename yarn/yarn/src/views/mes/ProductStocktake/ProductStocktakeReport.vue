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
                  @change="onCalcDateChange"
                />
              </el-form-item>
              <el-form-item label="盘点归属日" class="filter-form-item--date">
                <el-date-picker
                  v-model="query.stocktakeDate"
                  type="date"
                  placeholder="提交盘点归属日"
                  value-format="YYYY-MM-DD"
                  size="small"
                  class="filter-date"
                  @change="onStocktakeDateChange"
                />
              </el-form-item>
              <el-form-item class="filter-form-item--actions">
                <el-button type="primary" size="small" :loading="loading" @click="handleSearch"
                  >查看</el-button
                >
              </el-form-item>
            </el-form>
            <div class="filter-hint">
              与小程序「智能推算」同源 · 推算后可改「确认吨数」再提交正式盘点 · 默认归属日
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

        <el-col :span="6" style="text-align: right">
          <el-button size="small" @click="handleRefresh">
            <el-icon><Refresh /></el-icon> 刷新
          </el-button>
          <template v-if="activeView === 'daily'">
            <el-dropdown
              split-button
              type="success"
              size="small"
              @click="exportDailyExcel('current')"
              @command="onExportCommand"
            >
              导出Excel
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="current">导出当前页签</el-dropdown-item>
                  <el-dropdown-item command="all">导出全部</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
          <el-button v-else type="success" size="small" @click="handleExport">导出Excel</el-button>
        </el-col>
      </el-row>
    </el-card>

    <!-- 日推算标题 -->
    <div v-if="activeView === 'daily'" class="report-header-pro">
      <h2 class="report-title">日推算看板</h2>
      <div class="report-meta">
        <span class="meta-label">推算日：</span>
        <span class="meta-value">{{ query.calcDate || todayDate }}</span>
        <span class="meta-label" style="margin-left: 16px">盘点归属日：</span>
        <span class="meta-value">{{ query.stocktakeDate || stocktakeBaselineDate || '-' }}</span>
        <span v-if="lastRefreshTime" class="meta-label" style="margin-left: 16px">最后刷新：</span>
        <span v-if="lastRefreshTime" class="meta-value">{{ lastRefreshTime }}</span>
      </div>
      <div class="report-meta report-meta--sub">
        <span class="meta-label">昨日库存 → 当日入库/产出 − 当日消耗 → </span>
        <span class="meta-value meta-value--accent">当前库存</span>
        <span class="meta-label"> → 确认吨数后提交</span>
        <span v-if="dailySummary.count" class="meta-label" style="margin-left: 16px">
          本页 {{ dailySummary.viewCount }} 条 / 全部 {{ dailySummary.count }} 条 · 本页库存合计
          {{ formatNumber(dailySummary.viewPredicted) }} t · 已改
          {{ dailySummary.dirtyCount }} 条
        </span>
      </div>
    </div>

    <!-- [AI-2026-08] 圆钢材质页签：整表过滤展示 -->
    <div v-if="activeView === 'daily'" class="material-tab-bar">
      <el-radio-group v-model="dailyMaterialTab" size="small">
        <el-radio-button
          v-for="tab in dailyMaterialTabs"
          :key="tab.value"
          :value="tab.value"
        >
          {{ tab.label }}（{{ tabCounts[tab.value] || 0 }}）
        </el-radio-button>
      </el-radio-group>
    </div>

    <!-- [AI-2026-08] 办公室核对提交工具条 -->
    <div v-if="activeView === 'daily'" class="submit-toolbar">
      <div class="submit-toolbar__left">
        <el-button type="primary" size="small" plain @click="openAddRoundSteelDialog">
          新增圆钢
        </el-button>
        <el-button
          type="danger"
          size="small"
          :loading="submitting"
          :disabled="dailySummary.count === 0"
          @click="openSubmitDialog('all')"
        >
          全部提交
        </el-button>
      </div>
      <div class="submit-toolbar__hint">
        页签仅过滤查看；提交仍覆盖该工序全部材质（含其他页签）。无档案型号可「新增圆钢」。
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
        :data="displayTableData"
        border
        class="pro-table daily-table"
        :header-cell-style="dailyHeaderStyle"
        v-loading="loading"
        :span-method="displaySpanMethod"
        stripe
      >
        <el-table-column label="工序" align="center" min-width="96" class-name="col-process">
          <template #default="{ row }">
            <div class="process-cell">
              <div class="process-cell__name">{{ row.category }}</div>
              <el-button
                v-if="!row.isPlaceholder"
                type="warning"
                link
                size="small"
                :loading="submitting"
                @click.stop="openSubmitDialog('process', row.category)"
              >
                提交本工序
              </el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column
          label="材料"
          align="center"
          class-name="col-material"
          show-overflow-tooltip
        >
          <template #default="{ row }">
            <div v-if="!row.isPlaceholder" class="material-line">
              <el-tag v-if="row.is_manual_add" size="small" type="warning" class="manual-add-tag"
                >新增</el-tag
              >
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
        <el-table-column label="成品型号" align="center" class-name="col-spec">
          <template #default="{ row }">
            <span v-if="!row.isPlaceholder" class="spec-tag">{{ displayFinishedModel(row) }}</span>
            <span v-else class="placeholder-text">-</span>
          </template>
        </el-table-column>
        <el-table-column label="成品规格" align="center" class-name="col-spec">
          <template #default="{ row }">
            <span v-if="!row.isPlaceholder" class="spec-tag">{{ displayFinishedSpec(row) }}</span>
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
        <!-- [AI-2026-08] PC 核对提交：确认吨数可编辑 -->
        <el-table-column label="确认吨数" align="center" min-width="150" class-name="col-confirm">
          <template #default="{ row }">
            <div v-if="!row.isPlaceholder" :class="['confirm-qty-cell', row._dirty ? 'is-dirty' : '']">
              <el-input-number
                v-model="row.confirm_qty"
                :precision="3"
                :step="0.1"
                :controls="false"
                size="small"
                class="confirm-qty-input"
                @change="() => onConfirmQtyChange(row)"
              />
              <span class="confirm-sys-hint">系统 {{ formatNumber(row.system_qty) }}</span>
            </div>
            <span v-else class="num-cell empty">-</span>
          </template>
        </el-table-column>
        <el-table-column label="合计" align="center" class-name="col-total">
          <template #default="{ row }">
            <span v-if="!row.isPlaceholder" class="cat-total-num">{{
              formatNumber(displayCatTotal(row.category))
            }}</span>
            <span v-else class="num-cell empty">0.000</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- [AI-2026-08] 新增圆钢：从档案选择 -->
    <el-dialog
      v-model="addRoundDialogVisible"
      title="新增圆钢（从档案选择）"
      width="780px"
      :close-on-click-modal="false"
      destroy-on-close
      @open="onAddRoundDialogOpen"
    >
      <el-form :inline="true" class="add-round-filter" @submit.prevent>
        <el-form-item label="名称">
          <el-input
            v-model="addRoundQuery.material_name"
            clearable
            size="small"
            placeholder="圆钢名称"
            style="width: 120px"
            @keyup.enter="loadRoundSteelArchives"
          />
        </el-form-item>
        <el-form-item label="厂商">
          <el-input
            v-model="addRoundQuery.manufacturer"
            clearable
            size="small"
            placeholder="厂商"
            style="width: 120px"
            @keyup.enter="loadRoundSteelArchives"
          />
        </el-form-item>
        <el-form-item label="材质">
          <el-input
            v-model="addRoundQuery.material_spec"
            clearable
            size="small"
            placeholder="材质"
            style="width: 100px"
            @keyup.enter="loadRoundSteelArchives"
          />
        </el-form-item>
        <el-form-item label="型号">
          <el-input
            v-model="addRoundQuery.material_model"
            clearable
            size="small"
            placeholder="型号"
            style="width: 100px"
            @keyup.enter="loadRoundSteelArchives"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" size="small" :loading="addRoundLoading" @click="loadRoundSteelArchives"
            >搜索</el-button
          >
          <el-button size="small" @click="resetAddRoundQuery">重置</el-button>
        </el-form-item>
      </el-form>
      <el-table
        :data="addRoundList"
        border
        stripe
        height="360"
        v-loading="addRoundLoading"
        highlight-current-row
        @current-change="onAddRoundSelect"
      >
        <el-table-column type="index" width="50" label="#" align="center" />
        <el-table-column prop="material_name" label="圆钢名称" min-width="90" show-overflow-tooltip />
        <el-table-column prop="manufacturer" label="厂商" min-width="90" show-overflow-tooltip />
        <el-table-column prop="material_spec" label="材质" min-width="80" show-overflow-tooltip />
        <el-table-column prop="material_model" label="型号" min-width="80" show-overflow-tooltip />
      </el-table>
      <div class="add-round-pager">
        <el-pagination
          small
          background
          layout="total, prev, pager, next"
          :total="addRoundTotal"
          :page-size="addRoundQuery.limit"
          :current-page="addRoundQuery.page"
          @current-change="onAddRoundPageChange"
        />
      </div>
      <div class="add-round-hint">
        没有该型号？请先到
        <el-button type="primary" link @click="openRoundSteelArchivePage">圆钢档案</el-button>
        新建，再建档完成后回到此弹窗搜索添加。
      </div>
      <template #footer>
        <el-button @click="addRoundDialogVisible = false">取消</el-button>
        <el-button
          type="primary"
          :disabled="!addRoundSelected"
          @click="confirmAddRoundSteel"
        >
          添加到盘点
        </el-button>
      </template>
    </el-dialog>

    <!-- 提交确认弹窗 -->
    <el-dialog
      v-model="submitDialogVisible"
      title="确认提交盘点"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <div class="submit-dialog-body">
        <p>
          盘点归属日：<strong>{{ query.stocktakeDate || '-' }}</strong>
        </p>
        <p>
          提交范围：<strong>{{
            submitMode === 'all' ? '全部工序' : submitProcessName || '-'
          }}</strong>
        </p>
        <p>
          共 <strong>{{ submitPreview.total }}</strong> 条 · 其中已改
          <strong>{{ submitPreview.dirty }}</strong> 条
        </p>
        <p class="submit-dialog-warn">
          将提交该工序全部材质（含其他页签），不仅当前页；同日同工序会覆盖已有盘点记录。
        </p>
        <el-form-item label="备注（可选）" class="submit-remark-item">
          <el-input
            v-model="submitRemark"
            type="textarea"
            :rows="2"
            maxlength="200"
            show-word-limit
            placeholder="如：不良品/废料/涨秤调整说明"
          />
        </el-form-item>
      </div>
      <template #footer>
        <el-button @click="submitDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="confirmSubmit">确认提交</el-button>
      </template>
    </el-dialog>

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

        <el-table-column label="成品型号" min-width="96">
          <template #default="{ row }">
            <span v-if="row.process_name !== '圆钢' && displayFinishedModel(row) !== '-'" class="spec-tag">{{
              displayFinishedModel(row)
            }}</span>
            <span v-else class="num-cell empty">-</span>
          </template>
        </el-table-column>
        <el-table-column label="成品规格" min-width="96">
          <template #default="{ row }">
            <span v-if="row.process_name !== '圆钢' && displayFinishedSpec(row) !== '-'" class="spec-tag">{{
              displayFinishedSpec(row)
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
import {
  autoCalc,
  monthlyLedger,
  submitStocktake
} from '@/api/mes/ProductStocktake/ProductStocktake'
import { list as materialList } from '@/api/mes/MaterialManagement/MaterialManagement'
import { Refresh } from '@element-plus/icons-vue'
import { ElMessageBox } from 'element-plus'
import * as XLSX from 'xlsx'

const round3 = (num) => Math.round((parseFloat(num) || 0) * 1000) / 1000

const emptyAddRoundQuery = () => ({
  page: 1,
  limit: 20,
  material_property: '圆钢',
  material_name: '',
  manufacturer: '',
  material_spec: '',
  material_model: ''
})

const DAILY_MATERIAL_TABS = [
  { value: 'suyuan_bearing', label: '溯源·轴承钢' },
  { value: 'kaiya_20', label: '凯亚·20#' },
  { value: 'other', label: '其他' }
]

const TAB_LABEL_MAP = {
  suyuan_bearing: '溯源·轴承钢',
  kaiya_20: '凯亚·20#',
  other: '其他'
}

/** 规范化材质用于 20# 匹配 */
const normalizeMaterialSpec = (spec) =>
  String(spec || '')
    .trim()
    .replace(/\s+/g, '')
    .toUpperCase()

export default {
  name: 'ProductStocktakeReport',
  components: { Refresh },
  data() {
    return {
      loading: false,
      submitting: false,
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
      query: { calcDate: '', stocktakeDate: '' },
      todayDate: '',
      lastRefreshTime: '',
      rawList: [],
      tableData: [],
      spanArr: [],
      categories: ['圆钢', '断钢', '穿孔', '球化', '矫直', '酸洗', '次成品', '半成品'],
      // [AI-2026-08] 材质页签
      dailyMaterialTab: 'suyuan_bearing',
      dailyMaterialTabs: DAILY_MATERIAL_TABS,
      // [AI-2026-08] PC 核对提交
      submitProcessName: '',
      submitMode: 'process',
      submitDialogVisible: false,
      submitRemark: '',
      submitPreview: { total: 0, dirty: 0 },
      // [AI-2026-08] 新增圆钢行
      addRoundDialogVisible: false,
      addRoundLoading: false,
      addRoundList: [],
      addRoundTotal: 0,
      addRoundSelected: null,
      addRoundQuery: emptyAddRoundQuery()
    }
  },
  computed: {
    stocktakeBaselineDate() {
      const calcDate = this.query.calcDate || this.todayDate
      if (!calcDate) return ''
      return this.offsetDate(calcDate, -1)
    },
    /** 由字段可明确归类的 material_id → 页签（供下游工序回挂） */
    roundSteelTabByMaterialId() {
      const map = {}
      ;(this.tableData || []).forEach((r) => {
        if (r.isPlaceholder) return
        const mid = parseInt(r.material_id, 10) || 0
        if (mid <= 0) return
        const tab = this.classifyByFields(r)
        if (tab !== 'other') {
          map[mid] = tab
        }
      })
      return map
    },
    tabCounts() {
      const counts = { suyuan_bearing: 0, kaiya_20: 0, other: 0 }
      ;(this.tableData || []).forEach((r) => {
        if (r.isPlaceholder) return
        const tab = this.classifyDailyRow(r)
        counts[tab] = (counts[tab] || 0) + 1
      })
      return counts
    },
    displayTableData() {
      const tab = this.dailyMaterialTab
      const out = []
      this.categories.forEach((cat) => {
        const rows = (this.tableData || []).filter(
          (r) => !r.isPlaceholder && r.category === cat && this.classifyDailyRow(r) === tab
        )
        if (!rows.length) {
          out.push(this.makeDisplayPlaceholder(cat))
        } else {
          out.push(...rows)
        }
      })
      return out
    },
    displaySpanArr() {
      const spans = []
      const data = this.displayTableData
      let i = 0
      while (i < data.length) {
        const cat = data[i].category
        let count = 0
        while (i + count < data.length && data[i + count].category === cat) count++
        for (let j = 0; j < count; j++) {
          spans.push(j === 0 ? count : 0)
        }
        i += count
      }
      return spans
    },
    dailySummary() {
      const rows = (this.tableData || []).filter((r) => !r.isPlaceholder)
      const viewRows = (this.displayTableData || []).filter((r) => !r.isPlaceholder)
      return {
        count: rows.length,
        viewCount: viewRows.length,
        totalPredicted: rows.reduce((s, r) => s + parseFloat(r.today_predicted || 0), 0),
        viewPredicted: viewRows.reduce((s, r) => s + parseFloat(r.today_predicted || 0), 0),
        dirtyCount: rows.filter((r) => r._dirty).length
      }
    },
    processRowCounts() {
      const counts = {}
      this.categories.forEach((p) => {
        counts[p] = 0
      })
      ;(this.tableData || []).forEach((r) => {
        if (r.isPlaceholder) return
        const p = r.category || r.process_name
        if (p) counts[p] = (counts[p] || 0) + 1
      })
      return counts
    },
    hasUnsubmittedManualAdds() {
      return (this.tableData || []).some((r) => !r.isPlaceholder && r.is_manual_add)
    }
  },
  created() {
    const today = new Date().toISOString().split('T')[0]
    this.todayDate = today
    this.query.calcDate = today
    this.query.stocktakeDate = this.offsetDate(today, -1)
    // 默认本月
    const now = new Date()
    this.monthlyQuery.yearMonth =
      now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0')
    this.loadData({ skipManualGuard: true })
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

    onCalcDateChange() {
      const calcDate = this.query.calcDate || this.todayDate
      if (calcDate) {
        this.query.stocktakeDate = this.offsetDate(calcDate, -1)
      }
      this.loadData()
    },

    onStocktakeDateChange() {
      this.resetConfirmQtys()
    },

    async ensureDiscardManualAdds() {
      if (!this.hasUnsubmittedManualAdds) return true
      try {
        await ElMessageBox.confirm(
          '有未提交的新增圆钢行，刷新将丢失，是否继续？',
          '提示',
          {
            type: 'warning',
            confirmButtonText: '继续刷新',
            cancelButtonText: '取消'
          }
        )
        return true
      } catch {
        return false
      }
    },

    // ---- 今日看板 ----
    async loadData(options = {}) {
      const skipManualGuard = options.skipManualGuard === true
      if (!skipManualGuard && !(await this.ensureDiscardManualAdds())) {
        return
      }
      this.loading = true
      try {
        const calcDate = this.query.calcDate || new Date().toISOString().split('T')[0]
        if (!this.query.stocktakeDate) {
          this.query.stocktakeDate = this.offsetDate(calcDate, -1)
        }
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

    /** 归属日=推算日用当前库存，否则用昨日结存（对齐小程序预填） */
    getSystemConfirmQty(item) {
      const calcDate = this.query.calcDate || this.todayDate
      const stocktakeDate = this.query.stocktakeDate || this.stocktakeBaselineDate
      if (stocktakeDate && calcDate && stocktakeDate === calcDate) {
        return round3(item.today_predicted || 0)
      }
      return round3(item.predicted_qty ?? item.begin_today ?? 0)
    },

    resetConfirmQtys() {
      ;(this.tableData || []).forEach((row) => {
        if (row.isPlaceholder) return
        if (row.is_manual_add) {
          row.system_qty = 0
          row._dirty = round3(row.confirm_qty) !== 0
          return
        }
        const sys = this.getSystemConfirmQty(row)
        row.system_qty = sys
        row.confirm_qty = sys
        row._dirty = false
      })
    },

    onConfirmQtyChange(row) {
      if (!row || row.isPlaceholder) return
      const confirm = round3(row.confirm_qty)
      const system = round3(row.system_qty)
      row.confirm_qty = confirm
      row._dirty = confirm !== system
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
        // [AI-2026-08] 当前库存为 0 不展示（手动新增行保留，负库存仍展示）
        if (
          !item.is_manual_add &&
          Math.abs(parseFloat(item.today_predicted || 0)) < 0.0005
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
          // [AI-2026-08] 工序内按圆钢型号聚拢（同型号再按钢厂）
          group.items.sort((a, b) => this.compareStocktakeRows(a, b))
          group.items.forEach((item) => {
            const systemQty = this.getSystemConfirmQty(item)
            result.push({
              ...item,
              category: cat,
              category_total: ts.today_predicted,
              today_cat_total: ts.today_predicted,
              isPlaceholder: false,
              system_qty: systemQty,
              confirm_qty: systemQty,
              _dirty: false,
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

    objectSpanMethod({ column, rowIndex }) {
      // [AI-2026-08] 只合并工序、合计；确认吨数按行可改
      if (this.isDailyMergeColumn(column)) {
        const _row = this.spanArr[rowIndex]
        const _col = _row > 0 ? 1 : 0
        return { rowspan: _row, colspan: _col }
      }
    },

    /**
     * [AI-2026-08] 排序用钢厂：优先厂商字段；历史把钢厂写在材质位时回退材质
     */
    getSortManufacturer(row) {
      const { manufacturer, spec } = this.resolveRowIdentity(row)
      if (manufacturer) return manufacturer
      const millNames = ['邯钢', '巨能', '澳森', '潍坊', '潍钢', '宣钢', '富伦', '天钢', '敬业']
      if (millNames.includes(spec)) return spec
      return ''
    },

    /**
     * [AI-2026-08] 日推算/月台账默认排序：型号 → 钢厂 → 名称 → 材质 → 工序规格
     * 例：巨能50、邯钢50、天钢50 … 巨能55、邯钢55
     */
    compareStocktakeRows(a, b) {
      const ia = this.resolveRowIdentity(a)
      const ib = this.resolveRowIdentity(b)

      // 1) 先按圆钢型号聚拢（数字型号按数值，如 50→55）
      const modelA = (ia.model || '').trim()
      const modelB = (ib.model || '').trim()
      if (modelA !== modelB) {
        if (!modelA) return 1
        if (!modelB) return -1
        const modelNumA = parseFloat(modelA)
        const modelNumB = parseFloat(modelB)
        if (!Number.isNaN(modelNumA) && !Number.isNaN(modelNumB) && modelNumA !== modelNumB) {
          return modelNumA - modelNumB
        }
        return modelA.localeCompare(modelB, 'zh-CN', { numeric: true })
      }

      // 2) 同型号再按钢厂
      const millA = this.getSortManufacturer(a)
      const millB = this.getSortManufacturer(b)
      if (millA !== millB) {
        if (!millA) return 1
        if (!millB) return -1
        return millA.localeCompare(millB, 'zh-CN')
      }

      // 3) 再按名称、材质、工序规格
      if (ia.name !== ib.name) {
        return (ia.name || '').localeCompare(ib.name || '', 'zh-CN')
      }
      if (ia.spec !== ib.spec) {
        return (ia.spec || '').localeCompare(ib.spec || '', 'zh-CN')
      }
      const finModelA = this.displayFinishedModel(a)
      const finModelB = this.displayFinishedModel(b)
      if (finModelA !== finModelB) {
        return finModelA.localeCompare(finModelB, 'zh-CN', { numeric: true })
      }
      const finSpecA = this.displayFinishedSpec(a)
      const finSpecB = this.displayFinishedSpec(b)
      return finSpecA.localeCompare(finSpecB, 'zh-CN', { numeric: true })
    },

    isPiercePlus(row) {
      return ['穿孔', '球化', '矫直', '酸洗'].includes(row.process_name || row.category)
    },
    displayFinishedModel(row) {
      const model = (row.c_material_model || '').trim()
      if (model) return model
      if (this.isPiercePlus(row)) {
        return (row.c_material_spec || row.c_product_spec || '').trim() || '-'
      }
      return '-'
    },
    displayFinishedSpec(row) {
      const spec = (row.c_material_spec || row.c_product_spec || '').trim()
      const model = (row.c_material_model || '').trim()
      if (this.isPiercePlus(row) && !model) {
        return '-'
      }
      return spec || '-'
    },

    /**
     * [AI-2026-08] 解析圆钢名称/材质（兼容 material_name 为「溯源｜厂商｜轴承钢｜型号」历史整串）
     */
    resolveRowIdentity(row) {
      let name = (row.y_material_name || row.material_name || '').trim()
      let manufacturer = (row.y_material_manufacturer || '').trim()
      let spec = (row.y_material_spec || '').trim()
      let model = (row.y_material_model || '').trim()

      if (name.includes('｜') || name.includes('|')) {
        const parts = name.split(/[｜|]/).map((s) => s.trim()).filter(Boolean)
        if (parts.length >= 4) {
          name = parts[0]
          manufacturer = manufacturer || parts[1]
          spec = spec || parts[2]
          model = model || parts[3]
        } else if (parts.length === 3 && (parts[0] === '溯源' || parts[0] === '凯亚')) {
          name = parts[0]
          manufacturer = manufacturer || parts[1]
          spec = spec || parts[2]
        } else if (parts.length === 2) {
          if (parts[0] === '溯源' || parts[0] === '凯亚') {
            name = parts[0]
            spec = spec || parts[1]
          } else if (!spec && !model) {
            spec = parts[0]
            model = parts[1]
            if (!name) name = '溯源'
          }
        }
      }

      return { name, manufacturer, spec, model }
    },

    /**
     * 仅按名称+材质字段归类（不含 material_id 回挂）
     * 业务约定：溯源系列整体进「溯源·轴承钢」（不要求材质字面含「轴承」）；
     * 凯亚仅材质为 20#/20 进「凯亚·20#」；其余进「其他」。
     */
    classifyByFields(row) {
      const { name, spec } = this.resolveRowIdentity(row)
      const rawName = (row.y_material_name || row.material_name || '').trim()
      const blob = `${rawName} ${spec}`

      // 溯源系列 = 溯源·轴承钢页签（现场数据常无「轴承」字样，材质位可能为空）
      if (name === '溯源' || rawName === '溯源' || rawName.startsWith('溯源')) {
        return 'suyuan_bearing'
      }

      const norm = normalizeMaterialSpec(spec)
      if (name === '凯亚' || rawName === '凯亚' || rawName.startsWith('凯亚')) {
        if (norm === '20#' || norm === '20' || /(?:^|[｜|\s])20#?(?:[｜|\s]|$)/.test(blob)) {
          return 'kaiya_20'
        }
      }
      return 'other'
    },

    /**
     * [AI-2026-08] 按圆钢名称+材质归页签；下游可按 material_id 挂回
     * 溯源表半成品夹灰/翘皮、成品夹灰、退回* → 溯源·轴承钢；凯亚二级管/回炉钢 → 其他
     */
    classifyDailyRow(row) {
      const process = row.category || row.process_name || ''
      if (process === '次成品' || process === '半成品') {
        return this.classifyDefectRow(row)
      }
      const byFields = this.classifyByFields(row)
      if (byFields !== 'other') {
        return byFields
      }
      const mid = parseInt(row.material_id, 10) || 0
      if (mid > 0 && this.roundSteelTabByMaterialId[mid]) {
        return this.roundSteelTabByMaterialId[mid]
      }
      return 'other'
    },

    /** [AI-2026-08] 次成品/半成品：按品种名归属页签（无圆钢档案） */
    classifyDefectRow(row) {
      const name = (row.material_name || '').trim()
      if (
        name.startsWith('半成品') ||
        name === '成品夹灰' ||
        name.startsWith('退回')
      ) {
        return 'suyuan_bearing'
      }
      return 'other'
    },

    makeDisplayPlaceholder(cat) {
      return {
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
        today_predicted: 0,
        category_total: 0,
        isPlaceholder: true
      }
    },

    isDailyMergeColumn(column) {
      const className = column?.className || ''
      const label = column?.label || ''
      return (
        className.includes('col-process') ||
        className.includes('col-total') ||
        label === '工序' ||
        label === '合计'
      )
    },

    displaySpanMethod({ column, rowIndex }) {
      if (this.isDailyMergeColumn(column)) {
        const _row = this.displaySpanArr[rowIndex]
        const _col = _row > 0 ? 1 : 0
        return { rowspan: _row, colspan: _col }
      }
    },

    displayCatTotal(category) {
      return (this.displayTableData || [])
        .filter((r) => !r.isPlaceholder && r.category === category)
        .reduce((s, r) => s + parseFloat(r.confirm_qty ?? r.today_predicted ?? 0), 0)
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
        this.exportDailyExcel('current')
      }
    },
    onExportCommand(cmd) {
      this.exportDailyExcel(cmd === 'all' ? 'all' : 'current')
    },

    // ---- [AI-2026-08] 新增圆钢行 ----
    openAddRoundSteelDialog() {
      this.addRoundDialogVisible = true
    },

    onAddRoundDialogOpen() {
      this.addRoundSelected = null
      this.addRoundQuery = emptyAddRoundQuery()
      this.loadRoundSteelArchives()
    },

    resetAddRoundQuery() {
      this.addRoundQuery = emptyAddRoundQuery()
      this.loadRoundSteelArchives()
    },

    onAddRoundPageChange(page) {
      this.addRoundQuery.page = page
      this.loadRoundSteelArchives()
    },

    onAddRoundSelect(row) {
      this.addRoundSelected = row || null
    },

    async loadRoundSteelArchives() {
      this.addRoundLoading = true
      try {
        const res = await materialList({ ...this.addRoundQuery, material_property: '圆钢' })
        if (res.code === 200 && res.data) {
          this.addRoundList = res.data.list || []
          this.addRoundTotal = res.data.count || 0
        } else {
          this.addRoundList = []
          this.addRoundTotal = 0
          this.$message.warning(res.msg || '加载圆钢档案失败')
        }
      } catch (e) {
        console.error('加载圆钢档案失败:', e)
        this.addRoundList = []
        this.addRoundTotal = 0
        this.$message.error('加载圆钢档案失败')
      } finally {
        this.addRoundLoading = false
      }
    },

    openRoundSteelArchivePage() {
      const routes = this.$router.getRoutes()
      const hit = routes.find((r) => {
        const name = String(r.name || '')
        const path = String(r.path || '')
        const title = String(r.meta?.title || '')
        return (
          name.includes('RoundSteel') ||
          path.includes('RoundSteel') ||
          /round[-_]?steel/i.test(path) ||
          title.includes('圆钢档案')
        )
      })
      if (hit) {
        const { href } = this.$router.resolve({ path: hit.path })
        window.open(href, '_blank')
        return
      }
      this.$message.info('请从左侧菜单打开「圆钢档案」新建后再回来搜索添加')
    },

    roundSteelIdentityKey(row) {
      const mid = parseInt(row.material_id, 10) || 0
      if (mid > 0) return `id:${mid}`
      return [
        'f',
        (row.y_material_name || row.material_name || '').trim(),
        (row.y_material_manufacturer || row.manufacturer || '').trim(),
        (row.y_material_spec || row.material_spec || '').trim(),
        (row.y_material_model || row.material_model || '').trim()
      ].join('|')
    },

    isRoundSteelAlreadyInTable(archive) {
      const key = this.roundSteelIdentityKey({
        material_id: archive.id,
        material_name: archive.material_name,
        y_material_name: archive.material_name,
        y_material_manufacturer: archive.manufacturer,
        y_material_spec: archive.material_spec,
        y_material_model: archive.material_model
      })
      return (this.tableData || []).some(
        (r) =>
          !r.isPlaceholder &&
          (r.category === '圆钢' || r.process_name === '圆钢') &&
          this.roundSteelIdentityKey(r) === key
      )
    },

    confirmAddRoundSteel() {
      const archive = this.addRoundSelected
      if (!archive || !archive.id) {
        this.$message.warning('请先选择一条圆钢档案')
        return
      }
      if (this.isRoundSteelAlreadyInTable(archive)) {
        this.$message.warning('该圆钢已在盘点列表中，请勿重复添加')
        return
      }
      this.addManualRoundSteelRow(archive)
      this.addRoundDialogVisible = false
      const probe = {
        category: '圆钢',
        process_name: '圆钢',
        material_name: archive.material_name,
        y_material_name: archive.material_name,
        y_material_spec: archive.material_spec
      }
      const tab = this.classifyDailyRow(probe)
      const tabLabel = TAB_LABEL_MAP[tab] || '其他'
      if (tab !== this.dailyMaterialTab) {
        this.dailyMaterialTab = tab
        this.$message.success(`已添加并切换到「${tabLabel}」，请填写确认吨数后提交`)
      } else {
        this.$message.success('已添加，请填写确认吨数后提交圆钢工序')
      }
    },

    addManualRoundSteelRow(archive) {
      const name = (archive.material_name || '').trim()
      const manufacturer = (archive.manufacturer || '').trim()
      const spec = (archive.material_spec || '').trim()
      const model = (archive.material_model || '').trim()
      const newRow = {
        process_name: '圆钢',
        category: '圆钢',
        material_id: archive.id,
        material_name: name,
        y_material_name: name,
        y_material_manufacturer: manufacturer,
        y_material_spec: spec,
        y_material_model: model,
        c_material_spec: '',
        c_material_model: '',
        baseline_qty: 0,
        predicted_qty: 0,
        today_predicted: 0,
        today_inbound: 0,
        today_output: 0,
        today_consume: 0,
        begin_today: 0,
        current_qty: 0,
        consume_qty: 0,
        system_qty: 0,
        confirm_qty: 0,
        _dirty: true,
        is_manual_add: 1,
        isPlaceholder: false,
        category_total: 0,
        today_cat_total: 0,
        _today_sub: { yesterdayStock: 0, dayInOut: 0, dayConsume: 0, predicted: 0 }
      }

      const data = [...(this.tableData || [])]
      let start = data.findIndex((r) => r.category === '圆钢')
      if (start < 0) {
        data.unshift(newRow)
      } else if (data[start].isPlaceholder) {
        data.splice(start, 1, newRow)
      } else {
        let end = start
        while (end < data.length && data[end].category === '圆钢') end++
        data.splice(end, 0, newRow)
      }
      this.tableData = data
      this.rebuildDailySpansAndTotals()
    },

    rebuildDailySpansAndTotals() {
      // [AI-2026-08] 新增圆钢等改动后，各工序内按型号重排
      const ordered = []
      this.categories.forEach((cat) => {
        const rows = (this.tableData || []).filter((r) => r.category === cat)
        const placeholders = rows.filter((r) => r.isPlaceholder)
        const items = rows.filter((r) => !r.isPlaceholder)
        items.sort((a, b) => this.compareStocktakeRows(a, b))
        if (!items.length && placeholders.length) {
          ordered.push(...placeholders)
        } else {
          ordered.push(...items)
        }
      })
      this.tableData = ordered

      const spans = []
      let i = 0
      const data = this.tableData || []
      while (i < data.length) {
        const cat = data[i].category
        let count = 0
        while (i + count < data.length && data[i + count].category === cat) count++
        spans.push(count)
        i += count
      }
      this.generateSpanArr(spans)

      this.categories.forEach((cat) => {
        const rows = data.filter((r) => !r.isPlaceholder && r.category === cat)
        const total = rows.reduce(
          (s, r) => s + parseFloat(r.confirm_qty ?? r.today_predicted ?? 0),
          0
        )
        rows.forEach((r) => {
          r.category_total = total
          r.today_cat_total = total
        })
      })
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

    getProcessRows(processName) {
      return (this.tableData || []).filter(
        (r) => !r.isPlaceholder && (r.category || r.process_name) === processName
      )
    },

    buildSubmitItems(rows) {
      return rows.map((it) => ({
        material_id: it.material_id || 0,
        material_name: it.material_name || it.y_material_name || '',
        y_material_name: it.y_material_name || it.material_name || '',
        y_material_manufacturer: it.y_material_manufacturer || '',
        y_material_spec: it.y_material_spec || '',
        y_material_model: it.y_material_model || '',
        c_material_spec: it.c_material_spec || it.c_product_spec || '',
        c_material_model: it.c_material_model || '',
        c_product_spec: it.c_product_spec || it.c_material_spec || '',
        c_product_model: it.c_product_model || it.c_material_model || '',
        day_shift_qty: String(round3(it.confirm_qty)),
        night_shift_qty: '0'
      }))
    },

    openSubmitDialog(mode, processName = '') {
      if (!this.query.stocktakeDate) {
        this.$message.warning('请先选择盘点归属日')
        return
      }
      this.submitMode = mode
      this.submitProcessName = processName || ''
      let rows = []
      if (mode === 'all') {
        rows = (this.tableData || []).filter((r) => !r.isPlaceholder)
      } else {
        if (!this.submitProcessName) {
          this.$message.warning('工序无效')
          return
        }
        rows = this.getProcessRows(this.submitProcessName)
      }
      if (!rows.length) {
        this.$message.warning('没有可提交的数据')
        return
      }
      this.submitPreview = {
        total: rows.length,
        dirty: rows.filter((r) => r._dirty).length
      }
      this.submitRemark = ''
      this.submitDialogVisible = true
    },

    async confirmSubmit() {
      if (this.submitting) return
      this.submitting = true
      try {
        if (this.submitMode === 'all') {
          await this.submitAll()
        } else {
          const message = await this.submitProcessByName(this.submitProcessName)
          this.showCoverNotice(message, `${this.submitProcessName} 提交成功`)
        }
        this.submitDialogVisible = false
        // [AI-2026-08] 先结束提交转圈，再异步刷新推算，避免「提交+autoCalc」叠在一起像卡死
        this.submitting = false
        await this.loadData({ skipManualGuard: true })
      } catch (e) {
        const msg = e?.message || e?.msg || '提交失败'
        this.$message.error(msg)
      } finally {
        this.submitting = false
      }
    },

    async submitProcessByName(processName) {
      const rows = this.getProcessRows(processName)
      if (!rows.length) {
        return
      }
      const res = await submitStocktake({
        stocktake_date: this.query.stocktakeDate,
        process_name: processName,
        remark: this.submitRemark || '',
        items: this.buildSubmitItems(rows)
      })
      if (!res || res.code !== 200) {
        throw new Error((res && res.msg) || `${processName} 提交失败`)
      }
      return (res && (res.msg || (res.data && res.data.message))) || `${processName} 提交成功`
    },

    showCoverNotice(message, fallback) {
      const text = message || fallback
      if (text && (text.indexOf('未对上') !== -1 || text.indexOf('负数未写入') !== -1 || text.indexOf('发货库存未改') !== -1)) {
        this.$message.warning({ message: text, duration: 8000, showClose: true })
        return
      }
      this.$message.success(text)
    },

    async submitAll() {
      const processes = this.categories.filter((p) => (this.processRowCounts[p] || 0) > 0)
      const notes = []
      for (let i = 0; i < processes.length; i++) {
        const processName = processes[i]
        try {
          const message = await this.submitProcessByName(processName)
          if (message && (message.indexOf('未对上') !== -1 || message.indexOf('负数未写入') !== -1 || message.indexOf('发货库存未改') !== -1)) {
            notes.push(message)
          }
        } catch (e) {
          throw new Error(
            `${processName} 提交失败：${e?.message || e?.msg || '未知错误'}（已完成 ${i}/${processes.length}，后续已中断）`
          )
        }
      }
      if (notes.length) {
        this.$message.warning({
          message: notes.join('；'),
          duration: 8000,
          showClose: true
        })
        return
      }
      this.$message.success(`全部提交成功（${processes.length} 个工序）`)
    },

    /**
     * 月台账「品种」列（定稿）：圆钢身份 名称｜厂商｜材质｜型号
     * 断钢及以下也显示圆钢身份；工序规格放「物料型号」列，不放这里。
     * 次成品/半成品：显示物料名称。
     */
    formatMonthlyMaterialLabel(row) {
      const process = row.process_name || ''
      if (process === '次成品' || process === '半成品') {
        return (row.material_name || row.category || '').trim() || '-'
      }

      const name = (row.y_material_name || row.material_name || row.category || '').trim()
      const manufacturer = (row.y_material_manufacturer || '').trim()
      const spec = (row.y_material_spec || '').trim()
      const model = (row.y_material_model || '').trim()

      if (name && /[｜|]/.test(name) && !manufacturer && !spec && !model) {
        return name.replace(/\|/g, '｜')
      }

      const parts = [name, manufacturer, spec, model].filter(Boolean)
      return parts.join('｜') || '-'
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

    exportDailyExcel(scope = 'current') {
      const source =
        scope === 'all'
          ? (this.tableData || []).filter((r) => !r.isPlaceholder)
          : (this.displayTableData || []).filter((r) => !r.isPlaceholder)
      if (!source.length) {
        this.$message.warning('暂无数据可导出')
        return
      }
      const sheetRows = source.map((r) => ({
        工序: r.category || r.process_name,
        圆钢名称: r.y_material_name || r.material_name || '',
        圆钢厂商: r.y_material_manufacturer || '',
        圆钢材质: r.y_material_spec || '',
        圆钢型号: r.y_material_model || '',
        成品型号: this.displayFinishedModel(r),
        成品规格: this.displayFinishedSpec(r),
        昨日库存: this.formatNumber(this.calcYesterdayStock(r)),
        当日入库产出: this.formatNumber(this.calcDayInOut(r)),
        当日消耗: this.formatNumber(r.today_consume || 0),
        当前库存: this.formatNumber(r.today_predicted || 0),
        确认吨数: this.formatNumber(r.confirm_qty ?? r.system_qty ?? 0),
        是否已改: r._dirty ? '是' : '否',
        是否新增: r.is_manual_add ? '是' : '否',
        页签: TAB_LABEL_MAP[this.classifyDailyRow(r)] || '其他'
      }))
      const ws = XLSX.utils.json_to_sheet(sheetRows)
      const wb = XLSX.utils.book_new()
      XLSX.utils.book_append_sheet(wb, ws, '日推算')
      const tabSuffix =
        scope === 'all' ? '全部' : TAB_LABEL_MAP[this.dailyMaterialTab] || '当前页'
      XLSX.writeFile(
        wb,
        `盘点日推算_${this.query.calcDate || 'today'}_${this.query.stocktakeDate || ''}_${tabSuffix}.xlsx`
      )
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
      // [AI-2026-08] 当前库存为 0 不展示（负库存仍展示）
      rows = rows.filter((r) => Math.abs(parseFloat(r.current_qty || 0)) >= 0.0005)
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

        // [AI-2026-08] 工序内按圆钢型号聚拢（同型号再按钢厂）
        items.sort((a, b) => this.compareStocktakeRows(a, b))

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
      // 工序列（索引0）和合计划（索引8，成品型号+成品规格后）
      if (columnIndex === 0 || columnIndex === 8) {
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
  flex-wrap: wrap;
}

.filter-form-item--date {
  margin-bottom: 0;
}
.filter-form-item--actions {
  margin-bottom: 0;
}
.filter-date {
  width: 160px;
}

.material-tab-bar {
  display: flex;
  align-items: center;
  padding: 10px 16px;
  background: #0f172a;
  border-left: 1px solid #1e293b;
  border-right: 1px solid #1e293b;
}

.material-tab-bar :deep(.el-radio-button__inner) {
  background: #1e293b;
  color: #cbd5e1;
  border-color: #334155;
}

.material-tab-bar :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
  background: #409eff;
  color: #fff;
  border-color: #409eff;
  box-shadow: none;
}

.submit-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 16px;
  margin-bottom: 0;
  background: #fff;
  border-left: 1px solid #e2e8f0;
  border-right: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
}

.submit-toolbar__left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.submit-toolbar__hint {
  font-size: 12px;
  color: #94a3b8;
}

.add-round-filter {
  margin-bottom: 8px;
}

.add-round-filter .el-form-item {
  margin-bottom: 8px;
}

.add-round-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}

.add-round-hint {
  margin-top: 10px;
  font-size: 13px;
  color: #64748b;
}

.manual-add-tag {
  margin-right: 4px;
  vertical-align: middle;
}

.process-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 4px 0;
}

.process-cell__name {
  font-weight: 600;
  color: #475569;
  font-size: 13px;
}

.confirm-qty-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 2px 0;
}

.confirm-qty-cell.is-dirty .confirm-qty-input :deep(.el-input__wrapper) {
  box-shadow: 0 0 0 1px #e6a23c inset;
  background: #fffbeb;
}

.confirm-qty-input {
  width: 110px;
}

.confirm-sys-hint {
  font-size: 11px;
  color: #94a3b8;
  line-height: 1.2;
}

.submit-dialog-body p {
  margin: 0 0 8px;
  color: #334155;
  font-size: 14px;
}

.submit-dialog-warn {
  color: #e6a23c !important;
  font-size: 13px !important;
}

.submit-remark-item {
  margin-top: 12px;
  margin-bottom: 0;
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

/* 日推算表格：9 列（含确认吨数）等比铺满容器，无需横向滚动 */
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

/* 9 列：工序 + 材料/规格/昨日/入产/消耗/当前 + 确认吨数 + 合计 */
:deep(.daily-table col:nth-child(1)) {
  width: 9%;
}
:deep(.daily-table col:nth-child(2)),
:deep(.daily-table col:nth-child(3)),
:deep(.daily-table col:nth-child(4)),
:deep(.daily-table col:nth-child(5)),
:deep(.daily-table col:nth-child(6)),
:deep(.daily-table col:nth-child(7)) {
  width: 11%;
}
:deep(.daily-table col:nth-child(8)),
:deep(.daily-table col:nth-child(9)) {
  width: 14%;
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
