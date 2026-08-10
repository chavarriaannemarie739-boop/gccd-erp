<template>
  <!-- [AI-2026-08] 出库圆钢统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.out_code"
            placeholder="出库编码"
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
            placeholder="物料规格"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_model"
            placeholder="物料型号"
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
          <!-- [AI-2026-08] 按出库仓库权限显隐 Tab -->
          <el-tab-pane v-if="canOutboundTab('配件')" label="配件仓库" name="配件仓库" />
          <el-tab-pane v-if="canOutboundTab('圆钢')" label="圆钢仓库" name="圆钢仓库" />
          <el-tab-pane v-if="canOutboundTab('成品')" label="成品仓库" name="成品仓库" />
        </el-tabs>
        <div class="toolbar-row">
          <el-button type="success" size="small" @click="CheckbelowGoods">新增</el-button>
          <el-button type="danger" size="small" :disabled="type" v-preventReClick @click="dele(row)"
            >删除</el-button
          >
          <el-button
            type="primary"
            size="small"
            :disabled="outboundToolbarDisabled"
            v-preventReClick
            @click="toolbarOutbound"
            >出库</el-button
          >
        </div>
        <p class="table-hint">
          表格列较多时，可在下方横向滚动条拖动查看全部内容（触控板双指滑动亦可）。
        </p>
        <div class="table-scroll-container">
          <el-table
            :key="activeName"
            ref="tableDataRefss"
            class="outbound-table"
            :data="tableData"
            border
            :fit="false"
            :header-cell-style="tableHeaderCellStyle"
            :cell-style="tableCellStyle"
            v-loading="loading"
            :row-key="getRowKeys"
            @selection-change="handleSelectionChange"
          >
            <el-table-column type="selection" align="center" :reserve-selection="true" width="48" />
            <el-table-column type="index" :index="handleIndex" label="序号" width="64" />
            <el-table-column
              prop="out_code"
              label="出库编号"
              min-width="140"
              show-overflow-tooltip
            />
            <el-table-column
              prop="material_name"
              :label="materialNameLabel"
              min-width="120"
              show-overflow-tooltip
            />
            <el-table-column
              v-if="activeName === '圆钢仓库'"
              prop="manufacturer"
              label="厂商"
              min-width="120"
              show-overflow-tooltip
            />
            <el-table-column
              prop="material_spec"
              :label="materialSpecLabel"
              min-width="120"
              show-overflow-tooltip
            />
            <el-table-column
              prop="material_model"
              :label="materialModelLabel"
              min-width="120"
              show-overflow-tooltip
            />
            <el-table-column prop="out_num" label="出库数量" width="100" show-overflow-tooltip />
            <el-table-column
              prop="material_batch"
              label="批次信息"
              min-width="120"
              show-overflow-tooltip
            />
            <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip />
            <el-table-column prop="status" label="审核状态" width="100" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.status === 0">待审核</span>
                <span v-if="row.status === 1">已出库</span>
              </template>
            </el-table-column>
            <el-table-column
              prop="create_name"
              label="创建人"
              min-width="88"
              show-overflow-tooltip
            />
            <el-table-column
              prop="create_time"
              label="创建时间"
              min-width="160"
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
        <AppFormDrawer v-model="processVisible" :title="newOutboundDrawerTitle">
          <NewOutbound :outbound-tab="activeName" v-model:is-show="processVisible" @list="list" />
        </AppFormDrawer>
        <el-dialog
          title="出库"
          v-if="dialogTableVis"
          v-model="dialogTableVis"
          width="40%"
          :close-on-click-modal="false"
        >
          <ModifyBatch
            :processes="selectList"
            v-model:is-show="dialogTableVis"
            @list="list"
            :view="view"
          />
        </el-dialog>
      </el-card>
    </div>
  </div>
</template>

<script>
import { list, dele } from '@/api/mes/WarehouseManagement/OutboundManagement'
import AppFormDrawer from '@/components/AppFormDrawer/index.vue'
import NewOutbound from './components/NewOutbound.vue'
import ModifyBatch from './components/ModifyBatch.vue'
import { canWarehouse, pickDefaultTab, visibleWarehouseTabs } from '@/utils/warehouseScope'
export default {
  name: 'OutboundList',
  components: {
    AppFormDrawer,
    NewOutbound,
    ModifyBatch
  },
  data() {
    return {
      activeName: '配件仓库',
      type: true,
      view: {},
      selectList: [],
      processVisible: false,
      dialogTableVis: false,
      order_times: '',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        out_code: '',
        material_name: '',
        manufacturer: '',
        material_spec: '',
        material_model: '',
        material_batch: '',
        warehouse: '配件',
        create_start_date: '',
        create_end_date: ''
      },
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
    outboundTabDefs() {
      return [
        { type: '配件', name: '配件仓库', label: '配件仓库' },
        { type: '圆钢', name: '圆钢仓库', label: '圆钢仓库' },
        { type: '成品', name: '成品仓库', label: '成品仓库' }
      ]
    },
    visibleOutboundTabs() {
      return visibleWarehouseTabs('outbound', this.outboundTabDefs)
    },
    /** 需勾选一条且为待审核才可出库 */
    outboundToolbarDisabled() {
      if (this.selectList.length !== 1) return true
      return Number(this.selectList[0].status) !== 0
    },
    newOutboundDrawerTitle() {
      return `新增出库 · ${this.activeName}`
    },
    materialNameLabel() {
      return this.activeName === '圆钢仓库' ? '圆钢名称' : '物料名称'
    },
    materialSpecLabel() {
      return this.activeName === '圆钢仓库' ? '材质' : '物料规格'
    },
    materialModelLabel() {
      return this.activeName === '圆钢仓库' ? '型号' : '物料型号'
    }
  },
  // [AI-2026-07] keep-alive 白名单外首次进入不触发 activated，需 mounted 拉数
  mounted() {
    this.applyOutboundTabScope()
    this.syncWarehouseQueryFromTab()
    this.list()
  },
  activated() {
    this.applyOutboundTabScope()
    this.syncWarehouseQueryFromTab()
    this.list()
  },
  methods: {
    canOutboundTab(type) {
      return canWarehouse('outbound', type)
    },
    applyOutboundTabScope() {
      this.activeName = pickDefaultTab(this.visibleOutboundTabs, this.activeName)
    },
    /** 与出库列表 Tab 对应：配件 / 原材料（圆钢档案多为原材料属性，后端同时兼容圆钢） */
    warehouseLikeForTab(tabName) {
      const map = {
        配件仓库: '配件',
        圆钢仓库: '原材料',
        成品仓库: '成品'
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
    CheckbelowGoods() {
      this.processVisible = true
    },
    toolbarOutbound() {
      if (this.selectList.length !== 1) {
        ElMessage.warning('请勾选一条出库记录')
        return
      }
      const row = this.selectList[0]
      if (Number(row.status) !== 0) {
        ElMessage.warning('仅「待审核」的记录可以出库')
        return
      }
      this.view = row
      this.dialogTableVis = true
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
      this.query.out_code = ''
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_spec = ''
      this.query.material_model = ''
      this.query.material_batch = ''
      this.query.create_start_date = ''
      this.query.create_end_date = ''
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

.outbound-table {
  min-width: 1100px;
}

:deep(.outbound-table.el-table) {
  --el-table-border-color: var(--el-border-color-lighter);
}

:deep(.outbound-table .el-table__header th) {
  padding: 13px 10px;
}

:deep(.outbound-table .el-table__body td) {
  padding: 12px 10px;
}

:deep(.outbound-table .el-table__body tr:hover > td) {
  background-color: var(--el-fill-color-lighter) !important;
}

.contenter {
  margin-top: 10px;
}
</style>
