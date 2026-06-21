<template>
  <div class="coefficient-manage">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>报表单价配置</span>
          <el-button type="primary" @click="handleAdd">新增单价</el-button>
        </div>
      </template>

      <!-- 筛选 -->
      <el-form :inline="true" :model="query" class="filter-form">
        <el-form-item label="单价类型">
          <el-select v-model="query.type" placeholder="全部" clearable @change="loadData">
            <el-option
              v-for="(label, value) in typeOptions"
              :key="value"
              :label="label"
              :value="value"
            />
          </el-select>
        </el-form-item>
      </el-form>

      <!-- 表格 -->
      <el-table :data="tableData" v-loading="loading" border>
        <el-table-column prop="type_name_display" label="单价类型" width="140" />
        <el-table-column prop="value" label="单价值" width="150">
          <template #default="{ row }">
            {{ row.value }} {{ row.unit }}
          </template>
        </el-table-column>
        <el-table-column prop="effective_date" label="生效日期" width="120" />
        <el-table-column prop="remark" label="说明" min-width="200" />
        <el-table-column prop="create_time" label="创建时间" width="180" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
            <el-popconfirm
              title="确定删除吗？"
              @confirm="handleDelete(row.id)"
            >
              <template #reference>
                <el-button type="danger" link>删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页 -->
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.limit"
        :total="total"
        :page-sizes="[10, 20, 50, 100]"
        layout="total, sizes, prev, pager, next, jumper"
        @size-change="loadData"
        @current-change="loadData"
        class="pagination"
      />
    </el-card>

    <!-- 新增/编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="500px"
      @close="handleDialogClose"
    >
      <el-form :model="form" :rules="rules" ref="formRef" label-width="100px">
        <el-form-item label="单价类型" prop="type">
          <el-select v-model="form.type" placeholder="请选择单价类型" :disabled="isEdit">
            <el-option
              v-for="(label, value) in typeOptions"
              :key="value"
              :label="label"
              :value="value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="单价值" prop="value">
          <el-input-number v-model="form.value" :precision="2" :step="0.01" :min="0" />
        </el-form-item>
        <el-form-item label="单位" prop="unit">
          <el-input v-model="form.unit" placeholder="如：元/吨、元/立方米、元/度" />
        </el-form-item>
        <el-form-item label="生效日期" prop="effective_date">
          <el-date-picker
            v-model="form.effective_date"
            type="date"
            placeholder="选择日期"
            value-format="YYYY-MM-DD"
          />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit" :loading="submitLoading">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import { ElMessage } from 'element-plus'
import { getCoefficientList, addCoefficient, editCoefficient, deleteCoefficient, getCoefficientTypes } from '@/api/report/coefficient'

export default {
  name: 'CoefficientManage',
  data() {
    return {
      loading: false,
      submitLoading: false,
      tableData: [],
      total: 0,
      query: {
        page: 1,
        limit: 15,
        type: ''
      },
      dialogVisible: false,
      dialogTitle: '新增系数',
      isEdit: false,
      form: {
        id: null,
        type: '',
        value: 0,
        unit: '',
        effective_date: '',
        remark: ''
      },
      typeOptions: {},
      rules: {
        type: [{ required: true, message: '请选择单价类型', trigger: 'change' }],
        value: [{ required: true, message: '请输入单价值', trigger: 'blur' }],
        effective_date: [{ required: true, message: '请选择生效日期', trigger: 'change' }]
      }
    }
  },
  created() {
    this.loadTypeOptions()
    this.loadData()
  },
  methods: {
    // 加载类型选项
    async loadTypeOptions() {
      try {
        const res = await getCoefficientTypes()
        if (res.code === 200) {
          this.typeOptions = res.data.type_options
        }
      } catch (error) {
        console.error('加载类型选项失败:', error)
      }
    },

    // 加载数据
    async loadData() {
      this.loading = true
      try {
        const res = await getCoefficientList(this.query)
        if (res.code === 200) {
          this.tableData = res.data.list
          this.total = res.data.count
        }
      } catch (error) {
        ElMessage.error('加载数据失败')
      } finally {
        this.loading = false
      }
    },

    // 新增
    handleAdd() {
      this.dialogTitle = '新增单价'
      this.isEdit = false
      this.dialogVisible = true
    },

    // 编辑
    handleEdit(row) {
      this.dialogTitle = '编辑单价'
      this.isEdit = true
      this.form = { ...row }
      this.dialogVisible = true
    },

    // 删除
    async handleDelete(id) {
      try {
        const res = await deleteCoefficient(id)
        if (res.code === 200) {
          ElMessage.success('删除成功')
          this.loadData()
        }
      } catch (error) {
        ElMessage.error('删除失败')
      }
    },

    // 提交
    async handleSubmit() {
      try {
        await this.$refs.formRef.validate()
        this.submitLoading = true

        const api = this.isEdit ? editCoefficient : addCoefficient
        const res = await api(this.form)

        if (res.code === 200) {
          ElMessage.success(this.isEdit ? '修改成功' : '添加成功')
          this.dialogVisible = false
          this.loadData()
        }
      } catch (error) {
        if (error !== false) { // 排除表单验证失败
          ElMessage.error('操作失败')
        }
      } finally {
        this.submitLoading = false
      }
    },

    // 关闭对话框
    handleDialogClose() {
      this.form = {
        id: null,
        type: '',
        value: 0,
        unit: '',
        effective_date: '',
        remark: ''
      }
      this.$refs.formRef?.resetFields()
    }
  }
}
</script>

<style scoped>
.coefficient-manage {
  padding: 20px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.filter-form {
  margin-bottom: 20px;
}

.pagination {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>
