<template>
  <!-- [AI-2026-08] 生产计划详情圆钢统一名称、厂商、材质、型号 -->
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
      <el-form-item label="物料内容：" prop="material_spec">
        <el-table
          :data="userForm.content"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <el-table-column type="index" label="序号" width="55" />
          <el-table-column prop="material_name" label="圆钢名称" />
          <el-table-column prop="manufacturer" label="厂商" />
          <el-table-column prop="material_spec" label="材质" />
          <el-table-column prop="material_model" label="型号" />
          <el-table-column prop="plan_num" label="计划数量" />
          <el-table-column prop="remark" label="备注" />
        </el-table>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
    </div>
  </div>
</template>

<script>
import { info } from '@/api/mes/ProductionOrderList/ProductionOrderList'
export default {
  name: 'ViewProductionPlan',
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
    this.info()
  },
  methods: {
    async info() {
      const resp = await info({ id: this.view.id })
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
