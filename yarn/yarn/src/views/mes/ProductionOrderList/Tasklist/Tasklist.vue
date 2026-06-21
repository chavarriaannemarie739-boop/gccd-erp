<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.task_code"
            placeholder="任务编号"
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
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="handleSearch">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
        <el-card>
          <div style="margin-bottom: 20px">
            <!-- <el-button type="success" @click="CheckbelowGoods">新增</el-button> -->
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
            <el-table-column prop="task_code" label="任务编号" show-overflow-tooltip />
            <el-table-column prop="processes_code" label="工序编号" show-overflow-tooltip />
            <el-table-column prop="relation_code" label="关联编号" show-overflow-tooltip />
            <el-table-column prop="routing_code" label="工艺编码" show-overflow-tooltip />
            <el-table-column prop="report_num" label="报工数量" show-overflow-tooltip />
            <el-table-column prop="task_num" label="任务数量" show-overflow-tooltip />
            <el-table-column fixed="right" label="任务状态">
              <template #default="{ row }">
                <span v-if="row.status === 0">未生产</span>
                <span v-if="row.status === 1">生产中</span>
                <span v-if="row.status === 2">生产完成</span>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
            <el-table-column fixed="right" label="操作" width="290">
              <template #default="{ row }">
                <el-button type="info" v-preventReClick @click="ViewproductionPlan(row)"
                  >查看</el-button
                >
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
        <!-- 详情 -->
        <el-dialog
          title="详情"
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="60%"
          :close-on-click-modal="false"
        >
          <TaskDetails :view="view" v-model:is-show="processVisibleGoods" />
        </el-dialog>
      </div>
    <router-view />
  </div>
</template>

<script>
import { list, dele } from '@/api/mes/ProductionOrderList/Tasklist/Tasklist'
import TaskDetails from './components/TaskDetails.vue'
export default {
  name: 'Tasklist',
  components: {
    TaskDetails
  },
  data() {
    return {
      type: true,
      view: {},
      selectList: [],
      processVisible: false,
      processVisibleGoods: false,
      OrderStatusGoods: false,
      processVisibleGoodsquery: false,
      buttons: [{ type: 'primary', text: 'primary' }],
      dialogTableVis: false,
      order_times: '',
      tableData: [],
      total: 0,
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        task_code: '',
        relation_code: ''
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
    handleSearch() {
      this.query.page = 1
      this.list()
    },
    async list() {
      this.loading = true
      const resp = await list(this.query)
      this.tableData = resp.data.list
      this.total = resp.data.count
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
      this.query.task_code = ''
      this.query.relation_code = ''
      this.query.page = 1
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
