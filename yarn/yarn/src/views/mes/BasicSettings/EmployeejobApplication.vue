<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input v-model="query.relation_code" placeholder="生产报工编号" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="getUserDayWage">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
        <el-card>
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
            <el-table-column prop="relation_code" label="生产报工编号" show-overflow-tooltip />
            <el-table-column prop="report_num" label="数量" show-overflow-tooltip />
            <el-table-column prop="wage" label="工资" show-overflow-tooltip />
            <el-table-column prop="username" label="用户名" show-overflow-tooltip />
            <el-table-column prop="create_time" label="时间" show-overflow-tooltip />
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
          width="40%"
          :close-on-click-modal="false"
        >
          <SalaryAddition
            :processes="selectList"
            v-model:is-show="processVisible"
            @list="getUserDayWage"
          />
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
import { getUserDayWage } from '@/api/mes/SalaryList/SalaryList'
import SalaryAddition from './components/SalaryAddition.vue'
import SalaryDetails from './components/SalaryDetails.vue'
export default {
  name: 'EmployeejobApplication',
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
        relation_code: ''
      },
      count: 0,
      tableList: [],
      loading: true
    }
  },
  activated() {
    this.applyRouteQuery()
    this.getUserDayWage()
  },
  methods: {
    applyRouteQuery() {
      const relationCode = this.$route?.query?.relation_code
      if (typeof relationCode === 'string' && relationCode.trim() !== '') {
        this.query.relation_code = relationCode.trim()
      }
    },
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
    async getUserDayWage() {
      this.loading = true
      const resp = await getUserDayWage(this.query)
      this.tableData = resp.data.list
      this.total = resp.data.count
      console.log(resp, 'resp')
      this.loading = false
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
      this.getUserDayWage()
    },
    // 页数
    handleCurrentChange(val) {
      this.query.page = val
      // 获取列表
      this.getUserDayWage()
    },
    // 重置
    handleResetting() {
      this.query.relation_code = ''
      this.getUserDayWage()
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
          this.getUserDayWage()
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
