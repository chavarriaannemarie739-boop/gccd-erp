<template>
  <!-- [AI-2026-08] 库存圆钢统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <el-alert
      v-if="showGlobalAlertBanner"
      class="stock-alert-banner"
      type="error"
      :closable="false"
      show-icon
      :title="`当前有 ${alertSummary.total_count} 种物料库存低于阈值（配件 ${alertSummary.accessory_count} / 圆钢 ${alertSummary.round_steel_count}）`"
    />
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
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
            v-model="query.manufacturer"
            placeholder="厂商"
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
            v-model="query.material_batch"
            placeholder="物料批次"
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
        <el-form-item v-if="isStockTab" class="filter-form-item--field">
          <el-checkbox v-model="stockAlertOnly" @change="handleStockAlertFilter"
            >仅显示库存预警</el-checkbox
          >
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
          <el-button v-preventReClick type="primary" size="small" @click="mateiallist"
            >搜索</el-button
          >
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
      <el-card>
        <el-tabs v-model="activeName" type="card" @tab-change="onWarehouseTabChange">
          <!-- [AI-2026-08] 按库存仓库权限显隐 Tab -->
          <el-tab-pane v-if="canInventoryTab('配件')" :label="accessoryTabLabel" name="配件库">
            <div class="inventory-actions">
              <el-button
                type="warning"
                :disabled="selectedMaterialCount === 0"
                @click="openStockThresholdDialog"
              >
                批量设置最低库存
              </el-button>
            </div>
            <el-table
              ref="accessoryTableRef"
              :data="tableData"
              border
              fit
              style="width: 100%"
              :header-cell-style="{ 'text-align': 'center' }"
              :cell-style="{ 'text-align': 'center' }"
              :row-class-name="stockAlertRowClass"
              v-loading="loading"
              :row-key="getRowKeys"
              @selection-change="handleSelectionChange"
            >
              <el-table-column
                type="selection"
                align="center"
                :reserve-selection="true"
                width="40"
              />
              <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
              <el-table-column prop="material_batch" label="批次号" show-overflow-tooltip />
              <el-table-column prop="material_name" label="物料名称" show-overflow-tooltip />
              <el-table-column prop="material_spec" label="物料规格" show-overflow-tooltip />
              <el-table-column prop="material_model" label="物料型号" show-overflow-tooltip />
              <el-table-column prop="supplier" label="供应商" show-overflow-tooltip />
              <el-table-column prop="location" label="库位" show-overflow-tooltip />
              <el-table-column prop="warehouse" label="仓库" show-overflow-tooltip />
              <el-table-column label="本批数量" width="120">
                <template #default="{ row }">
                  <span>{{ row.material_num }}</span>
                  <el-tag
                    v-if="row.is_stock_alert === 1"
                    type="danger"
                    size="small"
                    style="margin-left: 4px"
                    >不足</el-tag
                  >
                </template>
              </el-table-column>
              <el-table-column prop="total_stock" label="物料合计" width="100" />
              <el-table-column label="最低库存" width="100">
                <template #default="{ row }">
                  {{ row.min_stock_qty ?? '—' }}
                </template>
              </el-table-column>
              <el-table-column label="库存状态" width="100">
                <template #default="{ row }">
                  <el-tag v-if="row.is_stock_alert === 1" type="danger" size="small">不足</el-tag>
                  <el-tag v-else-if="row.min_stock_qty" type="success" size="small">正常</el-tag>
                  <span v-else>—</span>
                </template>
              </el-table-column>
              <el-table-column prop="money" label="单价" show-overflow-tooltip />
              <el-table-column prop="sum" label="总价" show-overflow-tooltip />
              <el-table-column prop="create_time" label="入库时间" show-overflow-tooltip />
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
          </el-tab-pane>
          <el-tab-pane v-if="canInventoryTab('圆钢')" :label="roundSteelTabLabel" name="圆钢库">
            <div class="inventory-actions">
              <el-button
                type="warning"
                :disabled="selectedMaterialCount === 0"
                @click="openStockThresholdDialog"
              >
                批量设置最低库存
              </el-button>
            </div>
            <el-table
              ref="roundSteelTableRef"
              :data="tableData"
              border
              fit
              style="width: 100%"
              :header-cell-style="{ 'text-align': 'center' }"
              :cell-style="{ 'text-align': 'center' }"
              :row-class-name="stockAlertRowClass"
              v-loading="loading"
              :row-key="getRowKeys"
              @selection-change="handleSelectionChange"
            >
              <el-table-column
                type="selection"
                align="center"
                :reserve-selection="true"
                width="40"
              />
              <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
              <el-table-column prop="material_batch" label="批次号" show-overflow-tooltip />
              <el-table-column
                prop="material_name"
                label="圆钢名称"
                min-width="120"
                show-overflow-tooltip
              />
              <el-table-column
                prop="manufacturer"
                label="厂商"
                min-width="120"
                show-overflow-tooltip
              />
              <el-table-column
                prop="material_spec"
                label="材质"
                min-width="120"
                show-overflow-tooltip
              />
              <el-table-column
                prop="material_model"
                label="型号"
                min-width="120"
                show-overflow-tooltip
              />
              <el-table-column prop="location" label="库位" min-width="100" show-overflow-tooltip />
              <el-table-column label="本批数量" width="120">
                <template #default="{ row }">
                  <span>{{ row.material_num }}</span>
                  <el-tag
                    v-if="row.is_stock_alert === 1"
                    type="danger"
                    size="small"
                    style="margin-left: 4px"
                    >不足</el-tag
                  >
                </template>
              </el-table-column>
              <el-table-column prop="total_stock" label="物料合计" width="100" />
              <el-table-column label="最低库存" width="100">
                <template #default="{ row }">
                  {{ row.min_stock_qty ?? '—' }}
                </template>
              </el-table-column>
              <el-table-column label="库存状态" width="100">
                <template #default="{ row }">
                  <el-tag v-if="row.is_stock_alert === 1" type="danger" size="small">不足</el-tag>
                  <el-tag v-else-if="row.min_stock_qty" type="success" size="small">正常</el-tag>
                  <span v-else>—</span>
                </template>
              </el-table-column>
              <el-table-column prop="money" label="单价" show-overflow-tooltip />
              <el-table-column prop="sum" label="总价" show-overflow-tooltip />
              <el-table-column prop="inbound_date" label="入库日期" show-overflow-tooltip />
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
          </el-tab-pane>
          <el-tab-pane v-if="canInventoryTab('成品')" label="成品库" name="成品库">
            <el-table
              ref="productTableRef"
              :data="tableData"
              border
              fit
              style="width: 100%"
              :header-cell-style="{ 'text-align': 'center' }"
              :cell-style="{ 'text-align': 'center' }"
              v-loading="loading"
              :row-key="getProductRowKeys"
              @selection-change="handleSelectionChange"
            >
              <el-table-column
                type="selection"
                align="center"
                :reserve-selection="true"
                width="40"
              />
              <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
              <el-table-column prop="material_batch" label="批次号" show-overflow-tooltip />
              <el-table-column prop="relation_code" label="计划单" show-overflow-tooltip />
              <el-table-column
                prop="production_plan_name"
                label="计划名称"
                min-width="120"
                show-overflow-tooltip
              />
              <el-table-column
                prop="stock_owner"
                label="归属"
                min-width="100"
                show-overflow-tooltip
              />
              <el-table-column prop="y_material_name" label="圆钢名称" show-overflow-tooltip />
              <el-table-column
                prop="y_material_manufacturer"
                label="圆钢厂商"
                show-overflow-tooltip
              />
              <el-table-column prop="y_material_spec" label="圆钢材质" show-overflow-tooltip />
              <el-table-column prop="y_material_model" label="圆钢型号" show-overflow-tooltip />
              <el-table-column prop="product_info" label="成品信息" show-overflow-tooltip />
              <el-table-column prop="in_num" label="入库数量" width="100" show-overflow-tooltip />
              <el-table-column prop="shipped_num" label="已发数" width="90" show-overflow-tooltip />
              <el-table-column prop="stock_num" label="库存数" width="90" show-overflow-tooltip />
              <el-table-column prop="create_time" label="入库时间" show-overflow-tooltip />
              <el-table-column label="操作" width="180" fixed="right">
                <template #default="{ row }">
                  <el-button
                    v-if="
                      stockPermissions.canTransfer && row.stock_owner && row.stock_owner !== '库存'
                    "
                    type="primary"
                    link
                    size="small"
                    @click="openTransferDialog(row)"
                  >
                    转库存
                  </el-button>
                  <el-button
                    v-if="stockPermissions.canWriteOff"
                    type="danger"
                    link
                    size="small"
                    @click="openWriteOffDialog(row)"
                  >
                    清零
                  </el-button>
                </template>
              </el-table-column>
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
          </el-tab-pane>
        </el-tabs>
      </el-card>
    </div>
    <el-dialog
      v-model="stockThresholdVisible"
      title="批量设置最低库存"
      width="460px"
      :close-on-click-modal="false"
    >
      <!-- [AI-2026-08] 按选中批次的 material_id 去重，阈值归属当前库存 Tab。 -->
      <p class="stock-threshold-tip">
        当前{{ activeName }}已选 {{ selectList.length }} 个批次，共
        {{ selectedMaterialCount }} 种物料；留空或填 0 表示删除阈值。
      </p>
      <el-form label-width="100px">
        <el-form-item label="最低库存">
          <el-input-number
            v-model="stockThresholdQty"
            :min="0"
            :precision="3"
            :step="0.001"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="stockThresholdVisible = false">取消</el-button>
        <el-button type="primary" v-preventReClick @click="submitStockThreshold"> 确定 </el-button>
      </template>
    </el-dialog>
    <el-dialog v-model="transferVisible" title="转库存" width="480px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="计划单">
          <span>{{ transferForm.plan_code }}</span>
        </el-form-item>
        <el-form-item label="当前归属">
          <span>{{ transferForm.stock_owner }}</span>
        </el-form-item>
        <el-form-item label="可用库存">
          <span>{{ transferForm.stock_num }} 吨</span>
        </el-form-item>
        <el-form-item label="转库存数量" required>
          <el-input-number
            v-model="transferForm.transfer_qty"
            :min="0.01"
            :max="transferForm.stock_num"
            :precision="2"
            :step="0.01"
            controls-position="right"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="transferForm.reason" type="textarea" placeholder="可选" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="transferVisible = false">取消</el-button>
        <el-button type="primary" v-preventReClick @click="submitTransfer">确认</el-button>
      </template>
    </el-dialog>
    <el-dialog
      v-model="writeOffVisible"
      title="库存清零"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form label-width="100px">
        <el-form-item label="计划单">
          <span>{{ writeOffForm.plan_code }}</span>
        </el-form-item>
        <el-form-item label="归属">
          <span>{{ writeOffForm.stock_owner }}</span>
        </el-form-item>
        <el-form-item label="当前库存">
          <span>{{ writeOffForm.stock_num }} 吨</span>
        </el-form-item>
        <el-form-item label="清零数量" required>
          <el-input-number
            v-model="writeOffForm.write_off_qty"
            :min="0.01"
            :max="writeOffForm.stock_num"
            :precision="2"
            :step="0.01"
            controls-position="right"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="原因" required>
          <el-input
            v-model="writeOffForm.reason"
            type="textarea"
            placeholder="必填，如：账实不符/现场已无货"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="writeOffVisible = false">取消</el-button>
        <el-button type="danger" v-preventReClick @click="submitWriteOff">确认清零</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { ElMessage } from 'element-plus'
import {
  batchUpdateStockThreshold,
  mateiallist,
  stockAlertSummary,
  transferToStock,
  writeOffStock
} from '@/api/mes/WarehouseManagement/WarehouseManagement'
import { useStockAlertStore } from '@/store/modules/stockAlert'
import { canWarehouse, pickDefaultTab, visibleWarehouseTabs } from '@/utils/warehouseScope'

export default {
  name: 'MaterialInventory',
  data() {
    return {
      type: true,
      activeName: '配件库',
      view: {},
      selectList: [],
      order_times: '',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        material_name: '',
        manufacturer: '',
        material_spec: '',
        material_model: '',
        location: '',
        warehouse: '配件库',
        material_batch: '',
        create_start_date: '',
        create_end_date: '',
        stock_alert_only: ''
      },
      loading: true,
      stockAlertOnly: false,
      tabAlertCount: 0,
      alertSummary: {
        accessory_count: 0,
        round_steel_count: 0,
        total_count: 0
      },
      stockPermissions: {
        canTransfer: false,
        canWriteOff: false,
        isSuper: false
      },
      stockThresholdVisible: false,
      stockThresholdQty: null,
      transferVisible: false,
      writeOffVisible: false,
      transferForm: {
        plan_code: '',
        stock_owner: '',
        stock_num: 0,
        transfer_qty: null,
        reason: ''
      },
      writeOffForm: {
        plan_code: '',
        stock_owner: '',
        stock_num: 0,
        write_off_qty: null,
        reason: ''
      }
    }
  },
  computed: {
    inventoryTabDefs() {
      return [
        { type: '配件', name: '配件库', label: '配件库' },
        { type: '圆钢', name: '圆钢库', label: '圆钢库' },
        { type: '成品', name: '成品库', label: '成品库' }
      ]
    },
    visibleInventoryTabs() {
      return visibleWarehouseTabs('inventory', this.inventoryTabDefs)
    },
    isStockTab() {
      return this.activeName === '配件库' || this.activeName === '圆钢库'
    },
    selectedMaterialCount() {
      return new Set(
        this.selectList
          .map((item) => Number(item.material_id))
          .filter((materialId) => materialId > 0)
      ).size
    },
    accessoryTabLabel() {
      const n = this.alertSummary.accessory_count || 0
      return n > 0 ? `配件库 (${n})` : '配件库'
    },
    roundSteelTabLabel() {
      const n = this.alertSummary.round_steel_count || 0
      return n > 0 ? `圆钢库 (${n})` : '圆钢库'
    },
    showGlobalAlertBanner() {
      return (this.alertSummary.total_count || 0) > 0
    }
  },
  // [AI-2026-07] keep-alive 白名单外首次进入不触发 activated，需 mounted 拉数
  mounted() {
    this.applyInventoryTabScope()
    this.query.warehouse = this.activeName
    this.loadAlertSummary()
    this.mateiallist()
  },
  activated() {
    this.applyInventoryTabScope()
    this.query.warehouse = this.activeName
    this.loadAlertSummary()
    this.mateiallist()
  },
  methods: {
    canInventoryTab(type) {
      return canWarehouse('inventory', type)
    },
    applyInventoryTabScope() {
      this.activeName = pickDefaultTab(this.visibleInventoryTabs, this.activeName)
    },
    async loadAlertSummary() {
      try {
        const resp = await stockAlertSummary({})
        this.alertSummary = resp.data || this.alertSummary
        const stockAlertStore = useStockAlertStore()
        stockAlertStore.accessoryCount = this.alertSummary.accessory_count || 0
        stockAlertStore.roundSteelCount = this.alertSummary.round_steel_count || 0
        stockAlertStore.totalCount = this.alertSummary.total_count || 0
        stockAlertStore.loaded = true
      } catch (e) {
        /* 汇总失败不阻断列表 */
      }
    },
    onWarehouseTabChange(name) {
      const tab = name ?? this.activeName
      this.query.warehouse = tab
      this.query.page = 1
      if (tab === '成品库') {
        this.stockAlertOnly = false
        this.query.stock_alert_only = ''
      }
      this.selectList = []
      this.type = true
      this.$nextTick(() => this.clearCurrentStockSelection())
      this.mateiallist()
    },
    stockAlertRowClass({ row }) {
      return row.is_stock_alert === 1 ? 'stock-alert-row' : ''
    },
    handleStockAlertFilter(val) {
      this.query.stock_alert_only = val ? 1 : ''
      this.query.page = 1
      this.mateiallist()
    },
    getRowKeys(row) {
      return row.id
    },
    getProductRowKeys(row) {
      return row.row_key || row.relation_code || row.material_batch || row.id
    },
    handleSelectionChange(val) {
      this.selectList = val
      this.type = val.length === 0
    },
    clearCurrentStockSelection() {
      this.$refs.accessoryTableRef?.clearSelection?.()
      this.$refs.roundSteelTableRef?.clearSelection?.()
    },
    openStockThresholdDialog() {
      if (!this.isStockTab || this.selectedMaterialCount === 0) return
      this.stockThresholdQty = null
      this.stockThresholdVisible = true
    },
    async submitStockThreshold() {
      const materialIds = [
        ...new Set(
          this.selectList
            .map((item) => Number(item.material_id))
            .filter((materialId) => materialId > 0)
        )
      ]
      if (!this.isStockTab || materialIds.length === 0) {
        ElMessage.error('请选择要设置的库存物料')
        return
      }
      try {
        await batchUpdateStockThreshold({
          material_ids: materialIds,
          warehouse: this.activeName,
          min_stock_qty: this.stockThresholdQty
        })
        this.stockThresholdVisible = false
        this.selectList = []
        this.type = true
        await this.$nextTick()
        this.clearCurrentStockSelection()
        await Promise.all([this.mateiallist(), this.loadAlertSummary()])
        ElMessage.success(this.stockThresholdQty > 0 ? '最低库存设置成功' : '最低库存已删除')
      } catch (e) {
        /* request 拦截器已提示 */
      }
    },
    async mateiallist() {
      this.loading = true
      try {
        const resp = await mateiallist(this.query)
        this.tableData = resp.data?.list || []
        this.total = resp.data?.count || 0
        this.tabAlertCount = resp.data?.alert_count || 0
        if (resp.data?.stockPermissions) {
          this.stockPermissions = resp.data.stockPermissions
        }
      } catch (e) {
        /* request 拦截器已提示；确保关闭转圈 */
      } finally {
        this.loading = false
      }
    },
    openTransferDialog(row) {
      this.transferForm = {
        plan_code: row.relation_code || row.material_batch,
        stock_owner: row.stock_owner,
        stock_num: row.stock_num,
        transfer_qty: row.stock_num,
        reason: ''
      }
      this.transferVisible = true
    },
    async submitTransfer() {
      const qty = Number(this.transferForm.transfer_qty)
      if (!qty || qty <= 0) {
        ElMessage.error('请填写转库存数量')
        return
      }
      try {
        await transferToStock({
          plan_code: this.transferForm.plan_code,
          transfer_qty: qty,
          reason: this.transferForm.reason
        })
        ElMessage.success('转库存成功')
        this.transferVisible = false
        this.mateiallist()
      } catch (e) {
        /* request 拦截器已提示 */
      }
    },
    openWriteOffDialog(row) {
      this.writeOffForm = {
        plan_code: row.relation_code || row.material_batch,
        stock_owner: row.stock_owner,
        stock_num: row.stock_num,
        write_off_qty: row.stock_num,
        reason: ''
      }
      this.writeOffVisible = true
    },
    async submitWriteOff() {
      const qty = Number(this.writeOffForm.write_off_qty)
      if (!qty || qty <= 0) {
        ElMessage.error('请填写清零数量')
        return
      }
      if (!this.writeOffForm.reason.trim()) {
        ElMessage.error('请填写清零原因')
        return
      }
      try {
        await writeOffStock({
          plan_code: this.writeOffForm.plan_code,
          stock_owner: this.writeOffForm.stock_owner,
          write_off_qty: qty,
          reason: this.writeOffForm.reason
        })
        ElMessage.success('清零成功')
        this.writeOffVisible = false
        this.mateiallist()
      } catch (e) {
        /* request 拦截器已提示 */
      }
    },
    handleChange(val) {
      this.order_times = val
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
      this.mateiallist()
    },
    handleCurrentChange(val) {
      this.query.page = val
      this.mateiallist()
    },
    handleResetting() {
      this.query.material_batch = ''
      this.query.manufacturer = ''
      this.query.material_spec = ''
      this.query.material_name = ''
      this.query.material_model = ''
      this.query.location = ''
      this.query.create_start_date = ''
      this.query.create_end_date = ''
      this.query.stock_alert_only = ''
      this.stockAlertOnly = false
      this.order_times = []
      this.query.warehouse = this.activeName
      this.mateiallist()
    }
  }
}
</script>
<style scoped>
.stock-alert-banner {
  margin-bottom: 10px;
}
.inventory-actions {
  margin-bottom: 12px;
}
.stock-threshold-tip {
  margin: 0 0 12px;
  color: #606266;
  font-size: 13px;
  line-height: 1.6;
}
:deep(.stock-alert-row) {
  background-color: #fef0f0 !important;
}
.contenter {
  margin-top: 10px;
}
</style>
