<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.device_code"
            placeholder="设备编号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.device_name"
            placeholder="设备名称"
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
            <el-table-column prop="device_code" label="设备编号" show-overflow-tooltip />
            <el-table-column prop="device_name" label="设备名称" show-overflow-tooltip />
            <el-table-column prop="processes_code" label="工序编号" show-overflow-tooltip />
            <el-table-column label="二维码" width="170">
              <template #default="{ row }">
                <el-image
                  preview-teleported="true"
                  style="width: 150px; height: 150px"
                  :src="row.img"
                  :preview-src-list="[row.img]"
                />
              </template>
            </el-table-column>
            <el-table-column prop="status" label="设备状态" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.status === 0">
                  <el-button type="success" round>正常</el-button></span
                >
                <span v-if="row.status === 1">
                  <el-button type="warning" round>正在维修</el-button></span
                >
              </template>
            </el-table-column>
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
          title="新增"
          v-if="processVisible"
          v-model="processVisible"
          width="30%"
          :close-on-click-modal="false"
        >
          <AddDevice :processes="selectList" v-model:is-show="processVisible" @list="list" />
        </el-dialog>
        <!-- <el-dialog
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="30%"
          :close-on-click-modal="false"
        >
          <ViewProcess :view="view" v-model:is-show="processVisibleGoods" />
        </el-dialog> -->
      </div>
  </div>
</template>

<script>
import { list } from '@/api/mes/EquipmentLedger/EquipmentLedger'
import AddDevice from './components/AddDevice.vue'
// import ViewProcess from './components/ViewProcess.vue'
export default {
  name: 'EquipmentLedger',
  components: {
    AddDevice
    // ViewProcess
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
        device_code: '',
        device_name: ''
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
      this.query.device_code = ''
      this.query.device_name = ''
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
/* .app-container {
  background-color: #eaedf1;
} */
</style>
