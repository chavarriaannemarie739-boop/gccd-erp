<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="质检编码：" prop="check_code">
        <el-input v-model="check_code" disabled placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="质检名称：" prop="check_name">
        <el-input v-model="userForm.check_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { add } from '@/api/mes/QualityInspectionItem/QualityInspectionItem'
export default {
  name: 'AddQualityInspectionItems',
  props: {
    processes: {
      type: Array,
      default: () => []
    },
    isShow: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      check_code: '后台自动生成',
      userForm: {
        check_name: '',
        check_code: '',
        is_finally: 0
      },
      handleRules: {
        check_name: [{ required: true, message: '工序名称不能为空', trigger: 'blur' }]
      }
    }
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
          await add(this.userForm)
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
