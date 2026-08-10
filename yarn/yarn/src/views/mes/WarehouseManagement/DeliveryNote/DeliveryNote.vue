<template>
  <!-- [AI-2026-08] 发货单圆钢业务字段统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.ship_code"
            placeholder="发货编码"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.relation_code"
            placeholder="关联单号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_num"
            placeholder="物料数量"
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
            v-model="query.material_name"
            placeholder="物料名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.customer_name"
            placeholder="客户"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_name"
            placeholder="圆钢名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_manufacturer"
            placeholder="圆钢厂商"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_spec"
            placeholder="圆钢材质"
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
            v-model="query.product_info"
            placeholder="成品信息"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="query.ship_date"
            type="date"
            placeholder="发货日期"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="list">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleAdd">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
      <el-card>
        <div style="margin-bottom: 20px">
          <el-button type="success" @click="CheckbelowGoods">新增</el-button>
          <!-- <el-button type="danger" :disabled="type" v-preventReClick @click="dele(row)"
              >删除</el-button
            > -->
        </div>
        <el-table
          ref="tableDataRefss"
          :data="tableData"
          border
          fit
          style="width: 100%"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
          v-loading="loading"
          :row-key="getRowKeys"
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" align="center" :reserve-selection="true" width="40" />
          <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
          <el-table-column prop="ship_code" label="发货编号" show-overflow-tooltip />
          <el-table-column prop="customer_name" label="客户" show-overflow-tooltip />
          <el-table-column prop="relation_code" label="关联单号" show-overflow-tooltip />
          <el-table-column prop="material_batch" label="物料批次" show-overflow-tooltip />
          <el-table-column prop="y_material_name" label="圆钢名称" show-overflow-tooltip />
          <el-table-column prop="y_material_manufacturer" label="圆钢厂商" show-overflow-tooltip />
          <el-table-column prop="y_material_spec" label="圆钢材质" show-overflow-tooltip />
          <el-table-column prop="y_material_model" label="圆钢型号" show-overflow-tooltip />
          <el-table-column prop="product_info" label="成品信息" show-overflow-tooltip />
          <el-table-column prop="material_num" label="数量" show-overflow-tooltip>
            <template #default="{ row }">
              {{ formatShipQty(row.material_num) }}
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" show-overflow-tooltip />
          <el-table-column prop="ship_date" label="发货日期" show-overflow-tooltip />
          <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
          <!-- <el-table-column fixed="right" label="操作" width="200">
              <template #default="{ row }">
                <el-button type="info" v-preventReClick @click="ViewproductionPlan(row)"
                  >查看</el-button
                >
                <el-button type="success" @click="CheckbelowGoodsInspection(row)"
                  >新增检验</el-button
                >
              </template>
            </el-table-column> -->
        </el-table>
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
        append-to-body
        :close-on-click-modal="false"
      >
        <AddShippingOrder :processes="selectList" v-model:is-show="processVisible" @list="list" />
      </el-dialog>
    </div>
  </div>
</template>

<script>
import { list } from '@/api/mes/WarehouseManagement/DeliveryNote'
import AddShippingOrder from './components/AddShippingOrder.vue'
export default {
  name: 'DeliveryNote',
  components: {
    AddShippingOrder
  },
  data() {
    return {
      type: true,
      view: {},
      processes: {},
      selectList: [],
      processVisible: false,
      processVisibleGoods: false,
      processVisibleInspection: false,
      buttons: [{ type: 'primary', text: 'primary' }],
      dialogTableVis: false,
      order_times: '',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        ship_code: '',
        relation_code: '',
        material_num: '',
        material_batch: '',
        material_name: '',
        customer_name: '',
        y_material_name: '',
        y_material_manufacturer: '',
        y_material_spec: '',
        y_material_model: '',
        product_info: '',
        ship_date: ''
      },
      count: 0,
      tableList: [],
      loading: true
    }
  },
  // [AI-2026-07] keep-alive 白名单外首次进入不触发 activated，需 mounted 拉数
  mounted() {
    this.list()
  },
  activated() {
    this.list()
  },
  methods: {
    formatShipQty(val) {
      if (val === null || val === undefined || val === '') return ''
      const n = Number(val)
      return Number.isNaN(n) ? String(val) : n.toFixed(2)
    },
    // 新增
    CheckbelowGoods() {
      this.processVisible = true
    },
    // 新增检验
    CheckbelowGoodsInspection(row) {
      this.processes = row
      this.processVisibleInspection = true
    },
    // 详情
    ViewproductionPlan(row) {
      this.view = row
      this.processVisibleGoods = true
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
    // 生产工序
    async list() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = (resp.data?.list || []).map((row) => {
          const legacy = (field) => row[['y', 'mateial', field].join('_')]
          return {
            ...row,
            y_material_name: row.y_material_name ?? legacy('name') ?? '',
            y_material_manufacturer: row.y_material_manufacturer ?? legacy('manufacturer') ?? '',
            y_material_spec: row.y_material_spec ?? legacy('spec') ?? '',
            y_material_model: row.y_material_model ?? legacy('model') ?? ''
          }
        })
        this.total = resp.data?.count || 0
      } catch (e) {
        /* request 拦截器已提示 */
      } finally {
        this.loading = false
      }
    },
    // 处理时间
    handleChange(val) {
      this.order_times = val
      this.query.create_time = val[0] + '~' + val[1]
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
    handleAdd() {
      this.query.ship_code = ''
      this.query.relation_code = ''
      this.query.material_num = ''
      this.query.material_batch = ''
      this.query.material_name = ''
      this.query.customer_name = ''
      this.query.y_material_name = ''
      this.query.y_material_manufacturer = ''
      this.query.y_material_spec = ''
      this.query.y_material_model = ''
      this.query.product_info = ''
      this.query.ship_date = ''
      this.list()
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
.contenter {
  margin-top: 10px;
}
/* .app-container {
  background-color: #eaedf1;
} */
</style>
