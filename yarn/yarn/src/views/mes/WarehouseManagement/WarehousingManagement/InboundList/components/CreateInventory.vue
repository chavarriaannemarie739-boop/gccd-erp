<template>
  <!-- [AI-2026-08] 入库选料圆钢统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <!-- 表单（紧凑布局） -->
    <el-form ref="handleRef" label-width="100px" :model="userForm" :rules="handleRules">
      <el-row :gutter="16">
        <el-col :span="6">
          <el-form-item label="入库编号：">
            <el-input v-model="routing_name" disabled placeholder="自动生成" size="small" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="仓库：" prop="warehouse">
            <el-select
              v-model="userForm.warehouse"
              filterable
              placeholder="请选择"
              size="small"
              style="width: 100%"
              @change="onWarehouseChange"
            >
              <el-option
                v-for="item in warehouseOptions"
                :key="item.stock_code"
                :label="item.stock_name"
                :value="item.stock_name"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="库位：">
            <el-input v-model="userForm.location" placeholder="请输入" size="small" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="入库日期：">
            <el-date-picker
              v-model="userForm.inbound_date"
              type="date"
              placeholder="实际到厂日期"
              value-format="YYYY-MM-DD"
              size="small"
              style="width: 100%"
              :default-value="new Date()"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="物料内容：" prop="material_spec">
        <el-button round type="primary" style="margin-left: 20px" @click="addOrderMatter"
          >添加物料</el-button
        >
        <el-table
          :data="userForm.content"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <el-table-column type="index" label="序号" width="55" />
          <el-table-column prop="material_name" :label="nameColLabel" min-width="100" />
          <el-table-column
            v-if="warehouseMaterialKind === '圆钢'"
            prop="manufacturer"
            label="厂商"
            min-width="100"
          />
          <el-table-column prop="material_spec" :label="specColLabel" min-width="100" />
          <el-table-column prop="material_model" :label="modelColLabel" min-width="100" />
          <el-table-column v-if="warehouseMaterialKind === '配件'" label="供应商" min-width="120">
            <template #default="{ row }">
              <el-input v-model="row.supplier" placeholder="供应商名称" style="width: 118px" />
            </template>
          </el-table-column>
          <el-table-column prop="in_num" label="入库数量" width="118">
            <template #default="{ row }">
              <el-input v-model="row.in_num" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="money" label="单价" width="118">
            <template #default="{ row }">
              <el-input v-model="row.money" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="sum" label="总额" width="118">
            <template #default="{ row }">
              <el-input v-model="row.sum" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" min-width="100">
            <template #default="{ row }">
              <el-input v-model="row.remark" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="100">
            <template #default="scope">
              <el-button type="danger" plain @click="delOrderMatter(scope.$index, scope.row)"
                >删除</el-button
              >
            </template>
          </el-table-column>
        </el-table>
      </el-form-item>
    </el-form>
    <el-dialog
      v-model="processVisible"
      v-if="processVisible"
      width="70%"
      :title="materialPickerTitle"
      :close-on-click-modal="false"
    >
      <el-row :gutter="24">
        <el-col :span="6">
          <el-form-item :label="pickerNameLabel">
            <el-input v-model="query.material_name" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item v-if="warehouseMaterialKind === '圆钢'" label="厂商">
            <el-input v-model="query.manufacturer" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item :label="pickerSpecLabel">
            <el-input
              v-model="query.material_spec"
              style="width: 260px"
              placeholder="请输入"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item :label="pickerModelLabel">
            <el-input
              v-model="query.material_model"
              style="width: 260px"
              placeholder="请输入"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item>
            <el-button v-preventReClick type="primary" @click="handlePickerSearch">搜索</el-button>
            <el-button v-preventReClick @click="handlePickerReset">重置</el-button>
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
        <el-table-column
          prop="material_name"
          :label="pickerNameLabel"
          show-overflow-tooltip
          min-width="100"
        />
        <el-table-column
          v-if="warehouseMaterialKind === '圆钢'"
          prop="manufacturer"
          label="厂商"
          show-overflow-tooltip
          min-width="100"
        />
        <el-table-column
          prop="material_spec"
          :label="pickerSpecLabel"
          show-overflow-tooltip
          min-width="100"
        />
        <el-table-column
          prop="material_model"
          :label="pickerModelLabel"
          show-overflow-tooltip
          min-width="100"
        />
        <el-table-column
          prop="create_time"
          label="创建时间"
          show-overflow-tooltip
          min-width="160"
        />
      </el-table>
      <!-- 分页 -->
      <el-pagination
        style="margin-top: 10px"
        :page-sizes="[10, 30, 50, 100]"
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
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { add } from '@/api/mes/WarehouseManagement/WarehouseManagement'
import { listGoods } from '@/api/mes/WarehouseManagement/RepositoryList'
import { list } from '@/api/mes/MaterialManagement/MaterialManagement'

/** 与物料档案一致：圆钢档案 material_property=圆钢，配件档案=配件 */
const materialPropertyFromStockName = (stockName) => {
  if (!stockName) {
    return null
  }
  if (/成品/.test(stockName)) {
    return null
  }
  if (/圆钢|原料/.test(stockName)) {
    return '圆钢'
  }
  if (/配件/.test(stockName)) {
    return '配件'
  }
  return null
}

const isFinishedStockName = (stockName) => stockName && /成品/.test(stockName)

export default {
  name: 'CreateInventory',
  props: {
    isShow: {
      type: Boolean,
      default: false
    },
    /** 入库列表当前 Tab：配件仓库 | 圆钢仓库 | 成品库 */
    inboundTab: {
      type: String,
      default: '配件仓库'
    }
  },
  data() {
    return {
      options: [],
      routing_name: '后台自动生成',
      processVisible: false,
      loading: true,
      processes: [],
      total: 0,
      selection: [],
      userForm: {
        AdminToken: '',
        in_code: '',
        location: '',
        warehouse: '',
        inbound_date: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        material_name: '',
        manufacturer: '',
        material_spec: '',
        material_model: '',
        material_property: ''
      },
      queryGoods: {
        AdminToken: '',
        page: 1,
        limit: 100000000
      },
      handleRules: {
        warehouse: [{ required: true, message: '请选择仓库', trigger: 'change' }]
      }
    }
  },
  computed: {
    warehouseOptions() {
      return (this.options || []).filter(
        (o) => o && o.stock_name && !isFinishedStockName(o.stock_name)
      )
    },
    warehouseMaterialKind() {
      return materialPropertyFromStockName(this.userForm.warehouse)
    },
    nameColLabel() {
      if (this.warehouseMaterialKind === '圆钢') return '圆钢名称'
      if (this.warehouseMaterialKind === '配件') return '配件名称'
      return '物料名称'
    },
    specColLabel() {
      if (this.warehouseMaterialKind === '圆钢') return '材质'
      if (this.warehouseMaterialKind === '配件') return '配件规格'
      return '物料规格'
    },
    modelColLabel() {
      if (this.warehouseMaterialKind === '圆钢') return '圆钢型号'
      if (this.warehouseMaterialKind === '配件') return '配件型号'
      return '型号'
    },
    pickerNameLabel() {
      return this.nameColLabel
    },
    pickerSpecLabel() {
      return this.specColLabel
    },
    pickerModelLabel() {
      return this.modelColLabel
    },
    materialPickerTitle() {
      const p = this.warehouseMaterialKind
      if (p === '圆钢') {
        return '选择物料（圆钢档案）'
      }
      if (p === '配件') {
        return '选择物料（配件档案）'
      }
      return '选择物料'
    }
  },
  async mounted() {
    await this.listGoods()
    this.applyDefaultWarehouse()
  },
  methods: {
    applyDefaultWarehouse() {
      const opts = this.warehouseOptions
      if (!opts.length) {
        return
      }
      const tab = this.inboundTab
      let pick = null
      if (tab === '圆钢仓库') {
        pick = opts.find((o) => /圆钢|原料/.test(o.stock_name))
      } else if (tab === '配件仓库') {
        pick = opts.find((o) => /配件/.test(o.stock_name))
      }
      this.userForm.warehouse = (pick || opts[0]).stock_name
    },
    onWarehouseChange() {
      if (this.userForm.content.length) {
        this.userForm.content = []
        ElMessage.info('已切换仓库，已清空已选物料')
      }
    },
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.options = resp.data.list || []
    },
    addOrderMatter() {
      if (!this.userForm.warehouse) {
        ElMessage.warning('请先选择仓库')
        return
      }
      const prop = materialPropertyFromStockName(this.userForm.warehouse)
      if (!prop) {
        ElMessage.warning('成品仓库不能手动添加入库物料，成品入库由酸洗工序报工自动生成')
        return
      }
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_spec = ''
      this.query.material_model = ''
      this.query.page = 1
      this.processVisible = true
      this.list()
    },
    handlePickerSearch() {
      this.query.page = 1
      this.list()
    },
    handlePickerReset() {
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_spec = ''
      this.query.material_model = ''
      this.query.page = 1
      this.list()
    },
    handleSelectionChange(selection) {
      this.selection = selection
    },
    handleSizeChangeGoods(val) {
      this.query.limit = val
      this.list()
    },
    handleCurrentChangeGoods(val) {
      this.query.page = val
      this.list()
    },
    getRowKeys(row) {
      return row.id
    },
    processSubmit() {
      if (!this.selection.length) {
        ElMessage.warning('请选择物料')
        return
      }
      for (const item of this.selection) {
        const exists = this.userForm.content.some((row) => row.id === item.id)
        if (exists) {
          ElMessage.error('当前物料已存在，请重新选择')
          return
        }
        this.userForm.content.push({
          material_name: item.material_name,
          manufacturer: item.manufacturer ?? '',
          material_spec: item.material_spec,
          material_model: item.material_model ?? '',
          id: item.id,
          supplier: '',
          in_num: '',
          money: '',
          sum: '',
          remark: ''
        })
      }
      this.selection = []
      this.$refs.tableDataRef?.clearSelection?.()
      this.processVisible = false
    },
    async list() {
      const prop = materialPropertyFromStockName(this.userForm.warehouse)
      if (!prop) {
        this.loading = false
        this.processes = []
        this.total = 0
        return
      }
      this.loading = true
      const resp = await list({
        ...this.query,
        material_property: prop
      })
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
      if (!this.userForm.content.length) {
        ElMessage.warning('请添加物料')
        return
      }
      this.$refs.handleRef.validate(async (value) => {
        if (!value) return
        try {
          const params = {
            list: {
              in_code: this.userForm.in_code,
              warehouse: this.userForm.warehouse,
              location: this.userForm.location,
              inbound_date: this.userForm.inbound_date || '',
              content: []
            }
          }
          const isAccessory = this.warehouseMaterialKind === '配件'
          this.userForm.content.forEach((item) => {
            params.list.content.push({
              material_id: item.id,
              material_name: item.material_name,
              manufacturer: item.manufacturer,
              material_spec: item.material_spec,
              material_model: item.material_model,
              remark: item.remark,
              in_num: item.in_num,
              money: item.money,
              sum: item.sum,
              supplier: isAccessory ? item.supplier ?? '' : ''
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
.btn {
  text-align: right;
}
</style>
