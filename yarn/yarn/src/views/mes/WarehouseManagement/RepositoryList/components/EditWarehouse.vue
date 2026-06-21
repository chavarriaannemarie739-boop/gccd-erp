<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="仓库编码：" prop="stock_code">
        <el-input v-model="stock_code" disabled placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="仓库名称：" prop="stock_name">
        <el-input v-model="userForm.stock_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { edit } from '@/api/mes/WarehouseManagement/RepositoryList'
export default {
  name: 'EditWarehouse',
  props: {
    processes: {
      type: Array,
      default: () => []
    },
    isShow: {
      type: Boolean,
      default: false
    },
    view: {
      type: Object,
      default: () => {}
    }
  },
  data() {
    return {
      warehouse: [
        { id: 0, name: '否' },
        { id: 1, name: '是' }
      ],
      stock_code: '后台自动生成',
      userForm: {
        id: '',
        stock_code: '',
        stock_name: ''
      },
      handleRules: {
        stock_name: [{ required: true, message: '工序名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.userForm.id = this.view.id
    this.userForm.stock_code = this.view.stock_code
    this.userForm.stock_name = this.view.stock_name
  },
  methods: {
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        // 判断
        if (!value) return
        try {
          // 调接口发请求
          await edit(this.userForm)
          this.$emit('list')
          // 成功的提示
          ElMessage({
            message: '操作成功',
            type: 'success'
          })
          this.hanleAddDialogClose()
        } catch (err) {
          // 失败的提示
          // ElMessage.error(err.message || '添加失败')
        }
      })
    }
  }
}
</script>

<style scoped>
.btn {
  text-align: right;
}
</style>
