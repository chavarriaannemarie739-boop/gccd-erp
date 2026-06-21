<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px">
      <el-form-item label="报工信息：" prop="material_spec">
        <el-table
          :data="userForm"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <el-table-column prop="processes_code" label="工序编号" show-overflow-tooltip />
          <el-table-column prop="report_code" label="报工编号" show-overflow-tooltip />
          <el-table-column prop="relation_code" label="关联编号" show-overflow-tooltip />
          <el-table-column prop="routing_code" label="工艺编码" show-overflow-tooltip />
          <el-table-column prop="report_num" label="报工数量" show-overflow-tooltip />
          <el-table-column prop="remark" label="备注" show-overflow-tooltip />
          <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
        </el-table>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
    </div>
  </div>
</template>

<script>
import { info } from '@/api/mes/EnergyConsumption/EnergyConsumption'
export default {
  name: 'JobapplicationDetails',
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
      userForm: [],
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
      this.userForm = [resp.data]
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
