<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_spec"
            placeholder="物料规格"
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
        <el-card>
          <div style="margin-bottom: 20px">
            <el-button type="success" @click="CheckbelowGoods">新增</el-button>
            <el-button type="danger" :disabled="type" v-preventReClick @click="dele(row)"
              >删除</el-button
            >
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
            <el-table-column prop="material_spec" label="规格" show-overflow-tooltip />
            <el-table-column prop="wage" label="工资" show-overflow-tooltip />
            <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
            <el-table-column fixed="right" label="操作" width="200">
              <template #default="{ row }">
                <el-button type="info" v-preventReClick @click="ViewproductionPlan(row)"
                  >查看</el-button
                >
                <!-- <el-button type="danger" v-preventReClick @click="dele(row)">删除</el-button> -->
              </template>
            </el-table-column>
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
          title="新增计件单价"
          v-if="processVisible"
          v-model="processVisible"
          class="salary-management-add-dialog"
          width="920px"
          top="4vh"
          append-to-body
          destroy-on-close
          :close-on-click-modal="false"
        >
          <SalaryAddition v-model:is-show="processVisible" @list="list" />
        </el-dialog>
        <!-- 详情 -->
        <el-dialog
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="30%"
          :close-on-click-modal="false"
        >
          <SalaryDetails :view="view" v-model:is-show="processVisibleGoods" />
        </el-dialog>
      </div>
    <router-view />
  </div>
</template>

<script>
import { list, dele } from '@/api/mes/SalaryManagement/SalaryManagement'
import SalaryAddition from './components/SalaryAddition.vue'
import SalaryDetails from './components/SalaryDetails.vue'
export default {
  name: 'SalaryManagement',
  components: {
    SalaryAddition,
    SalaryDetails
  },
  data() {
    return {
      type: true,
      view: {},
      selectList: [],
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
        material_spec: '',
        create_start_date: '',
        create_end_date: ''
      },
      count: 0,
      tableList: [],
      loading: true
    }
  },
  activated() {
    this.list()
  },
  methods: {
    // 新增
    CheckbelowGoods() {
      this.processVisible = true
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
      const resp = await list(this.query)
      this.tableData = resp.data.list
      this.total = resp.data.count
      console.log(resp, 'resp')
      this.loading = false
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
      this.query.material_spec = ''
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
.contenter {
  margin-top: 10px;
}
:deep(.salary-management-add-dialog) {
  max-width: 96vw;
}
/* .app-container {
  background-color: #eaedf1;
} */
</style>
