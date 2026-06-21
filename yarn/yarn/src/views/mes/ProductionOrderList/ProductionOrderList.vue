<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.production_plan_name"
            placeholder="计划名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_spec"
            placeholder="圆钢规格"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_model"
            placeholder="圆钢型号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_spec"
            placeholder="成品规格"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_model"
            placeholder="成品型号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.production_plan_code"
            placeholder="计划单号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="order_times"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
            @change="handleChange"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="handleSearch">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
        <el-card class="main-list-card">
          <el-tabs v-model="planTab" class="plan-tabs" @tab-change="onPlanTabChange">
            <el-tab-pane label="进行中" name="active" />
            <el-tab-pane label="已完成" name="done" />
          </el-tabs>
          <div v-if="planTab === 'active'" class="toolbar-row">
            <el-button type="success" size="small" @click="CheckbelowGoods">新增</el-button>
            <el-button type="primary" size="small" @click="exportGoods">导出</el-button>
            <el-button type="primary" size="small" :disabled="editDisabled" @click="toolbarEdit"
              >编辑</el-button
            >
            <el-button type="danger" size="small" :disabled="type" v-preventReClick @click="dele(row)"
              >删除</el-button
            >
            <el-button type="warning" size="small" :disabled="editDisabled" @click="openFlowCardPrint"
              >打印流转卡</el-button
            >
          </div>
          <div v-else class="toolbar-row toolbar-row--done">
            <el-button type="primary" size="small" @click="exportGoods">导出</el-button>
            <el-button type="warning" size="small" :disabled="editDisabled" @click="openFlowCardPrint"
              >打印流转卡</el-button
            >
          </div>
          <p class="table-hint">表格列较多时，可在下方横向滚动条拖动查看全部内容（触控板双指滑动亦可）。</p>
          <div class="table-scroll-container">
            <el-table
              :key="planTab"
              ref="tableDataRefss"
              class="production-plan-table"
              :data="tableData"
              border
              :fit="false"
              :header-cell-style="tableHeaderCellStyle"
              :cell-style="tableCellStyle"
              v-loading="loading"
              :row-key="getRowKeys"
              @selection-change="handleSelectionChange"
            >
              <el-table-column
                v-if="planTab === 'active'"
                type="selection"
                align="center"
                :reserve-selection="true"
                width="48"
              />
              <el-table-column type="index" :index="handleIndex" label="序号" width="64" />
              <el-table-column
                prop="production_plan_name"
                label="计划名称"
                min-width="100"
                show-overflow-tooltip
              />
              <el-table-column
                prop="y_mateial_spec"
                label="圆钢规格"
                min-width="88"
                show-overflow-tooltip
              />
              <el-table-column
                prop="y_mateial_model"
                label="圆钢型号"
                min-width="88"
                show-overflow-tooltip
              />
              <el-table-column
                prop="material_spec"
                label="成品规格"
                min-width="100"
                show-overflow-tooltip
              />
              <el-table-column
                prop="material_model"
                label="成品型号"
                min-width="100"
                show-overflow-tooltip
              />
              <el-table-column prop="plan_num" label="计划数量" width="96" show-overflow-tooltip />
              <el-table-column
                prop="punch_device_names"
                label="穿孔设备"
                min-width="120"
                show-overflow-tooltip
              />
              <el-table-column label="状态" width="88" align="center">
                <template #default="{ row }">
                  <el-tag v-if="row._planStatusDone" type="success" size="small" effect="plain"
                    >已完毕</el-tag
                  >
                  <el-tag v-else type="warning" size="small" effect="plain">生产中</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="工序报工（吨）" min-width="360" align="center">
                <template #default="{ row }">
                  <div v-if="row._progressLoading" class="progress-loading">
                    <span class="progress-loading-dot" />
                    <span>加载报工数据…</span>
                  </div>
                  <div v-else class="process-progress-strip">
                    <el-tooltip
                      v-for="p in row._processProgress || []"
                      :key="p.name"
                      placement="top"
                      :show-after="200"
                    >
                      <template #content>
                        <div class="progress-tooltip">
                          <strong>{{ p.name }}</strong>
                          <span>报工合计：{{ formatProgressNum(p.report_num) }}（与小程序工单同源）</span>
                          <span class="progress-tooltip-plan"
                            >计划数量：{{ formatProgressNum(row.plan_num) }}</span
                          >
                          <span v-if="progressNumClass(p.report_num, row.plan_num) === 'progress-num--over'" class="progress-tooltip-warn"
                            >已超过计划数量</span
                          >
                        </div>
                      </template>
                      <div class="process-step-card">
                        <span class="process-step-name">{{ p.name }}</span>
                        <span
                          class="process-step-num"
                          :class="progressNumClass(p.report_num, row.plan_num)"
                          >{{ formatProgressNum(p.report_num) }}</span
                        >
                      </div>
                    </el-tooltip>
                  </div>
                </template>
              </el-table-column>
              <el-table-column
                prop="production_plan_code"
                label="生产计划单号"
                min-width="155"
                show-overflow-tooltip
              />
              <el-table-column prop="create_time" label="创建时间" min-width="160" show-overflow-tooltip />
            </el-table>
          </div>
          <!-- 分页 -->
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
        <!-- 新增 -->
        <el-dialog
          title="新增"
          v-if="processVisible"
          v-model="processVisible"
          width="60%"
          :close-on-click-modal="false"
        >
          <NewProductionPlan v-model:is-show="processVisible" @list="list" />
        </el-dialog>
        <!-- 编辑 -->
        <el-dialog
          title="编辑"
          v-if="processVisibleProduction"
          v-model="processVisibleProduction"
          width="60%"
          :close-on-click-modal="false"
        >
          <EditingAndProduction
            :view="view"
            v-model:is-show="processVisibleProduction"
            @list="list"
          />
        </el-dialog>
        <!-- 详情 -->
        <el-dialog
          title="详情"
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="50%"
          :close-on-click-modal="false"
        >
          <ViewProductionPlan :view="view" v-model:is-show="processVisibleGoods" />
        </el-dialog>
        <!-- 下推计划 -->
        <el-dialog
          title="下推计划"
          v-if="processVisibleGoodsquery"
          v-model="processVisibleGoodsquery"
          width="50%"
          :close-on-click-modal="false"
        >
          <PushDownPlan :view="view" @list="list" v-model:is-show="processVisibleGoodsquery" />
        </el-dialog>
        <FlowCardPrintDialog
          v-model="flowCardPrintVisible"
          :plan-id="flowCardPlanId"
          :plan-name="flowCardPlanName"
        />
    </div>
    <router-view />
  </div>
</template>

<script>
import { list, dele, exportExcel } from '@/api/mes/ProductionOrderList/ProductionOrderList'
import { listByRelationCodes, editTask } from '@/api/mes/ProductionOrderList/Tasklist/Tasklist'
import { getProcess } from '@/api/mes/ProcessRoute/ProcessRoute'
import {
  FIXED_PROCESSES,
  aggregatePlanProgress,
  buildProcessNameToCodeMap
} from '@/utils/productionPlanProgress'
import ViewProductionPlan from './components/ViewProductionPlan.vue'
import NewProductionPlan from './components/NewProductionPlan.vue'
import PushDownPlan from './components/PushDownPlan.vue'
import EditingAndProduction from './components/EditingAndProduction.vue'
import FlowCardPrintDialog from './components/FlowCardPrintDialog.vue'
export default {
  name: 'ProductionOrderList',
  components: {
    ViewProductionPlan,
    NewProductionPlan,
    EditingAndProduction,
    PushDownPlan,
    FlowCardPrintDialog
  },
  data() {
    return {
      type: true,
      view: {},
      selectList: [],
      processVisible: false,
      processVisibleGoods: false,
      processVisibleProduction: false,
      processVisibleGoodsquery: false,
      flowCardPrintVisible: false,
      flowCardPlanId: 0,
      flowCardPlanName: '',
      buttons: [{ type: 'primary', text: 'primary' }],
      dialogTableVis: false,
      order_times: '',
      planTab: 'active',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        production_plan_name: '',
        production_plan_code: '',
        material_spec: '',
        material_model: '',
        y_material_spec: '',
        y_material_model: '',
        create_start_date: '',
        create_end_date: '',
        plan_finish: '0'
      },
      count: 0,
      tableList: [],
      loading: true,
      _routingNameToCodeCache: {},
      _routeContentCache: {},
      tableHeaderCellStyle: {
        textAlign: 'center',
        background: 'var(--el-fill-color-light)',
        fontWeight: '600',
        color: 'var(--el-text-color-primary)',
        fontSize: '13px'
      },
      tableCellStyle: {
        textAlign: 'center',
        verticalAlign: 'middle'
      }
    }
  },
  computed: {
    editDisabled() {
      return this.selectList.length !== 1
    }
  },
  mounted() {
    this.list()
    this._skipNextActivated = true
  },
  activated() {
    if (this._skipNextActivated) {
      this._skipNextActivated = false
      return
    }
    this.list()
  },
  methods: {
    onPlanTabChange(tabName) {
      const name = tabName ?? this.planTab
      this.query.plan_finish = name === 'done' ? '1' : '0'
      this.query.page = 1
      this.selectList = []
      this.type = true
      this.$nextTick(() => {
        this.$refs.tableDataRefss?.clearSelection?.()
      })
      this.list()
    },
    taskFinishedMini(t) {
      const st = parseInt(t.status, 10)
      if (st === 2) return true
      const tn = parseFloat(t.task_num) || 0
      const rn = parseFloat(t.report_num) || 0
      return tn > 0 && rn >= tn
    },
    formatProgressNum(n) {
      const x = parseFloat(n) || 0
      return Number.isInteger(x) ? String(x) : String(x)
    },
    /** 0：蓝色；大于 0 且未超计划：绿色；大于计划数：红色（计划为 0 时不判超量） */
    progressNumClass(reportNum, planNum) {
      const n = parseFloat(reportNum) || 0
      const cap = parseFloat(planNum) || 0
      if (n <= 0) return 'progress-num--zero'
      if (cap > 0 && n > cap) return 'progress-num--over'
      return 'progress-num--ok'
    },
    toolbarEdit() {
      if (this.selectList.length !== 1) {
        ElMessage.warning('请勾选一条生产计划进行编辑')
        return
      }
      this.Viewproductionedit(this.selectList[0])
    },
    openFlowCardPrint() {
      if (this.selectList.length !== 1) {
        ElMessage.warning('请勾选一条生产计划')
        return
      }
      const row = this.selectList[0]
      this.flowCardPlanId = row.id
      this.flowCardPlanName = row.production_plan_name || ''
      this.flowCardPrintVisible = true
    },
    async ensureNameToCode(routing_code) {
      if (!routing_code) return {}
      if (this._routingNameToCodeCache[routing_code]) {
        return this._routingNameToCodeCache[routing_code]
      }
      try {
        const res = await getProcess(routing_code)
        const content = res.data?.content || []
        const map = buildProcessNameToCodeMap(content)
        this._routingNameToCodeCache[routing_code] = map
        // 同时缓存完整 content（含 sort），用于动态展示工序
        if (!this._routeContentCache) this._routeContentCache = {}
        this._routeContentCache[routing_code] = content
        return map
      } catch {
        this._routingNameToCodeCache[routing_code] = {}
        return {}
      }
    },
    async attachProcessProgress(rows) {
      if (!rows?.length) return
      this._routingNameToCodeCache = {}
      this._routeContentCache = {}
      const codes = [...new Set(rows.map((r) => r.production_plan_code).filter(Boolean))]
      if (!codes.length) {
        rows.forEach((r) => {
          r._processProgress = FIXED_PROCESSES.map((fp) => ({ name: fp.name, report_num: 0 }))
          r._planStatusDone = false
          r._progressLoading = false
        })
        return
      }
      let allTasks = []
      try {
        const res = await listByRelationCodes({ relation_codes: codes })
        const payload = res.data
        allTasks = Array.isArray(payload?.list) ? payload.list : Array.isArray(payload) ? payload : []
      } catch {
        allTasks = []
      }
      const byPlan = {}
      allTasks.forEach((t) => {
        const k = t.relation_code
        if (!k) return
        if (!byPlan[k]) byPlan[k] = []
        byPlan[k].push(t)
      })
      const routingSet = new Set()
      rows.forEach((row) => {
        const tasks = byPlan[row.production_plan_code] || []
        const routing = row.routing_code || tasks[0]?.routing_code
        if (routing) routingSet.add(routing)
      })
      await Promise.all([...routingSet].map((rc) => this.ensureNameToCode(rc)))
      rows.forEach((row) => {
        try {
          const tasks = row.production_plan_code ? byPlan[row.production_plan_code] || [] : []
          const routing = row.routing_code || tasks[0]?.routing_code || ''
          const nameToCode = this._routingNameToCodeCache[routing] || {}
          const routeContent = (this._routeContentCache && this._routeContentCache[routing]) || []
          row._processProgress =
            tasks.length > 0
              ? aggregatePlanProgress(tasks, nameToCode, routeContent)
              : routeContent.length > 0
                ? routeContent.slice().sort((a,b)=>(a.sort||0)-(b.sort||0)).map(c => ({
                    name: c.processes_name || c.process_code || '-',
                    report_num: 0
                  }))
                : FIXED_PROCESSES.map((fp) => ({ name: fp.name, report_num: 0 }))
          row._planStatusDone =
            tasks.length > 0 && tasks.every((t) => this.taskFinishedMini(t))
          row._tasks = tasks
        } catch {
          row._processProgress = FIXED_PROCESSES.map((fp) => ({ name: fp.name, report_num: 0 }))
          row._planStatusDone = false
        } finally {
          row._progressLoading = false
        }
      })
    },
    async exportGoods() {
      // data 为 向后台传递的参数，大家自行定义
      await exportExcel()
        .then((res) => {
          var debug = res
          if (res.code === 200) {
            // const xlsx = 'application/vnd.ms-excel'
            // const blob = new Blob([res.data], { type: xlsx })
            // //转换数据类型
            // const a = document.createElement('a') // 转换完成，创建一个a标签用于下载
            // // const name = res.headers['content-disposition']
            // // a.download = name.split('=')[1]
            // a.download = `${this.$t('自定义文件名')}.xlsx`
            // a.href = window.URL.createObjectURL(blob)
            // a.click()
            // a.remove()
            // document.body.removeChild(a) //也可以这么移除
            // 直接打开下载文件的链接
            window.location.href = res.data.data.url
          }
        })
        .catch((err) => {
          console.log(err)
        })
    },
    // 新增
    CheckbelowGoods() {
      this.processVisible = true
    },
    // 编辑
    Viewproductionedit(row) {
      this.view = row
      this.processVisibleProduction = true
    },
    // 详情
    ViewproductionPlan(row) {
      this.view = row
      this.processVisibleGoods = true
    },
    //编辑
    EditMaterials(row) {
      this.view = row
      this.processVisibleGoodsquery = true
    },
    // 产品唯一的标识
    getRowKeys(row) {
      return row.id
    },
    // 选择项发生改变
    handleSelectionChange(val) {
      // 当前选中的天平书
      this.selectList = val
      if (this.selectList.length !== 0) {
        this.type = false
      } else {
        this.type = true
      }
    },
    async list() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = (resp.data.list || []).map((r) => ({
          ...r,
          _progressLoading: true
        }))
        this.total = resp.data.count
      } finally {
        this.loading = false
      }
      await this.attachProcessProgress(this.tableData)
      // 进行中tab：将前端判定已完工但后端未同步的任务刷为 status=2
      if (this.planTab === 'active') {
        await this.syncDonePlans()
      }
    },
    async syncDonePlans() {
      const doneRows = this.tableData.filter(r => r._planStatusDone === true)
      if (!doneRows.length) return
      const tasks = []
      for (const row of doneRows) {
        for (const t of (row._tasks || [])) {
          if (t.status !== 2) tasks.push(t.id)
        }
      }
      if (!tasks.length) return
      await Promise.all(tasks.map(id => editTask({ id, status: 2 })))
      // 从当前列表移除已同步的已完成计划
      this.tableData = this.tableData.filter(r => r._planStatusDone !== true)
      this.total = this.tableData.length
    },
    handleSearch() {
      this.query.page = 1
      this.list()
    },
    // 处理时间
    handleChange(val) {
      if (val && val.length === 2) {
        this.query.create_start_date = val[0]
        this.query.create_end_date = val[1]
      } else {
        this.query.create_start_date = ''
        this.query.create_end_date = ''
      }
    },
    // 处理序号
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    // 条数
    handleSizeChange(val) {
      this.query.limit = val
      // 获取列表
      this.list()
    },
    // 页数
    handleCurrentChange(val) {
      this.query.page = val
      // 获取列表
      this.list()
    },
    // 重置
    handleResetting() {
      this.query.production_plan_name = ''
      this.query.production_plan_code = ''
      this.query.material_spec = ''
      this.query.material_model = ''
      this.query.y_material_spec = ''
      this.query.y_material_model = ''
      this.query.create_start_date = ''
      this.query.create_end_date = ''
      this.order_times = []
      this.handleSearch()
    },
    // 删除计划
    dele(row) {
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
          await dele(params)
          this.list()
          ElMessage({
            type: 'success',
            message: '删除成功'
          })
          this.type = true
        })
        .catch(() => {
          // ElMessage({
          //   type: 'info',
          //   message: 'Delete canceled'
          // })
        })
    },
    // 库存下推
    handlePush_down() {
      // 处理发货操作
      const tmpPath = {
        path: '/mes/productionPlan/PlanningManagement/ProductionplanpushDown'
      }
      this.$router.push(tmpPath)
    }
  }
}
</script>
<style scoped>
.main-list-card :deep(.el-card__body) {
  padding-top: 8px;
}

.plan-tabs {
  margin: -4px 0 10px;
}

.plan-tabs :deep(.el-tabs__header) {
  margin-bottom: 0;
}

.toolbar-row {
  margin-bottom: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.toolbar-row--done {
  margin-bottom: 14px;
}

.table-scroll-container {
  width: 100%;
  max-width: 100%;
  overflow-x: auto;
  overflow-y: visible;
  -webkit-overflow-scrolling: touch;
  border-radius: 10px;
  border: 1px solid var(--el-border-color-lighter);
  background: var(--el-bg-color);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
  margin-bottom: 6px;
}

.table-scroll-container::-webkit-scrollbar {
  height: 12px;
}

.table-scroll-container::-webkit-scrollbar-track {
  background: var(--el-fill-color-lighter);
  border-radius: 8px;
  margin: 0 4px;
}

.table-scroll-container::-webkit-scrollbar-thumb {
  background: linear-gradient(
    180deg,
    var(--el-color-primary-light-5),
    var(--el-color-primary-light-3)
  );
  border-radius: 8px;
  border: 3px solid var(--el-fill-color-lighter);
}

.table-scroll-container::-webkit-scrollbar-thumb:hover {
  background: var(--el-color-primary-light-3);
}

.table-hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin: 0 0 10px 2px;
  letter-spacing: 0.02em;
}

.production-plan-table {
  min-width: 1240px;
}

.process-progress-strip {
  display: inline-flex;
  flex-wrap: nowrap;
  align-items: stretch;
  justify-content: center;
  gap: 6px;
  padding: 2px 0 4px;
  min-height: 42px;
}

.process-step-card {
  min-width: 64px;
  max-width: 76px;
  padding: 5px 6px 7px;
  border-radius: 8px;
  background: linear-gradient(
    165deg,
    var(--el-fill-color-blank) 0%,
    var(--el-fill-color-light) 110%
  );
  border: 1px solid var(--el-border-color-lighter);
  border-top: 2px solid var(--el-border-color);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
  cursor: default;
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease,
    border-color 0.16s ease;
  text-align: center;
}

.process-step-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  border-color: var(--el-color-primary-light-7);
}

.process-step-name {
  display: block;
  font-size: 11px;
  color: var(--el-text-color-secondary);
  margin-bottom: 5px;
  letter-spacing: 0.02em;
  font-weight: 500;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.process-step-num {
  font-size: 15px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1.15;
}

.process-step-num.progress-num--zero {
  color: #409eff;
}

.process-step-num.progress-num--ok {
  color: #67c23a;
}

.process-step-num.progress-num--over {
  color: #f56c6c;
}

.progress-loading {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
  padding: 8px 4px;
}

.progress-loading-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--el-color-primary);
  animation: progress-pulse 1.1s ease-in-out infinite;
}

@keyframes progress-pulse {
  0%,
  100% {
    opacity: 0.35;
    transform: scale(0.85);
  }
  50% {
    opacity: 1;
    transform: scale(1);
  }
}

.progress-tooltip {
  max-width: 260px;
  line-height: 1.55;
  font-size: 13px;
}

.progress-tooltip strong {
  display: block;
  margin-bottom: 6px;
  font-size: 14px;
}

.progress-tooltip span {
  display: block;
}

.progress-tooltip-plan {
  margin-top: 6px;
  opacity: 0.92;
  font-size: 12px;
}

.progress-tooltip-warn {
  margin-top: 6px;
  color: #f56c6c;
  font-weight: 600;
}

:deep(.production-plan-table.el-table) {
  --el-table-border-color: var(--el-border-color-lighter);
}

:deep(.production-plan-table .el-table__header th) {
  padding: 9px 6px;
}

:deep(.production-plan-table .el-table__body td) {
  padding: 7px 6px;
}

:deep(.production-plan-table .el-table__body tr:hover > td) {
  background-color: var(--el-fill-color-lighter) !important;
}

.contenter {
  margin-top: 10px;
}
</style>
