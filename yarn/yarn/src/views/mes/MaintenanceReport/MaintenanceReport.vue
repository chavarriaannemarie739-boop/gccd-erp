<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input v-model="query.device_name" placeholder="设备名称" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: auto;">
          <el-radio-group v-model="query.report_type" size="small" @change="getList">
            <el-radio-button label="">全部</el-radio-button>
            <el-radio-button label="机修">机修</el-radio-button>
            <el-radio-button label="电工">电工</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item class="filter-form-item--field" style="width: auto;">
          <el-radio-group v-model="query.process" size="small" @change="getList">
            <el-radio-button label="">全部</el-radio-button>
            <el-radio-button label="断钢">断钢</el-radio-button>
            <el-radio-button label="穿孔">穿孔</el-radio-button>
            <el-radio-button label="球化">球化</el-radio-button>
            <el-radio-button label="酸洗">酸洗</el-radio-button>
            <el-radio-button label="其他">其他</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input v-model="query.user_names" placeholder="人员" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input v-model="query.repair_item_name" placeholder="维修项目" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
            @change="handleDateChange"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="getList">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="content-card" style="margin-top: 10px">
      <!-- 操作按钮区 -->
      <div style="margin-bottom: 15px">
        <el-button type="primary" size="small" :disabled="selectList.length !== 1" @click="handleEdit(selectList[0])">编辑</el-button>
        <el-button type="danger" size="small" :disabled="selectList.length === 0" @click="handleBatchDelete">批量删除</el-button>
      </div>

      <el-table
        ref="tableRef"
        v-loading="loading"
        :data="tableData"
        border
        fit
        style="width: 100%"
        :header-cell-style="{ 'background-color': '#f8f9fa', 'text-align': 'center' }"
        :cell-style="{ 'text-align': 'center' }"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" />
        <el-table-column type="index" label="序号" width="70" />
        <el-table-column prop="report_type" label="工种" width="80">
          <template #default="{ row }">
            <el-tag :type="row.report_type === '机修' ? 'primary' : 'warning'">{{ row.report_type || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="process" label="工序" width="100">
          <template #default="{ row }">
            <el-tag :type="processTagType(row.process)">{{ row.process }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="device_name" label="设备名称" show-overflow-tooltip />
        <el-table-column prop="repair_item_name" label="维修项目" show-overflow-tooltip />
        <el-table-column prop="repair_content" label="维修内容" show-overflow-tooltip />
        <el-table-column prop="user_names" label="人员" show-overflow-tooltip />
        <el-table-column prop="remark" label="备注" show-overflow-tooltip />
        <el-table-column prop="create_time" label="报工时间" width="180" />
      </el-table>

      <!-- 分页 -->
      <div class="pagination-container" style="margin-top: 15px; text-align: right">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :current-page="query.page"
          :page-size="query.limit"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <!-- 编辑弹窗 -->
    <el-dialog
      title="编辑报工记录"
      v-model="editVisible"
      width="500px"
      :close-on-click-modal="false"
    >
      <el-form ref="editFormRef" :model="editForm" label-width="90px">
        <el-form-item label="工种">
          <el-select v-model="editForm.report_type" style="width: 100%">
            <el-option label="机修" value="机修" />
            <el-option label="电工" value="电工" />
          </el-select>
        </el-form-item>
        <el-form-item label="工序">
          <el-select v-model="editForm.process" style="width: 100%">
            <el-option label="断钢" value="断钢" />
            <el-option label="穿孔" value="穿孔" />
            <el-option label="球化" value="球化" />
            <el-option label="酸洗" value="酸洗" />
            <el-option label="其他" value="其他" />
          </el-select>
        </el-form-item>
        <el-form-item label="设备名称">
          <el-input v-model="editForm.device_name" />
        </el-form-item>
        <el-form-item label="维修项目">
          <el-input v-model="editForm.repair_item_name" />
        </el-form-item>
        <el-form-item label="维修内容">
          <el-input v-model="editForm.repair_content" type="textarea" />
        </el-form-item>
        <el-form-item label="人员">
          <el-input v-model="editForm.user_names" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editLoading" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { maintenanceReportList, maintenanceReportEdit, maintenanceReportDele } from '@/api/mes/MaintenanceReport/MaintenanceReport'

export default {
  name: 'MaintenanceReport',
  data() {
    return {
      loading: false,
      tableData: [],
      total: 0,
      dateRange: [],
      selectList: [],
      query: {
        page: 1,
        limit: 20,
        device_name: '',
        report_type: '',
        process: '',
        user_names: '',
        repair_item_name: '',
        create_start_date: '',
        create_end_date: ''
      },
      editVisible: false,
      editLoading: false,
      editForm: {
        ids: [],
        report_type: '',
        process: '',
        device_name: '',
        repair_item_name: '',
        repair_content: '',
        user_names: '',
        remark: ''
      }
    }
  },
  created() {
    this.getList()
  },
  methods: {
    async getList() {
      this.loading = true
      try {
        const res = await maintenanceReportList(this.query)
        if (res.code === 200) {
          this.tableData = res.data.list
          this.total = res.data.count
        }
      } catch (error) {
        console.error('获取列表失败', error)
      } finally {
        this.loading = false
      }
    },
    handleReset() {
      this.query = {
        page: 1,
        limit: 20,
        device_name: '',
        report_type: '',
        process: '',
        user_names: '',
        repair_item_name: '',
        create_start_date: '',
        create_end_date: ''
      }
      this.dateRange = []
      this.getList()
    },
    handleDateChange(val) {
      if (val) {
        this.query.create_start_date = val[0]
        this.query.create_end_date = val[1]
      } else {
        this.query.create_start_date = ''
        this.query.create_end_date = ''
      }
    },
    handleSizeChange(val) {
      this.query.limit = val
      this.getList()
    },
    handleCurrentChange(val) {
      this.query.page = val
      this.getList()
    },
    processTagType(process) {
      const map = { '断钢': 'primary', '穿孔': 'warning', '球化': 'success', '酸洗': 'danger', '其他': 'info' }
      return map[process] || ''
    },
    handleSelectionChange(val) {
      this.selectList = val
    },
    handleEdit(row) {
      if (!row) return
      this.editForm = {
        ids: [row.id],
        report_type: row.report_type || '',
        process: row.process || '',
        device_name: row.device_name || '',
        repair_item_name: row.repair_item_name || '',
        repair_content: row.repair_content || '',
        user_names: row.user_names || '',
        remark: row.remark || ''
      }
      this.editVisible = true
    },
    async submitEdit() {
      this.editLoading = true
      try {
        const res = await maintenanceReportEdit(this.editForm)
        if (res.code === 200) {
          ElMessage.success('保存成功')
          this.editVisible = false
          this.getList()
        }
      } catch (error) {
        console.error('编辑失败', error)
      } finally {
        this.editLoading = false
      }
    },
    handleBatchDelete() {
      ElMessageBox.confirm(`确定删除选中的 ${this.selectList.length} 条记录？`, '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).then(async () => {
        try {
          const ids = this.selectList.map(item => item.id)
          const res = await maintenanceReportDele({ ids })
          if (res.code === 200) {
            ElMessage.success('删除成功')
            this.getList()
          }
        } catch (error) {
          console.error('删除失败', error)
        }
      }).catch(() => {})
    }
  }
}
</script>

<style scoped>
.app-container {
  padding: 20px;
}
</style>
