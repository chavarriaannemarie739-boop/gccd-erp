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
      <el-form-item label="工艺路线：" prop="routing_code">
        <el-select v-model="userForm.routing_code" placeholder="请选择" style="width: 300px">
          <el-option
            v-for="item in options"
            :key="item.id"
            :label="item.routing_code"
            :value="item.routing_code"
          />
        </el-select>
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
          <el-table-column prop="material_name" label="物料名称" />
          <el-table-column prop="material_spec" label="物料规格" />
          <el-table-column prop="plan_num" label="计划数量" />
          <el-table-column prop="remark" label="备注" />
          <el-table-column prop="task_num" label="下推数量">
            <template #default="{ row }">
              <el-input v-model="row.task_num" placeholder="请输入" style="width: 100px" />
            </template>
          </el-table-column>
        </el-table>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">确定</el-button>
    </div>
  </div>
</template>

<script>
import { info, push } from '@/api/mes/ProductionOrderList/ProductionOrderList'
import { list } from '@/api/mes/ProcessRoute/ProcessRoute'
export default {
  name: 'PushDownPlan',
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
        id: '',
        create_uid: '',
        delete_time: '',
        delete_uid: '',
        production_plan_code: '',
        production_plan_name: '',
        create_time: '',
        status: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 10
      },
      options: []
    }
  },
  mounted() {
    this.info()
    this.list()
  },
  methods: {
    async info() {
      const resp = await info({ id: this.view.id })
      this.userForm = resp.data
    },
    async list() {
      const resp = await list(this.query)
      this.options = resp.data.list
      console.log(this.options, ' this.options')
    },
    async handleAppEdit() {
      try {
        await push(this.userForm)
        this.$emit('list')
        this.hanleAddDialogClose()
        ElMessage({
          message: '操作成功',
          type: 'success'
        })
      } catch (err) {
        //
      }
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
