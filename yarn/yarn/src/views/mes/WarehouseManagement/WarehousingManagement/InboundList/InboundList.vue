<template>
  <!-- [AI-2026-08] 入库圆钢统一名称、厂商、材质、型号 -->
  <div class="app-container inbound-list-page">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.in_code"
            placeholder="入库编码"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.manufacturer"
            placeholder="厂商"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_name"
            placeholder="物料名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_batch"
            placeholder="物料批次"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_spec"
            placeholder="规格"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_model"
            placeholder="型号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.location"
            placeholder="库位"
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
          <el-button v-preventReClick type="primary" size="small" @click="handleSearch"
            >搜索</el-button
          >
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
      <el-card class="main-list-card">
        <el-tabs
          v-model="activeName"
          class="plan-tabs"
          type="card"
          @tab-change="onWarehouseTabChange"
        >
          <!-- [AI-2026-08] 按入库仓库权限显隐 Tab -->
          <el-tab-pane v-if="canInboundTab('配件')" label="配件仓库" name="配件仓库" />
          <el-tab-pane v-if="canInboundTab('圆钢')" label="圆钢仓库" name="圆钢仓库" />
          <el-tab-pane v-if="canInboundTab('成品')" label="成品仓库" name="成品库" />
        </el-tabs>
        <div class="toolbar-row">
          <el-button
            type="success"
            size="small"
            :disabled="inboundAddDisabled"
            @click="CheckbelowGoods"
            >新增</el-button
          >
          <el-button type="primary" size="small" :disabled="editDisabled" @click="toolbarEdit"
            >编辑</el-button
          >
          <el-button type="danger" size="small" :disabled="type" v-preventReClick @click="dele(row)"
            >删除</el-button
          >
        </div>
        <p class="table-hint">
          表格列较多时，可在下方横向滚动条拖动查看全部内容（触控板双指滑动亦可）。
        </p>
        <div class="table-scroll-container">
          <el-table
            :key="activeName"
            ref="tableDataRefss"
            class="inbound-table"
            :data="tableData"
            border
            :fit="true"
            :header-cell-style="tableHeaderCellStyle"
            :cell-style="tableCellStyle"
            v-loading="loading"
            :row-key="getRowKeys"
            @selection-change="handleSelectionChange"
          >
            <el-table-column type="selection" align="center" :reserve-selection="true" width="48" />
            <el-table-column type="index" :index="handleIndex" label="序号" width="64" />
            <el-table-column
              v-for="col in tableColumns"
              :key="col.prop"
              :prop="col.prop"
              :label="col.label"
              :width="col.width"
              :min-width="col.minWidth"
              show-overflow-tooltip
            />
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
        <!-- 新增 -->
        <el-dialog
          title="新增"
          v-if="processVisible"
          v-model="processVisible"
          width="70%"
          :close-on-click-modal="false"
        >
          <CreateInventory
            :inbound-tab="activeName"
            v-model:is-show="processVisible"
            @list="list"
          />
        </el-dialog>
        <!-- 编辑 -->
        <el-dialog
          title="编辑"
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="70%"
          :close-on-click-modal="false"
        >
          <ViewInventory :view="view" v-model:is-show="processVisibleGoods" @list="list" />
        </el-dialog>
      </el-card>
    </div>
  </div>
</template>

<script>
import { list, dele } from '@/api/mes/WarehouseManagement/WarehouseManagement'
import { listGoods } from '@/api/mes/WarehouseManagement/RepositoryList'
import CreateInventory from './components/CreateInventory.vue'
import ViewInventory from './components/ViewInventory.vue'
import { canWarehouse, pickDefaultTab, visibleWarehouseTabs } from '@/utils/warehouseScope'
export default {
  name: 'InboundList',
  components: {
    CreateInventory,
    ViewInventory
  },
  data() {
    return {
      activeName: '配件仓库',
      type: true,
      view: {},
      selectList: [],
      options: [],
      processVisible: false,
      processVisibleGoods: false,
      buttons: [{ type: 'primary', text: 'primary' }],
      dialogTableVis: false,
      order_times: '',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        in_code: '',
        material_name: '',
        manufacturer: '',
        warehouse: '配件',
        material_batch: '',
        create_start_date: '',
        create_end_date: '',
        material_spec: '',
        material_model: '',
        location: ''
      },
      queryGoods: {
        AdminToken: '',
        page: 1,
        limit: 100000000
      },
      count: 0,
      tableList: [],
      loading: true,
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
    inboundTabDefs() {
      return [
        { type: '配件', name: '配件仓库', label: '配件仓库' },
        { type: '圆钢', name: '圆钢仓库', label: '圆钢仓库' },
        { type: '成品', name: '成品库', label: '成品仓库' }
      ]
    },
    visibleInboundTabs() {
      return visibleWarehouseTabs('inbound', this.inboundTabDefs)
    },
    inboundAddDisabled() {
      return this.activeName === '成品库'
    },
    editDisabled() {
      return this.selectList.length !== 1
    },
    tableColumns() {
      if (this.activeName === '成品库') {
        return [
          { prop: 'in_code', label: '入库编号', minWidth: 130 },
          { prop: 'relation_code', label: 'SCJH单号', minWidth: 130 },
          { prop: 'material_name', label: '圆钢名称', minWidth: 120 },
          { prop: 'manufacturer', label: '厂商', minWidth: 120 },
          { prop: 'material_spec', label: '材质', minWidth: 120 },
          { prop: 'material_model', label: '型号', minWidth: 120 },
          { prop: 'finished_material_name', label: '成品名称', minWidth: 120 },
          { prop: 'finished_material_spec', label: '成品规格', minWidth: 120 },
          { prop: 'finished_material_model', label: '成品型号', minWidth: 120 },
          { prop: 'in_num', label: '入库数量', minWidth: 100 },
          { prop: 'material_batch', label: '批次号', minWidth: 120 },
          { prop: 'remark', label: '备注', minWidth: 100 },
          { prop: 'create_time', label: '报工入库时间', minWidth: 160 }
        ]
      }
      if (this.activeName === '圆钢仓库') {
        // [AI-2026-08] 入库时间跟序号后；入库编号/库位/备注放最后
        return [
          { prop: 'inbound_date', label: '入库日期', minWidth: 110 },
          { prop: 'material_name', label: '圆钢名称', minWidth: 120 },
          { prop: 'manufacturer', label: '厂商', minWidth: 120 },
          { prop: 'material_spec', label: '材质', minWidth: 120 },
          { prop: 'material_model', label: '型号', minWidth: 120 },
          { prop: 'in_num', label: '入库数量', width: 100 },
          { prop: 'money', label: '单价', width: 88 },
          { prop: 'sum', label: '总额', width: 88 },
          { prop: 'material_batch', label: '批次信息', minWidth: 120 },
          { prop: 'create_name', label: '创建人', minWidth: 88 },
          { prop: 'create_time', label: '创建时间', minWidth: 160 },
          { prop: 'in_code', label: '入库编号', minWidth: 130 },
          { prop: 'location', label: '库位', minWidth: 88 },
          { prop: 'remark', label: '备注', minWidth: 100 }
        ]
      }
      if (this.activeName === '配件仓库') {
        return [
          { prop: 'in_code', label: '入库编号', minWidth: 140 },
          { prop: 'material_name', label: '配件名称', minWidth: 120 },
          { prop: 'material_spec', label: '配件规格', minWidth: 120 },
          { prop: 'material_model', label: '配件型号', minWidth: 120 },
          { prop: 'supplier', label: '供应商', minWidth: 120 },
          { prop: 'in_num', label: '入库数量', width: 100 },
          { prop: 'money', label: '单价', width: 88 },
          { prop: 'sum', label: '总额', width: 88 },
          { prop: 'material_batch', label: '批次信息', minWidth: 120 },
          { prop: 'location', label: '库位', minWidth: 88 },
          { prop: 'remark', label: '备注', minWidth: 100 },
          { prop: 'create_name', label: '创建人', minWidth: 88 },
          { prop: 'create_time', label: '创建时间', minWidth: 160 }
        ]
      }
      return [
        { prop: 'in_code', label: '入库编号', minWidth: 140 },
        { prop: 'material_name', label: '物料名称', minWidth: 120 },
        { prop: 'material_spec', label: '物料规格', minWidth: 120 },
        { prop: 'in_num', label: '入库数量', width: 100 },
        { prop: 'money', label: '单价', width: 88 },
        { prop: 'sum', label: '总额', width: 88 },
        { prop: 'material_batch', label: '批次信息', minWidth: 120 },
        { prop: 'location', label: '库位', minWidth: 88 },
        { prop: 'remark', label: '备注', minWidth: 100 },
        { prop: 'create_name', label: '创建人', minWidth: 88 },
        { prop: 'create_time', label: '创建时间', minWidth: 160 }
      ]
    }
  },
  // [AI-2026-07] keep-alive 白名单外首次进入不触发 activated，需 mounted 拉数
  mounted() {
    this.applyInboundTabScope()
    this.syncWarehouseQueryFromTab()
    this.list()
    this.listGoods()
  },
  activated() {
    this.applyInboundTabScope()
    this.syncWarehouseQueryFromTab()
    this.list()
    this.listGoods()
  },
  methods: {
    canInboundTab(type) {
      return canWarehouse('inbound', type)
    },
    applyInboundTabScope() {
      this.activeName = pickDefaultTab(this.visibleInboundTabs, this.activeName)
    },
    /** 与 Tab 对应的仓库 LIKE 关键字（兼容库内 配件仓库/配件库、圆钢仓库、成品仓库/成品库 等写法，仅影响筛选，不改数据） */
    warehouseLikeForTab(tabName) {
      const map = {
        配件仓库: '配件',
        圆钢仓库: '圆钢',
        成品库: '成品'
      }
      return map[tabName] ?? '配件'
    },
    syncWarehouseQueryFromTab() {
      this.query.warehouse = this.warehouseLikeForTab(this.activeName)
    },
    onWarehouseTabChange(name) {
      const w = name ?? this.activeName
      this.query.warehouse = this.warehouseLikeForTab(w)
      this.query.page = 1
      this.selectList = []
      this.type = true
      this.$nextTick(() => {
        this.$refs.tableDataRefss?.clearSelection?.()
      })
      this.list()
    },
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.options = resp.data.list
    },
    CheckbelowGoods() {
      if (this.inboundAddDisabled) {
        ElMessage.warning('成品入库由酸洗工序报工自动生成，不能手动新增')
        return
      }
      this.processVisible = true
    },
    toolbarEdit() {
      if (this.selectList.length !== 1) {
        ElMessage.warning('请勾选一条入库记录进行编辑')
        return
      }
      this.ViewproductionPlan(this.selectList[0])
    },
    ViewproductionPlan(row) {
      this.view = row
      this.processVisibleGoods = true
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
        this.tableData = resp.data?.list || []
        this.total = resp.data?.count || 0
      } catch (e) {
        /* request 拦截器已提示 */
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
        this.query.create_start_date = val[0]
        this.query.create_end_date = val[1]
      } else {
        this.query.create_start_date = ''
        this.query.create_end_date = ''
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
      this.query.in_code = ''
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_batch = ''
      this.query.create_start_date = ''
      this.query.create_end_date = ''
      this.query.material_spec = ''
      this.query.material_model = ''
      this.query.location = ''
      this.order_times = []
      this.syncWarehouseQueryFromTab()
      this.handleSearch()
    },
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
/* 入库列表：主区横向铺满，表格列随宽度拉伸（少列时也能吃满屏宽） */
.inbound-list-page {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}

.inbound-list-page.app-container {
  padding: 10px 8px 16px !important;
}

.inbound-list-page .main-list-card {
  width: 100%;
}

.inbound-list-page .main-list-card :deep(.el-card__body) {
  padding: 8px 10px 12px;
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
  box-sizing: border-box;
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

.inbound-table {
  width: 100%;
  min-width: 100%;
}

:deep(.inbound-table.el-table) {
  --el-table-border-color: var(--el-border-color-lighter);
}

:deep(.inbound-table .el-table__header th) {
  padding: 13px 10px;
}

:deep(.inbound-table .el-table__body td) {
  padding: 12px 10px;
}

:deep(.inbound-table .el-table__body tr:hover > td) {
  background-color: var(--el-fill-color-lighter) !important;
}

.contenter {
  margin-top: 10px;
}
</style>
