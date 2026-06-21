<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px">
      <el-form-item label="生产计划名称：" prop="production_plan_name">
        <el-input
          v-model="userForm.production_plan_name"
          disabled
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="生产计划编号：" prop="production_plan_code">
        <el-input
          v-model="userForm.production_plan_code"
          disabled
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="物料内容：" prop="material_spec">
        <el-table
          :data="userForm.task"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <el-table-column type="index" label="序号" width="55" />
          <el-table-column prop="task_code" label="任务编号" show-overflow-tooltip />
          <el-table-column prop="relation_code" label="关联编号" show-overflow-tooltip />
          <el-table-column prop="routing_code" label="工艺编码" show-overflow-tooltip />
          <el-table-column prop="report_num" label="报工数量" show-overflow-tooltip />
          <el-table-column prop="task_num" label="任务数量" show-overflow-tooltip />
          <el-table-column prop="y_material_name" label="圆钢名称" show-overflow-tooltip />
          <el-table-column prop="y_material_spec" label="圆钢规格" show-overflow-tooltip />
        </el-table>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
    </div>
  </div>
</template>

<script>
import { getProcess } from '@/api/mes/ProductionOrderList/ProductionOrderList'
export default {
  name: 'OrderStatus',
  props: {
    view: {
      type: Object,
      default: () => {}
    },
    isShow: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      processVisible: false,
      loading: true,
      processes: [],
      total: 0,
      selection: [],
      userForm: {
        AdminToken: '',
        production_plan_name: '',
        production_plan_code: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 10
      }
    }
  },
  mounted() {
    this.getProcess()
  },
  methods: {
    async getProcess() {
      const resp = await getProcess({ id: this.view.id })
      this.userForm = resp.data
    },
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    }
  }
}
</script>

<style scoped>
.btn {
  text-align: right;
}
</style>
