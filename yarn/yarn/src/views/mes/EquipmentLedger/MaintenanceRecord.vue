<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.device_code"
            placeholder="设备编码"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.device_name"
            placeholder="设备名称"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.create_name"
            placeholder="报修人"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.repair_name"
            placeholder="维修人"
            clearable
            size="small"
            class="filter-input"
            @keyup.enter="handleSearch"
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
          <el-button
            v-preventReClick
            type="danger"
            size="small"
            :disabled="selectList.length === 0"
            @click="handleBatchDelete"
          >
            删除
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
        <el-card>
          <!-- <div style="margin-bottom: 20px">
            <el-button type="success" @click="CheckbelowGoods">新增</el-button>
            <el-button type="danger" :disabled="type" v-preventReClick @click="dele(row)"
              >删除</el-button
            >
          </div> -->
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
            <el-table-column prop="device_name" label="设备名称" show-overflow-tooltip />
            <el-table-column prop="reason" label="维修原因" show-overflow-tooltip />
            <el-table-column prop="time" label="维修时长" show-overflow-tooltip />
            <el-table-column prop="" label="报修照片" width="200">
              <template #default="{ row }">
                <div class="demo-image__preview">
                  <el-image
                    preview-teleported="true"
                    v-for="(item, index) in row.imgs"
                    :key="index"
                    style="height: 40px; border-radius: 50%"
                    :src="item"
                    :preview-src-list="[item]"
                  />
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="报修时间" show-overflow-tooltip />
            <el-table-column prop="create_name" label="报修人员" show-overflow-tooltip />
            <el-table-column prop="detail" label="维修过程" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.detail.length !== 0"> {{ row.detail[0].detail }}</span>
                <span v-if="row.detail.length === 0"> -</span>
              </template>
            </el-table-column>
            <el-table-column prop="" label="维修照片" width="200">
              <template #default="{ row }">
                <div class="demo-image__preview" v-if="row.detail.length !== 0">
                  <el-image
                    preview-teleported="true"
                    v-for="(item, index) in row.detail[0].imgsg"
                    :key="index"
                    style="height: 40px; border-radius: 50%"
                    :src="item"
                    :preview-src-list="[item]"
                  />
                </div>
                <div class="demo-image__preview" v-if="row.detail.length === 0">-</div>
              </template>
            </el-table-column>
            <el-table-column prop="repair_name" label="维修人员" show-overflow-tooltip />
            <el-table-column prop="create_times" label="维修时间" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.detail.length !== 0"> {{ row.detail[0].create_time }}</span>
                <span v-else> -</span>
              </template>
            </el-table-column>
            <el-table-column prop="status" label="设备状态" show-overflow-tooltip>
              <template #default="{ row }">
                <div>
                  <span v-if="row.status === 0">
                    <el-button type="warning" round>维修中</el-button></span
                  >
                  <span v-if="row.status === 1">
                    <el-button type="success" round>维修完成</el-button></span
                  >
                  <span v-if="row.status === 2">
                    <el-button type="success" round>维修完毕</el-button></span
                  >
                </div>
              </template>
            </el-table-column>
          </el-table>
          <!-- 分页 -->
          <el-pagination
            style="margin-top: 10px"
            :page-sizes="[10, 60, 100, 200]"
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
          <NewProcess :processes="selectList" v-model:is-show="processVisible" @list="list" />
        </el-dialog>
        <!-- 详情 -->
        <el-dialog
          v-if="processVisibleGoods"
          v-model="processVisibleGoods"
          width="30%"
          :close-on-click-modal="false"
        >
          <ViewProcess :view="view" v-model:is-show="processVisibleGoods" />
        </el-dialog>
      </div>
  </div>
</template>

<script>
import { ElMessage, ElMessageBox } from 'element-plus'
import { repairlist as requestRepairList, repairDele } from '@/api/mes/EquipmentLedger/EquipmentLedger'
// import NewProcess from './components/NewProcess.vue'
// import ViewProcess from './components/ViewProcess.vue'
export default {
  name: 'MaintenanceRecord',
  components: {
    // NewProcess,
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
        device_name: '',
        create_name: '',
        repair_name: '',
        create_start_date: '',
        create_end_date: ''
      },
      count: 0,
      tableList: [],
      loading: true
    }
  },
  // [AI-2026-07] keep-alive 白名单外首次进入不触发 activated，需 mounted 拉数
  mounted() {
    this.repairlist()
  },
  activated() {
    this.repairlist()
  },
  methods: {
    handleSearch() {
      this.query.page = 1
      this.repairlist()
    },
    handleBatchDelete() {
      if (this.selectList.length === 0) return
      ElMessageBox.confirm(
        `是否删除选中的 ${this.selectList.length} 条维修记录？`,
        '提示',
        {
          confirmButtonText: '确定',
          cancelButtonText: '取消',
          type: 'warning'
        }
      )
        .then(async () => {
          const ids = this.selectList.map((row) => row.id)
          await repairDele({ ids })
          ElMessage.success('删除成功')
          this.$refs.tableDataRefss?.clearSelection()
          this.selectList = []
          this.type = true
          await this.repairlist()
        })
        .catch(() => {})
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
    async repairlist() {
      this.loading = true
      const resp = await requestRepairList(this.query)
      // for (let j = 0; j < resp.data.list.length; j++) {
      //   const item = resp.data.list[j]
      //   console.log(item, 'item')
      //   for (let i = 0; i < item.detail.length; i++) {
      //     const element = item.detail[i]
      //     this.tableData.push({
      //       device_name: item.device_name,
      //       reason: item.reason,
      //       type: item.type,
      //       time: item.time,
      //       create_time: item.create_time,
      //       create_name: item.create_name,
      //       imgs: item.imgs,
      //       status: item.status
      //       // detail: element.detail,
      //       // imgsg: element.imgs,
      //       // create_times: element.create_time
      //     })
      //   }
      // }

      // console.log(this.tableData, 'this.tableData')
      this.tableData = resp.data.list
      this.total = resp.data.count
      this.loading = false
    },
    // 处理时间
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
    // 处理序号
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    // 条数
    handleSizeChange(val) {
      this.query.limit = val
      // 获取列表
      this.repairlist()
    },
    // 页数
    handleCurrentChange(val) {
      this.query.page = val
      // 获取列表
      this.repairlist()
    },
    // 重置
    handleResetting() {
      this.query.device_code = ''
      this.query.device_name = ''
      this.query.create_name = ''
      this.query.repair_name = ''
      this.query.create_start_date = ''
      this.query.create_end_date = ''
      this.query.page = 1
      this.order_times = []
      this.repairlist()
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
