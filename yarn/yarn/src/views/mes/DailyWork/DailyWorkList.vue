<template>
  <div class="app-container">
    <!-- 筛选区域 -->
    <el-card class="filter-card" shadow="never">
      <el-form :model="query" class="filter-form" @submit.prevent>
        <el-form-item class="filter-form-item">
          <el-date-picker
            v-model="workDateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="small"
            @change="handleSearch"
          />
        </el-form-item>
        <el-form-item class="filter-form-item">
          <el-button v-preventReClick type="primary" size="small" @click="handleSearch">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 统计卡片 -->
    <el-row :gutter="16" class="stat-row">
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">日工工资合计</div>
          <div class="stat-value">{{ statData.daily_wage_total }} 元</div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">岗位津贴合计</div>
          <div class="stat-value">{{ statData.allowance_total }} 元</div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">工资合计</div>
          <div class="stat-value stat-value--total">{{ statData.wage_total }} 元</div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">参与人数</div>
          <div class="stat-value">{{ statData.user_count }} 人</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 数据表格 -->
    <el-card class="list-card" shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        stripe
        size="small"
        style="width: 100%"
      >
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="nickname" label="姓名" width="120" />
        <el-table-column prop="work_date" label="工作日期" width="120" />
        <el-table-column prop="work_type" label="报工类型" width="100">
          <template #default="{ row }">
            <el-tag :type="row.work_type === 1 ? 'primary' : 'warning'" size="small">
              {{ row.work_type === 1 ? '全天' : '半天' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="work_hours" label="工时(h)" width="90" />
        <el-table-column prop="daily_wage" label="日工工资(元)" width="120" />
        <el-table-column prop="team_leader_allowance" label="岗位津贴(元)" width="120" />
        <el-table-column prop="total_wage" label="合计工资(元)" width="120">
          <template #default="{ row }">
            <span class="total-wage-cell">{{ row.total_wage }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip />
        <el-table-column prop="create_time" label="创建时间" width="170" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-preventReClick type="primary" size="small" link @click="handleEdit(row)">编辑</el-button>
            <el-button v-preventReClick type="danger" size="small" link @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        v-model:current-page="query.page"
        v-model:page-size="query.limit"
        :total="total"
        :page-sizes="[10, 20, 50, 100]"
        layout="total, sizes, prev, pager, next, jumper"
        small
        class="list-pagination"
        @size-change="handleSearch"
        @current-change="handleSearch"
      />
    </el-card>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" title="编辑日工记录" width="500px" destroy-on-close>
      <el-form ref="editFormRef" :model="editForm" label-width="100px">
        <el-form-item label="工作日期">
          <el-date-picker v-model="editForm.work_date" type="date" value-format="YYYY-MM-DD" />
        </el-form-item>
        <el-form-item label="报工类型">
          <el-radio-group v-model="editForm.work_type">
            <el-radio :value="1">全天日工</el-radio>
            <el-radio :value="2">半天日工</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="工时">
          <el-input-number v-model="editForm.work_hours" :min="0.5" :max="12" :step="0.5" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" maxlength="200" show-word-limit />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitEdit">确认修改</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { list, edit, dele, stat } from '@/api/mes/DailyWork/DailyWork'
import { ElMessage, ElMessageBox } from 'element-plus'

const loading = ref(false)
const submitting = ref(false)
const tableData = ref([])
const total = ref(0)
const dialogVisible = ref(false)
const editFormRef = ref(null)
const workDateRange = ref([])

const query = reactive({
  page: 1,
  limit: 10,
  where: {}
})

const statData = reactive({
  daily_wage_total: 0,
  allowance_total: 0,
  wage_total: 0,
  user_count: 0
})

const editForm = reactive({
  id: 0,
  work_date: '',
  work_type: 1,
  work_hours: 12,
  remark: ''
})

function buildWhere() {
  const w = { ...query.where }
  if (workDateRange.value && workDateRange.value.length === 2) {
    w.work_date = workDateRange.value[0] + '~' + workDateRange.value[1]
  } else {
    delete w.work_date
  }
  return w
}

function fetchList() {
  loading.value = true
  const data = {
    page: query.page,
    limit: query.limit,
    where: buildWhere()
  }
  list(data)
    .then(res => {
      if (res.data.code === 200) {
        const d = res.data.data
        tableData.value = d.list || []
        total.value = d.count || 0
      } else {
        ElMessage.error(res.data.msg || '获取列表失败')
      }
    })
    .catch(() => ElMessage.error('请求失败'))
    .finally(() => { loading.value = false })
}

function fetchStat() {
  stat({ where: buildWhere() })
    .then(res => {
      if (res.data.code === 200) {
        Object.assign(statData, res.data.data)
      }
    })
    .catch(() => {})
}

function handleSearch() {
  query.page = 1
  fetchList()
  fetchStat()
}

function handleResetting() {
  workDateRange.value = []
  query.where = {}
  query.page = 1
  fetchList()
  fetchStat()
}

function handleEdit(row) {
  editForm.id = row.id
  editForm.work_date = row.work_date
  editForm.work_type = row.work_type
  editForm.work_hours = parseFloat(row.work_hours) || 12
  editForm.remark = row.remark || ''
  dialogVisible.value = true
}

function submitEdit() {
  submitting.value = true
  edit({ id: editForm.id, ...editForm })
    .then(res => {
      if (res.data.code === 200) {
        ElMessage.success('修改成功')
        dialogVisible.value = false
        fetchList()
        fetchStat()
      } else {
        ElMessage.error(res.data.msg || '修改失败')
      }
    })
    .catch(() => ElMessage.error('请求失败'))
    .finally(() => { submitting.value = false })
}

function handleDelete(row) {
  ElMessageBox.confirm('确定要删除这条日工记录吗？', '确认删除', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  })
    .then(() => {
      dele({ id: row.id })
        .then(res => {
          if (res.data.code === 200) {
            ElMessage.success('删除成功')
            fetchList()
            fetchStat()
          } else {
            ElMessage.error(res.data.msg || '删除失败')
          }
        })
        .catch(() => ElMessage.error('请求失败'))
    })
    .catch(() => {})
}

onMounted(() => {
  fetchList()
  fetchStat()
})
</script>

<style scoped>
.filter-card {
  margin-bottom: 12px;
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.filter-form-item {
  margin-bottom: 0;
}
.stat-row {
  margin-bottom: 12px;
}
.stat-card {
  text-align: center;
}
.stat-label {
  font-size: 13px;
  color: #909399;
  margin-bottom: 8px;
}
.stat-value {
  font-size: 22px;
  font-weight: 600;
  color: #303133;
}
.stat-value--total {
  color: #e74c3c;
}
.list-card {
  min-height: 400px;
}
.total-wage-cell {
  font-weight: 600;
  color: #e74c3c;
}
.list-pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
