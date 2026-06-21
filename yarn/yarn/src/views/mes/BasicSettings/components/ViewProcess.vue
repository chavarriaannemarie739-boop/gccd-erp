<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm">
      <el-form-item label="工序编码：" prop="processes_code">
        <el-input
          v-model="userForm.processes_code"
          disabled
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="工序名称：" prop="processes_name">
        <el-input
          disabled
          v-model="userForm.processes_name"
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="是否为最后一道工序：" prop="product_specs">
        <el-select
          disabled
          v-model="userForm.is_finnally"
          placeholder="请选择"
          style="width: 300px"
        >
          <el-option v-for="item in warehouse" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
    </div>
  </div>
</template>

<script>
import { info } from '@/api/mes/ProcessManagement/ProcessManagement'
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
      warehouse: [
        { id: 0, name: '否' },
        { id: 1, name: '是' }
      ],
      processes_code: '后台自动生成',
      userForm: {
        processes_name: '',
        processes_code: '',
        is_finnally: 0
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
      console.log(resp, '12312')
    },
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    }
    // handleAppEdit() {
    //   this.$emit('list')
    // }
  }
}
</script>

<style scoped>
.btn {
  text-align: right;
}
</style>
