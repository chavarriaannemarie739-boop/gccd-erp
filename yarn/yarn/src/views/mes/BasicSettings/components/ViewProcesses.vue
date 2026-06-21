<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm">
      <el-form-item label="工艺名称：" prop="routing_name">
        <el-input
          disabled
          v-model="userForm.routing_name"
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="工序：" prop="material_spec">
        <el-table
          :data="userForm.content"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <el-table-column type="index" label="序号" width="55" />
          <el-table-column prop="process_code" label="工序编号" />
          <el-table-column prop="sort" label="顺序">
            <template #default="{ row }">
              {{ row.sort }}
            </template>
          </el-table-column>
          <el-table-column label="操作" width="100">
            <template #default="scope">
              <el-button type="danger" plain @click="delOrderMatter(scope.$index, scope.row)"
                >删除</el-button
              >
            </template>
          </el-table-column>
        </el-table>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
    </div>
  </div>
</template>

<script>
import { info } from '@/api/mes/ProcessRoute/ProcessRoute'
export default {
  name: 'ViewProcess',
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
      userForm: {
        routing_name: '',
        content: []
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
