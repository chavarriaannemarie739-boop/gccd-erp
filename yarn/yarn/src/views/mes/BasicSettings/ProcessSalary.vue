<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.processes_code"
            placeholder="工序编号"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.processes_name"
            placeholder="工序名称"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.post_name"
            placeholder="职位名称"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
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
          <div class="toolbar-actions">
            <el-button type="success" @click="CheckbelowGoods">新增</el-button>
            <el-button
              type="primary"
              v-preventReClick
              :disabled="selectList.length !== 1"
              @click="handleToolbarEdit"
            >
              编辑
            </el-button>
            <el-button
              type="danger"
              v-preventReClick
              :disabled="selectList.length === 0"
              @click="handleToolbarDelete"
            >
              删除
            </el-button>
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
            <el-table-column prop="nickname" label="姓名" width="100" show-overflow-tooltip />
            <el-table-column prop="post_name" label="职位名称" show-overflow-tooltip />
            <el-table-column prop="processes_code" label="工序编号" show-overflow-tooltip />
            <el-table-column prop="processes_name" label="工序名称" show-overflow-tooltip />
            <el-table-column prop="wage" label="工资额" min-width="100" show-overflow-tooltip />
            <el-table-column label="月薪/日薪" prop="is_month" width="100">
              <template #default="{ row }">
                <span v-if="row.is_month === 0">日薪</span>
                <span v-else-if="row.is_month === 1">月薪</span>
                <span v-else>—</span>
              </template>
            </el-table-column>
            <el-table-column label="启用" prop="status" width="80">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status === 1 ? '是' : '否' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="创建时间" min-width="160" show-overflow-tooltip />
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
          <NewlyAddedProcess v-model:is-show="processVisible" @list="list" />
        </el-dialog>
        <!-- 详情 -->
        <el-dialog
          title="详情"
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="40%"
          :close-on-click-modal="false"
        >
          <ViewProcesses :view="view" v-model:is-show="processVisibleGoods" />
        </el-dialog>
        <!-- 编辑 -->
        <el-dialog
          title="编辑"
          v-if="processVisibleGoodsquery"
          v-model="processVisibleGoodsquery"
          width="30%"
          :close-on-click-modal="false"
        >
          <EditProcessSalary :view="view" @list="list" v-model:is-show="processVisibleGoodsquery" />
        </el-dialog>
      </div>
  </div>
</template>

<script>
import { list, dele } from '@/api/mes/ProcessSalary/ProcessSalary'
// import ViewProcesses from './components/ViewProcesses.vue'
import NewlyAddedProcess from './components/NewlyAddedProcess.vue'
import EditProcessSalary from './components/EditProcessSalary.vue'
export default {
  name: 'ProcessSalary',
  components: {
    // ViewProcesses,
    NewlyAddedProcess,
    EditProcessSalary
  },
  data() {
    return {
      view: {},
      selectList: [],
      processVisible: false,
      processVisibleGoods: false,
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
        processes_code: '',
        processes_name: '',
        post_name: ''
      },
      count: 0,
      tableList: [],
      loading: true
    }
  },
  mounted() {
    this.list()
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
    //编辑
    EditMaterials(row) {
      this.view = row
      this.processVisibleGoodsquery = true
    },
    handleToolbarEdit() {
      if (this.selectList.length !== 1) {
        ElMessage.warning('请勾选一条记录进行编辑')
        return
      }
      this.EditMaterials(this.selectList[0])
    },
    handleToolbarDelete() {
      if (this.selectList.length === 0) {
        ElMessage.warning('请勾选要删除的记录')
        return
      }
      this.deleBatch(this.selectList)
    },
    // 产品唯一的标识
    getRowKeys(row) {
      return row.id
    },
    // 选择项发生改变
    handleSelectionChange(val) {
      this.selectList = val
    },
    async list() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = resp.data.list
        this.total = resp.data.count
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
    handleSearch() {
      this.query.page = 1
      this.list()
    },
    // 重置
    handleResetting() {
      this.query.processes_code = ''
      this.query.processes_name = ''
      this.query.post_name = ''
      this.query.page = 1
      this.list()
    },
    deleBatch(rows) {
      const n = rows.length
      const tip =
        n === 1 ? '是否删除该固定工资配置？' : `是否删除选中的 ${n} 条固定工资配置？`
      ElMessageBox.confirm(tip, '温馨提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
        .then(async () => {
          const ids = rows.map((r) => r.id)
          const params = {
            AdminToken: '',
            ids
          }
          await dele(params)
          this.list()
          this.clearTableSelection()
          ElMessage({
            type: 'success',
            message: '删除成功'
          })
        })
        .catch(() => {})
    },
    clearTableSelection() {
      this.$refs.tableDataRefss?.clearSelection?.()
      this.selectList = []
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
.toolbar-actions {
  margin-bottom: 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
/* .app-container {
  background-color: #eaedf1;
} */
</style>
