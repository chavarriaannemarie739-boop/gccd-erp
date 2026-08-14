<template>
  <!-- [AI-2026-08] 发货选料圆钢业务字段统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <!-- 表单（紧凑布局） -->
    <el-form ref="handleRef" label-width="100px" :model="userForm" :rules="handleRules">
      <el-row :gutter="16">
        <el-col :span="8">
          <el-form-item label="关联单号：">
            <el-input
              v-model="relation_code"
              @click="handleClickmaterial"
              placeholder="请选择关联计划/任务"
              readonly
              size="small"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="客户名称：" prop="customer_name">
            <el-autocomplete
              v-model="userForm.customer_name"
              :fetch-suggestions="querySearchCustomer"
              clearable
              placeholder="请输入或选择"
              size="small"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="发货日期：" prop="ship_date">
            <el-date-picker
              v-model="userForm.ship_date"
              type="date"
              placeholder="实际发货日期"
              value-format="YYYY-MM-DD"
              size="small"
              style="width: 100%"
              :default-value="new Date()"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="物料内容：" prop="material_spec">
        <el-button round type="primary" @click="addOrderMatter" style="margin-left: 20px"
          >从成品库选择物料</el-button
        >
        <el-table
          :data="userForm.content"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <!-- [AI-2026-08] 表头顺序与「从成品库选择物料」一致，末尾保留发货数量/备注/操作 -->
          <el-table-column type="index" label="序号" width="55" />
          <el-table-column
            prop="y_material_manufacturer"
            label="圆钢厂商"
            min-width="80"
            show-overflow-tooltip
          />
          <el-table-column
            prop="y_material_spec"
            label="圆钢材质"
            min-width="80"
            show-overflow-tooltip
          />
          <el-table-column
            prop="y_material_model"
            label="圆钢型号"
            min-width="80"
            show-overflow-tooltip
          />
          <el-table-column
            prop="product_info"
            label="成品信息"
            min-width="180"
            show-overflow-tooltip
          />
          <el-table-column prop="in_num" label="入库数量" show-overflow-tooltip />
          <el-table-column prop="shipped_num" label="已发数" show-overflow-tooltip />
          <el-table-column prop="stock_num" label="库存数" show-overflow-tooltip />
          <el-table-column
            prop="create_time"
            label="入库时间"
            min-width="140"
            show-overflow-tooltip
          />
          <el-table-column prop="material_batch" label="批次号" show-overflow-tooltip />
          <el-table-column prop="relation_code" label="计划单" show-overflow-tooltip />
          <el-table-column
            prop="production_plan_name"
            label="计划名称"
            min-width="70"
            show-overflow-tooltip
          />
          <el-table-column
            prop="y_material_name"
            label="圆钢名称"
            min-width="80"
            show-overflow-tooltip
          />
          <el-table-column prop="material_num" label="发货数量" width="150">
            <template #default="{ row }">
              <el-input-number
                v-model="row.material_num"
                :min="0"
                :max="shipQtyMax(row)"
                :precision="2"
                :step="0.01"
                controls-position="right"
                placeholder="数量"
                style="width: 138px"
              />
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注">
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
    <!-- [AI-2026-08] 选料改为抽屉，避免嵌套弹窗列被挤窄 -->
    <el-drawer
      v-model="processVisible"
      v-if="processVisible"
      size="90%"
      direction="rtl"
      append-to-body
      :close-on-click-modal="false"
      title="从成品库选择物料"
    >
      <el-form :model="query" @submit.prevent>
        <el-row :gutter="16">
          <el-col :span="6">
            <el-form-item label="圆钢材质：">
              <el-input
                v-model="query.y_material_spec"
                clearable
                placeholder="圆钢材质"
                @keyup.enter="searchFinishedStock"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="圆钢型号：">
              <el-input
                v-model="query.y_material_model"
                clearable
                placeholder="圆钢型号"
                @keyup.enter="searchFinishedStock"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="成品信息：">
              <el-input
                v-model="query.product_info"
                clearable
                placeholder="成品信息，如 70*1.2"
                @keyup.enter="searchFinishedStock"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item>
              <el-button v-preventReClick type="primary" @click="searchFinishedStock"
                >搜索</el-button
              >
              <el-button v-preventReClick @click="resetFinishedStock">重置</el-button>
            </el-form-item>
          </el-col>
        </el-row>
      </el-form>
      <el-row />
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
        <el-table-column show-overflow-tooltip :reserve-selection="true" type="selection" width="42" />
        <el-table-column type="index" :index="handleIndex" label="序号" width="50" />
        <!-- [AI-2026-08] 选料弹窗隐藏归属列；批次号/计划单/计划名称/圆钢名称放到最后 -->
        <el-table-column
          prop="y_material_manufacturer"
          label="圆钢厂商"
          min-width="80"
          show-overflow-tooltip
        />
        <el-table-column
          prop="y_material_spec"
          label="圆钢材质"
          min-width="80"
          show-overflow-tooltip
        />
        <el-table-column
          prop="y_material_model"
          label="圆钢型号"
          min-width="80"
          show-overflow-tooltip
        />
        <el-table-column
          prop="product_info"
          label="成品信息"
          min-width="180"
          show-overflow-tooltip
        />
        <el-table-column prop="in_num" label="入库数量" show-overflow-tooltip />
        <el-table-column prop="shipped_num" label="已发数" show-overflow-tooltip />
        <el-table-column prop="stock_num" label="库存数" show-overflow-tooltip />
        <el-table-column
          prop="create_time"
          label="入库时间"
          min-width="140"
          show-overflow-tooltip
        />
        <el-table-column prop="material_batch" label="批次号" show-overflow-tooltip />
        <el-table-column prop="relation_code" label="计划单" show-overflow-tooltip />
        <el-table-column
          prop="production_plan_name"
          label="计划名称"
          min-width="70"
          show-overflow-tooltip
        />
        <el-table-column
          prop="y_material_name"
          label="圆钢名称"
          min-width="80"
          show-overflow-tooltip
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
    </el-drawer>
    <!-- 关联计划/任务 -->
    <el-dialog
      v-model="dialogTableVisible"
      v-if="dialogTableVisible"
      width="70%"
      append-to-body
      :close-on-click-modal="false"
      title="选择关联计划"
    >
      <el-form :model="queryGoods" @submit.prevent>
        <el-row :gutter="20">
          <el-col :span="6">
            <el-form-item label="生产计划单号：">
              <el-input
                v-model="queryGoods.production_plan_code"
                style="width: 260px"
                clearable
                placeholder="请输入"
                @keyup.enter="searchPlanList"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="方案名称：">
              <el-input
                v-model="queryGoods.production_plan_name"
                style="width: 260px"
                clearable
                placeholder="请输入"
                class="filter-item"
                @keyup.enter="searchPlanList"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="圆钢名称：">
              <el-input v-model="queryGoods.y_material_name" clearable placeholder="请输入" />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="圆钢厂商：">
              <el-input
                v-model="queryGoods.y_material_manufacturer"
                clearable
                placeholder="请输入"
              />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="圆钢材质：">
              <el-input v-model="queryGoods.y_material_spec" clearable placeholder="请输入" />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item label="圆钢型号：">
              <el-input v-model="queryGoods.y_material_model" clearable placeholder="请输入" />
            </el-form-item>
          </el-col>
          <el-col :span="6">
            <el-form-item>
              <el-button
                v-preventReClick
                class="filter-item"
                type="primary"
                style="margin-left: 10px"
                @click="searchPlanList"
              >
                搜索
              </el-button>
              <el-button
                v-preventReClick
                class="filter-item"
                type="info"
                style="margin-left: 10px"
                @click="resetPlanList"
              >
                重置
              </el-button>
            </el-form-item>
          </el-col>
        </el-row>
      </el-form>
      <el-table
        :border="true"
        :show-overflow-tooltip="true"
        :data="tableData"
        v-loading="loading"
        stripe
        :row-key="getRowKeys"
        ref="tableDataRef"
        :header-cell-style="{ 'text-align': 'center' }"
        :cell-style="{ 'text-align': 'center' }"
        @row-click="handleClickAdd"
      >
        <el-table-column type="index" :index="planTableIndex" label="序号" width="70" />
        <el-table-column prop="production_plan_code" label="生产计划单号" show-overflow-tooltip />
        <el-table-column prop="production_plan_name" label="计划名称" show-overflow-tooltip />
        <el-table-column prop="y_material_name" label="圆钢名称" show-overflow-tooltip />
        <el-table-column prop="y_material_manufacturer" label="圆钢厂商" show-overflow-tooltip />
        <el-table-column prop="y_material_spec" label="圆钢材质" show-overflow-tooltip />
        <el-table-column prop="y_material_model" label="圆钢型号" show-overflow-tooltip />
        <el-table-column prop="material_name" label="成品名称" show-overflow-tooltip />
        <el-table-column prop="material_spec" label="成品规格" show-overflow-tooltip />
        <el-table-column prop="material_model" label="成品型号" show-overflow-tooltip />
        <el-table-column prop="plan_num" label="计划数量" show-overflow-tooltip />
        <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
      </el-table>
      <!-- 分页 -->
      <el-pagination
        style="margin-top: 10px"
        :page-sizes="[10, 30, 50, 100]"
        :current-page="queryGoods.page"
        :page-size="queryGoods.limit"
        layout="total, sizes, prev, pager, next, jumper"
        :total="totalGoods"
        background
        @size-change="handleSizeChange"
        @current-change="handleCurrentChange"
      />
    </el-dialog>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { ElMessage, ElMessageBox } from 'element-plus'
import { add, customer_list } from '@/api/mes/WarehouseManagement/DeliveryNote'
import { mateiallist } from '@/api/mes/WarehouseManagement/WarehouseManagement'
import { list } from '@/api/mes/ProductionOrderList/ProductionOrderList'
export default {
  name: 'AddShippingOrder',
  props: {
    isShow: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      tableData: [],
      routing_name: '后台自动生成',
      processVisible: false,
      dialogTableVisible: false,
      loading: true,
      relation_code: '',
      processes: [],
      customerHistory: [],
      total: 0,
      totalGoods: 0,
      selection: [],
      userForm: {
        AdminToken: '',
        out_code: '',
        customer_name: '',
        ship_date: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        warehouse: '成品库',
        y_material_spec: '',
        y_material_model: '',
        product_info: ''
      },
      queryGoods: {
        AdminToken: '',
        page: 1,
        limit: 10,
        production_plan_code: '',
        production_plan_name: '',
        y_material_name: '',
        y_material_manufacturer: '',
        y_material_spec: '',
        y_material_model: ''
      },
      handleRules: {
        customer_name: [{ required: true, message: '客户名称不能为空', trigger: 'blur' }],
        ship_date: [{ required: true, message: '发货日期不能为空', trigger: 'change' }]
      }
    }
  },
  mounted() {
    this.fetchCustomerList()
  },
  methods: {
    /** 发货数量上限：系统库存 +2% 容差 */
    shipQtyMax(row) {
      const maxQty = Number(row?.ship_max_qty)
      if (Number.isFinite(maxQty) && maxQty > 0) {
        return maxQty
      }
      const s = Number(row?.stock_num)
      if (Number.isFinite(s) && s > 0) {
        return Number((s * 1.02).toFixed(2))
      }
      return undefined
    },
    normalizeShipQty(val) {
      const n = parseFloat(val)
      if (Number.isNaN(n) || n <= 0) return null
      return Number(n.toFixed(2))
    },
    async fetchCustomerList() {
      const resp = await customer_list()
      this.customerHistory = resp.data
    },
    querySearchCustomer(queryString, cb) {
      const results = queryString
        ? this.customerHistory.filter(
            (item) => item.value.toLowerCase().indexOf(queryString.toLowerCase()) === 0
          )
        : this.customerHistory
      cb(results)
    },
    // 打开关联计划
    handleClickmaterial() {
      this.dialogTableVisible = true
      this.queryGoods.page = 1
      this.list()
    },
    async list() {
      this.loading = true
      const resp = await list(this.queryGoods)
      this.tableData = (resp.data.list || []).map((row) => {
        const legacy = (field) => row[['y', 'mateial', field].join('_')]
        return {
          ...row,
          y_material_name: row.y_material_name ?? legacy('name') ?? '',
          y_material_manufacturer: row.y_material_manufacturer ?? legacy('manufacturer') ?? '',
          y_material_spec: row.y_material_spec ?? legacy('spec') ?? '',
          y_material_model: row.y_material_model ?? legacy('model') ?? ''
        }
      })
      this.totalGoods = resp.data.count
      this.loading = false
    },
    searchPlanList() {
      this.queryGoods.page = 1
      this.list()
    },
    resetPlanList() {
      this.queryGoods.production_plan_code = ''
      this.queryGoods.production_plan_name = ''
      this.queryGoods.y_material_name = ''
      this.queryGoods.y_material_manufacturer = ''
      this.queryGoods.y_material_spec = ''
      this.queryGoods.y_material_model = ''
      this.queryGoods.page = 1
      this.list()
    },
    handleAdd() {
      this.list()
    },
    // 选中关联计划
    handleClickAdd(row) {
      this.relation_code = row.production_plan_code
      this.dialogTableVisible = false
    },
    // 条数
    handleSizeChange(val) {
      this.queryGoods.limit = val
      this.queryGoods.page = 1
      this.list()
    },
    // 页数
    handleCurrentChange(val) {
      this.queryGoods.page = val
      this.list()
    },
    addOrderMatter() {
      this.processVisible = true
      this.query.page = 1
      this.mateiallist()
    },
    // [AI-2026-08] 成品库选料搜索（原输入框未绑定，无法缩小数据范围）
    searchFinishedStock() {
      this.query.page = 1
      this.mateiallist()
    },
    resetFinishedStock() {
      this.query.y_material_spec = ''
      this.query.y_material_model = ''
      this.query.product_info = ''
      this.query.page = 1
      this.mateiallist()
    },
    // 操作
    handleSelectionChange(selection) {
      this.selection = selection
    },
    // 条数
    handleSizeChangeGoods(val) {
      this.query.limit = val
      this.query.page = 1
      // 获取列表
      this.mateiallist()
    },
    // 页数
    handleCurrentChangeGoods(val) {
      this.query.page = val
      // 获取列表
      this.mateiallist()
    },
    getRowKeys(row) {
      return row.row_key || row.id || row.material_batch || row.relation_code
    },
    processSubmit() {
      for (const item of this.selection) {
        if (item.material_id == null || item.material_id === '') {
          ElMessage.error('该行缺少物料ID，请刷新「从成品库选择物料」列表后重试')
          return
        }
        for (let i = 0; i < this.userForm.content.length; i++) {
          const element = this.userForm.content[i]
          if (
            element.material_id === item.material_id &&
            element.relation_code === item.relation_code &&
            element.stock_owner === item.stock_owner
          ) {
            ElMessage.error('当前归属行已存在，请重新选择！')
            return
          }
        }
        this.userForm.content.push({
          material_name: item.material_name || '',
          material_spec: item.material_spec || '',
          material_batch: item.material_batch,
          material_id: item.material_id,
          relation_code: item.relation_code,
          production_plan_name: item.production_plan_name || '',
          stock_owner: item.stock_owner || '',
          y_material_name: item.y_material_name || '',
          y_material_manufacturer: item.y_material_manufacturer || '',
          y_material_spec: item.y_material_spec,
          y_material_model: item.y_material_model || '',
          product_info: item.product_info,
          in_num: item.in_num,
          shipped_num: item.shipped_num,
          stock_num: item.stock_num,
          create_time: item.create_time,
          ship_max_qty: item.ship_max_qty,
          material_num: null
        })
      }
      this.processVisible = false
    },
    // 生产工序
    async mateiallist() {
      this.loading = true
      const resp = await mateiallist(this.query)
      this.processes = resp.data.list
      this.total = resp.data.count
      this.loading = false
    },
    // 处理序号（成品库弹窗分页）
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    planTableIndex(index) {
      return index + 1 + this.queryGoods.limit * (this.queryGoods.page - 1)
    },
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        // 判断
        if (!value) return
        try {
          if (!this.userForm.content.length) {
            ElMessage.error('请从成品库选择物料并填写发货数量')
            return
          }
          const params = {
            customer_name: this.userForm.customer_name,
            content: []
          }
          for (const item of this.userForm.content) {
            const materialNum = this.normalizeShipQty(item.material_num)
            if (materialNum === null) {
              ElMessage.error('发货数量须为大于 0 的数字，最多保留两位小数')
              return
            }
            const stockNum = Number(item.stock_num)
            const maxAllowed = this.shipQtyMax(item)
            if (Number.isFinite(stockNum) && materialNum > stockNum) {
              if (maxAllowed != null && materialNum > maxAllowed + 0.001) {
                ElMessage.error(
                  `发货 ${materialNum} 吨超过容差上限（库存 ${stockNum} 吨，最多 ${maxAllowed} 吨）`
                )
                return
              }
            }
            params.content.push({
              material_id: item.material_id,
              material_name: item.material_name,
              material_spec: item.material_spec,
              material_batch: item.material_batch,
              material_num: materialNum,
              remark: item.remark,
              customer_name: this.userForm.customer_name,
              stock_owner: item.stock_owner || item.production_plan_name || '',
              relation_code: item.relation_code || this.relation_code,
              y_material_name: item.y_material_name,
              y_material_manufacturer: item.y_material_manufacturer,
              y_material_spec: item.y_material_spec,
              y_material_model: item.y_material_model,
              product_info: item.product_info,
              ship_date: this.userForm.ship_date || ''
            })
          }
          const overLines = params.content.filter((line) => {
            const src = this.userForm.content.find(
              (c) => c.relation_code === line.relation_code && c.stock_owner === line.stock_owner
            )
            const stock = Number(src?.stock_num)
            return Number.isFinite(stock) && line.material_num > stock
          })
          if (overLines.length) {
            const detail = overLines
              .map(
                (l) =>
                  `${l.relation_code} 超发 ${(
                    l.material_num -
                    Number(
                      this.userForm.content.find(
                        (c) =>
                          c.relation_code === l.relation_code && c.stock_owner === l.stock_owner
                      )?.stock_num
                    )
                  ).toFixed(2)} 吨`
              )
              .join('；')
            try {
              await ElMessageBox.confirm(
                `以下行在 ±2% 容差内超发：${detail}。确认继续提交？`,
                '超发提示',
                { type: 'warning' }
              )
            } catch {
              return
            }
          }
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
