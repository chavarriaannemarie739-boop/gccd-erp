<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field" style="width: auto;">
          <el-radio-group v-model="query.class" size="small">
            <el-radio-button :label="0">白班</el-radio-button>
            <el-radio-button :label="1">夜班</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 100px;">
          <el-input v-model="query.plan_code" placeholder="计划单号" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 100px;">
          <el-input v-model="query.production_plan_name" placeholder="计划名称" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 100px;">
          <el-input v-model="query.y_material_spec" placeholder="圆钢规格" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 100px;">
          <el-input v-model="query.y_material_model" placeholder="圆钢型号" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 100px;">
          <el-input v-model="query.c_material_spec" placeholder="成品规格" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 100px;">
          <el-input v-model="query.c_material_model" placeholder="成品型号" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 90px;">
          <el-input v-model="query.processes_name" placeholder="工序" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 90px;">
          <el-input v-model="query.device_name" placeholder="设备" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: 90px;">
          <el-input v-model="query.create_name" placeholder="报工人员" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--date" style="width: 210px;">
          <el-date-picker
            v-model="order_times"
            type="daterange"
            unlink-panels
            clearable
            range-separator="至"
            start-placeholder="日期起"
            end-placeholder="日期止"
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
      <el-card>
        <div class="toolbar-row">
          <el-button type="primary" size="small" :disabled="editDisabled" @click="toolbarEdit">编辑</el-button>
          <el-button type="danger" size="small" :disabled="type" v-preventReClick @click="dele"
            >删除</el-button
          >
        </div>
        <p class="table-hint">表格列较多时，可在下方横向滚动条拖动查看全部内容（触控板双指滑动亦可）。</p>
        <div class="table-scroll-container">
        <el-table
          ref="tableDataRefss"
          :data="tableData"
          border
          :fit="false"
          class="work-report-table"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
          v-loading="loading"
          :row-key="getRowKeys"
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" align="center" :reserve-selection="true" width="40" />
          <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
          <el-table-column prop="plan_code" label="计划编号" min-width="140" show-overflow-tooltip />
          <el-table-column prop="production_plan_name" label="计划名称" min-width="120" show-overflow-tooltip />
          <el-table-column prop="y_material_spec" label="圆钢规格" min-width="100" show-overflow-tooltip />
          <el-table-column prop="y_material_model" label="圆钢型号" min-width="100" show-overflow-tooltip />
          <el-table-column prop="c_material_spec" label="成品规格" min-width="100" show-overflow-tooltip />
          <el-table-column prop="c_material_model" label="成品型号" min-width="100" show-overflow-tooltip />
          <el-table-column prop="processes_name" label="工序名称" min-width="100" show-overflow-tooltip />
          <el-table-column prop="device_name" label="设备名称" min-width="100" show-overflow-tooltip />
          <el-table-column prop="report_num" label="报工吨数" min-width="90" show-overflow-tooltip />
          <el-table-column prop="yarn_count" label="报工支数" min-width="90" show-overflow-tooltip />
          <el-table-column prop="create_name" label="报工人员" min-width="90" show-overflow-tooltip />
          <el-table-column prop="product_user" label="生产人员" min-width="90" show-overflow-tooltip />
          <el-table-column prop="card_user" label="开车人员" min-width="90" show-overflow-tooltip />
          <el-table-column prop="class" label="班次" min-width="70" show-overflow-tooltip>
            <template #default="{ row }">
              {{ row.class === 1 ? '夜班' : '白班' }}
            </template>
          </el-table-column>
          <el-table-column prop="create_time" label="创建时间" min-width="160" show-overflow-tooltip />
          <el-table-column label="工资" min-width="90" show-overflow-tooltip>
            <template #default="{ row }">
              <el-link type="primary" @click="goToUserWageList(row)">
                {{ formatWage(row.wage_total ?? row.wage) }}
              </el-link>
            </template>
          </el-table-column>
          <el-table-column prop="report_date" label="归属日期" min-width="110" show-overflow-tooltip />
          <el-table-column prop="defective" label="不良品" min-width="80" show-overflow-tooltip />
          <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip />
        </el-table>
        </div>
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
      <el-dialog
        title="详情"
        v-if="processVisibleGoods"
        v-model="processVisibleGoods"
        width="50%"
        :close-on-click-modal="false"
      >
        <PlicationDetails :view="view" v-model:is-show="processVisibleGoods" />
      </el-dialog>
      <el-dialog
        title="编辑"
        v-if="processVisible"
        v-model="processVisible"
        width="40%"
        :close-on-click-modal="false"
      >
        <EditorsReport :view="view" v-model:is-show="processVisible" @list="list" />
      </el-dialog>
    </div>
    <router-view />
  </div>
</template>

<script>
import { list, dele } from '@/api/mes/EnergyConsumption/EnergyConsumption'
import PlicationDetails from './components/PlicationDetails.vue'
import EditorsReport from './components/EditorsReport.vue'
export default {
  name: 'WorkReport',
  components: {
    PlicationDetails,
    EditorsReport
  },
  data() {
    return {
      type: true,
      view: {},
      selectList: [],
      processVisible: false,
      processVisibleGoods: false,
      processVisibleGoodsquery: false,
      order_times: '',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        class: '',
        create_start_date: '',
        create_end_date: '',
        report_date_start: '',
        report_date_end: '',
        plan_code: '',
        production_plan_name: '',
        y_material_spec: '',
        y_material_model: '',
        c_material_spec: '',
        c_material_model: '',
        processes_name: '',
        device_name: '',
        create_name: ''
      },
      loading: true
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
    formatWage(val) {
      const n = Number(val)
      if (!Number.isFinite(n)) return '0.00'
      return n.toFixed(2)
    },
    goToUserWageList(row) {
      const relationCode = row.report_code || row.relation_code || ''
      if (!relationCode) {
        ElMessage.warning('当前记录缺少报工编号，无法跳转')
        return
      }
      this.$router.push({
        name: 'EmployeejobApplication',
        query: { relation_code: relationCode }
      }).catch(() => {
        this.$router.push({
          path: '/mes/BasicSettings/EmployeejobApplication',
          query: { relation_code: relationCode }
        })
      })
    },
    toolbarEdit() {
      if (this.selectList.length !== 1) {
        ElMessage.warning('请勾选一条报工记录后再编辑')
        return
      }
      this.ViewproductionPlanReport(this.selectList[0])
    },
    ViewproductionPlan(row) {
      this.view = row
      this.processVisibleGoods = true
    },
    ViewproductionPlanReport(row) {
      this.view = row
      this.processVisible = true
    },
    getRowKeys(row) {
      return row.id
    },
    handleSelectionChange(val) {
      this.selectList = val
      this.type = this.selectList.length === 0
    },
    async list() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = resp.data.list
        this.total = resp.data.count
      } finally {
        this.loading = false
      }
    },
    handleSearch() {
      this.query.page = 1
      this.list()
    },
    handleChange(val) {
      if (val && val.length === 2) {
        this.query.report_date_start = val[0]
        this.query.report_date_end = val[1]
      } else {
        this.query.report_date_start = ''
        this.query.report_date_end = ''
      }
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
    handleResetting() {
      this.query.class = ''
      this.query.create_start_date = ''
      this.query.create_end_date = ''
      this.query.report_date_start = ''
      this.query.report_date_end = ''
      this.query.plan_code = ''
      this.query.production_plan_name = ''
      this.query.y_material_spec = ''
      this.query.y_material_model = ''
      this.query.c_material_spec = ''
      this.query.c_material_model = ''
      this.query.processes_name = ''
      this.query.device_name = ''
      this.query.create_name = ''
      this.order_times = ''
      this.query.page = 1
      this.list()
    },
    dele() {
      const n = this.selectList.length
      if (n === 0) {
        ElMessage.warning('请先勾选要删除的报工记录')
        return
      }
      const codeHint = this.selectList
        .map((r) => r.report_code || `#${r.id}`)
        .slice(0, 8)
        .join('、')
      const more = n > 8 ? ` 等共 ${n} 条` : ''
      const multiHint =
        n > 1
          ? '\n\n若只需删除一条，请先取消本对话框，再仅勾选一条记录后删除。'
          : ''
      const message = `即将删除 ${n} 条报工记录（${codeHint}${more}），删除后不可恢复。${multiHint}`
      ElMessageBox.confirm(message, '删除确认', {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning',
        distinguishCancelAndClose: true
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
          await this.list()
          this.selectList = []
          this.type = true
          await this.$nextTick()
          this.$refs.tableDataRefss?.clearSelection?.()
          ElMessage({
            type: 'success',
            message: `已删除 ${n} 条报工记录`
          })
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
.toolbar-row {
  margin-bottom: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.contenter {
  margin-top: 10px;
}

.table-hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin: 0 0 10px 2px;
  letter-spacing: 0.02em;
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

.work-report-table {
  min-width: 1600px;
}
</style>
