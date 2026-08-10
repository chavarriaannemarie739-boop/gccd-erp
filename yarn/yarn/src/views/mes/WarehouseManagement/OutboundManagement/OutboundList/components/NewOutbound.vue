<template>
  <!-- [AI-2026-08] 出库选料圆钢统一名称、厂商、材质、型号 -->
  <div class="app-drawer-form-panel">
    <!-- 布局类名见 styles/drawer-form-layout.scss -->
    <el-form
      ref="handleRef"
      class="app-drawer-form"
      label-width="100px"
      :model="userForm"
      :rules="handleRules"
    >
      <el-form-item label="出库编号" class="app-drawer-form-row--tight">
        <el-input
          v-model="routing_name"
          disabled
          placeholder="请输入"
          class="new-outbound-input-code"
        />
      </el-form-item>
      <el-form-item label="物料内容" prop="material_spec" class="app-drawer-form-row--actions">
        <el-button type="primary" size="small" @click="addOrderMatter">添加物料</el-button>
      </el-form-item>
      <el-form-item label=" " class="app-drawer-form-row--table" label-width="100px">
        <div class="app-drawer-table-scroll">
          <el-table
            :data="userForm.content"
            border
            fit
            class="new-outbound-table"
            :header-cell-style="{ 'text-align': 'center' }"
            :cell-style="{ 'text-align': 'center' }"
          >
            <el-table-column type="index" label="序号" width="55" />
            <el-table-column
              prop="material_batch"
              label="批次号"
              min-width="120"
              show-overflow-tooltip
            />
            <el-table-column
              prop="material_name"
              :label="materialNameLabel"
              min-width="100"
              show-overflow-tooltip
            />
            <el-table-column
              v-if="isRoundSteel"
              prop="manufacturer"
              label="厂商"
              min-width="100"
              show-overflow-tooltip
            />
            <el-table-column
              prop="material_spec"
              :label="materialSpecLabel"
              min-width="100"
              show-overflow-tooltip
            />
            <el-table-column
              prop="material_model"
              :label="materialModelLabel"
              min-width="100"
              show-overflow-tooltip
            />
            <el-table-column prop="supplier" label="供应商" min-width="88" show-overflow-tooltip />
            <el-table-column prop="location" label="库位" min-width="88" show-overflow-tooltip />
            <el-table-column prop="warehouse" label="仓库" min-width="88" show-overflow-tooltip />
            <el-table-column
              prop="material_num"
              :label="stockQtyColumnLabel"
              width="100"
              show-overflow-tooltip
            />
            <el-table-column prop="money" label="单价" min-width="88" show-overflow-tooltip />
            <el-table-column prop="sum" label="总价" min-width="88" show-overflow-tooltip />
            <el-table-column
              prop="create_time"
              label="入库时间"
              min-width="150"
              show-overflow-tooltip
            />
            <el-table-column prop="out_num" label="出库数量" width="120">
              <template #default="{ row }">
                <el-input v-model="row.out_num" placeholder="请输入" style="width: 100px" />
              </template>
            </el-table-column>
            <el-table-column prop="remark" label="备注" width="120">
              <template #default="{ row }">
                <el-input v-model="row.remark" placeholder="请输入" style="width: 100px" />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="88" fixed="right">
              <template #default="scope">
                <el-button type="danger" plain @click="delOrderMatter(scope.$index, scope.row)"
                  >删除</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </div>
      </el-form-item>
    </el-form>
    <el-dialog
      v-model="processVisible"
      v-if="processVisible"
      width="min(1100px, 96vw)"
      top="4vh"
      append-to-body
      :close-on-click-modal="false"
      title="选择库存物料"
      class="new-outbound-pick-stock-dialog"
    >
      <el-row :gutter="24">
        <el-col :span="6">
          <el-form-item :label="`${materialNameLabel}：`">
            <el-input style="width: 260px" placeholder="请输入" v-model="query.material_name" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item v-if="isRoundSteel" label="厂商：">
            <el-input style="width: 260px" placeholder="请输入" v-model="query.manufacturer" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item :label="`${materialSpecLabel}：`">
            <el-input
              style="width: 260px"
              placeholder="请输入"
              v-model="query.material_spec"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="物料批次：">
            <el-input
              style="width: 260px"
              placeholder="请输入"
              v-model="query.material_batch"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item>
            <el-button v-preventReClick type="primary" @click="onSearchMateiallist">搜索</el-button>
            <el-button v-preventReClick @click="handleGoods">重置</el-button>
          </el-form-item>
        </el-col>
      </el-row>
      <el-table
        :border="true"
        :show-overflow-tooltip="true"
        :data="processes"
        v-loading="loading"
        stripe
        :row-key="getRowKeys"
        ref="tableDataRef"
        :header-cell-style="{ 'text-align': 'center' }"
        :cell-style="{ 'text-align': 'center' }"
        @selection-change="handleSelectionChange"
      >
        <el-table-column show-overflow-tooltip :reserve-selection="true" type="selection" />
        <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
        <el-table-column prop="material_batch" label="批次号" show-overflow-tooltip />
        <el-table-column prop="material_name" :label="materialNameLabel" show-overflow-tooltip />
        <el-table-column
          v-if="isRoundSteel"
          prop="manufacturer"
          label="厂商"
          show-overflow-tooltip
        />
        <el-table-column prop="material_spec" :label="materialSpecLabel" show-overflow-tooltip />
        <el-table-column prop="material_model" :label="materialModelLabel" show-overflow-tooltip />
        <el-table-column prop="supplier" label="供应商" show-overflow-tooltip />
        <el-table-column prop="location" label="库位" show-overflow-tooltip />
        <el-table-column prop="warehouse" label="仓库" show-overflow-tooltip />
        <el-table-column prop="material_num" :label="stockQtyColumnLabel" show-overflow-tooltip />
        <el-table-column prop="money" label="单价" show-overflow-tooltip />
        <el-table-column prop="sum" label="总价" show-overflow-tooltip />
        <el-table-column prop="create_time" label="入库时间" show-overflow-tooltip />
      </el-table>
      <!-- 分页（与物料库存一致） -->
      <el-pagination
        style="margin-top: 10px"
        :page-sizes="[30, 60, 100, 200]"
        :current-page="query.page"
        :page-size="query.limit"
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        background
        @size-change="handleSizeChangeGoods"
        @current-change="handleCurrentChangeGoods"
      />
      <template #footer>
        <div class="dialog-footer">
          <el-button @click="processVisible = false">取消</el-button>
          <el-button type="primary" v-preventReClick @click="processSubmit"> 确认 </el-button>
        </div>
      </template>
    </el-dialog>
    <div class="app-drawer-form-footer">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { add } from '@/api/mes/WarehouseManagement/OutboundManagement'
import { mateiallist } from '@/api/mes/WarehouseManagement/WarehouseManagement'

/** 与「物料管理-物料库存」各 Tab 的 query.warehouse 取值一致（后端 stock LIKE） */
const MATEIALLIST_WAREHOUSE = {
  配件仓库: '配件库',
  圆钢仓库: '原料库'
}

const toNumber = (v) => {
  const n = Number(String(v ?? '').trim())
  return Number.isFinite(n) ? n : NaN
}

/** 配件仓库：出库数量不得大于所选库存行的库存数 material_num */
const validateAccessoryOutNumNotOverStock = (rows, outboundTab) => {
  if (outboundTab !== '配件仓库') return { ok: true }
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i]
    const out = toNumber(row.out_num)
    const stock = toNumber(row.material_num)
    if (!Number.isFinite(out) || out <= 0) {
      return {
        ok: false,
        message: `第 ${i + 1} 行：请填写大于 0 的出库数量`
      }
    }
    if (!Number.isFinite(stock)) {
      return {
        ok: false,
        message: `第 ${i + 1} 行：无法读取库存数量，请删除该行后重新从库存选择`
      }
    }
    if (out > stock) {
      const name = row.material_name ? `「${row.material_name}」` : ''
      return {
        ok: false,
        message: `第 ${i + 1} 行${name}出库数量（${out}）不能大于库存数量（${stock}）`
      }
    }
  }
  return { ok: true }
}

export default {
  name: 'NewOutbound',
  props: {
    isShow: {
      type: Boolean,
      default: false
    },
    /** 与出库列表 Tab 一致：配件仓库 | 圆钢仓库 */
    outboundTab: {
      type: String,
      default: '配件仓库'
    }
  },
  data() {
    return {
      routing_name: '后台自动生成',
      processVisible: false,
      loading: true,
      processes: [],
      total: 0,
      selection: [],
      userForm: {
        AdminToken: '',
        out_code: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 30,
        material_name: '',
        manufacturer: '',
        material_spec: '',
        material_model: '',
        material_batch: '',
        warehouse: ''
      },
      handleRules: {
        routing_name: [{ required: true, message: '工艺名称不能为空', trigger: 'blur' }]
      }
    }
  },
  computed: {
    /** 与物料库存-配件库表头一致：库存数量；圆钢库（原原料库）表头为「数量」 */
    stockQtyColumnLabel() {
      return this.outboundTab === '圆钢仓库' ? '数量' : '库存数量'
    },
    isRoundSteel() {
      return this.outboundTab === '圆钢仓库'
    },
    materialNameLabel() {
      return this.isRoundSteel ? '圆钢名称' : '物料名称'
    },
    materialSpecLabel() {
      return this.isRoundSteel ? '材质' : '物料规格'
    },
    materialModelLabel() {
      return this.isRoundSteel ? '型号' : '物料型号'
    }
  },
  methods: {
    syncMateiallistWarehouse() {
      this.query.warehouse =
        MATEIALLIST_WAREHOUSE[this.outboundTab] ?? MATEIALLIST_WAREHOUSE['配件仓库']
    },
    addOrderMatter() {
      this.query.page = 1
      this.syncMateiallistWarehouse()
      this.processVisible = true
      this.mateiallist()
    },
    onSearchMateiallist() {
      this.query.page = 1
      this.mateiallist()
    },
    handleSelectionChange(selection) {
      this.selection = selection
    },
    handleSizeChangeGoods(val) {
      this.query.limit = val
      this.mateiallist()
    },
    handleCurrentChangeGoods(val) {
      this.query.page = val
      this.mateiallist()
    },
    handleGoods() {
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_spec = ''
      this.query.material_model = ''
      this.query.material_batch = ''
      this.query.page = 1
      this.syncMateiallistWarehouse()
      this.mateiallist()
    },
    getRowKeys(row) {
      return row.id
    },
    rowContentFromStockItem(item) {
      return {
        id: item.id,
        material_id: item.material_id,
        material_batch: item.material_batch,
        material_name: item.material_name,
        manufacturer: item.manufacturer,
        material_spec: item.material_spec,
        material_model: item.material_model,
        supplier: item.supplier,
        location: item.location,
        warehouse: item.warehouse,
        material_num: item.material_num,
        money: item.money,
        sum: item.sum,
        create_time: item.create_time,
        out_num: '',
        remark: ''
      }
    },
    processSubmit() {
      if (!this.selection.length) {
        ElMessage.warning('请勾选要添加的物料')
        return
      }
      for (const item of this.selection) {
        const exists = this.userForm.content.some((row) => row.id === item.id)
        if (exists) {
          ElMessage.error('当前物料已存在，请重新选择！')
          return
        }
        this.userForm.content.push(this.rowContentFromStockItem(item))
      }
      this.selection = []
      this.$refs.tableDataRef?.clearSelection?.()
      this.processVisible = false
    },
    async mateiallist() {
      this.syncMateiallistWarehouse()
      this.loading = true
      const resp = await mateiallist(this.query)
      this.processes = resp.data.list
      this.total = resp.data.count
      this.loading = false
    },
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        if (!value) return
        try {
          const qtyCheck = validateAccessoryOutNumNotOverStock(
            this.userForm.content,
            this.outboundTab
          )
          if (!qtyCheck.ok) {
            ElMessage.error(qtyCheck.message)
            return
          }
          for (const item of this.userForm.content) {
            const mid = item.material_id
            if (mid === undefined || mid === null || mid === '') {
              ElMessage.error('数据异常：未找到物料编号，请删除该行后重新从库存选择')
              return
            }
          }
          const params = {
            list: {
              out_code: this.userForm.out_code,
              content: []
            }
          }
          this.userForm.content.forEach((item) => {
            params.list.content.push({
              material_id: item.material_id,
              material_name: item.material_name,
              manufacturer: item.manufacturer,
              material_spec: item.material_spec,
              material_model: item.material_model,
              material_batch: item.material_batch ?? '',
              remark: item.remark,
              out_num: item.out_num
            })
          })
          await add(params)
          this.$emit('list')
          this.hanleAddDialogClose()
          ElMessage({
            message: '操作成功',
            type: 'success'
          })
        } catch (err) {
          //
        }
      })
    },
    delOrderMatter(index) {
      this.userForm.content.splice(index, 1)
    }
  }
}
</script>

<style scoped>
.app-drawer-form :deep(.el-form-item) {
  margin-bottom: 12px;
}

.new-outbound-input-code {
  max-width: min(320px, 100%);
}

.new-outbound-table {
  min-width: 960px;
}
</style>
