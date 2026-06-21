<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="支数：" prop="yarn_count">
        <el-input v-model="userForm.yarn_count" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="生产任务单号：" prop="relation_code">
        <el-input v-model="userForm.relation_code" placeholder="选错工单时可改回正确 task_code" style="width: 300px" />
      </el-form-item>
      <el-form-item label="报工吨数：" prop="report_num">
        <el-input v-model="userForm.report_num" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="班次：">
        <el-select v-model="userForm.class" style="width: 300px">
          <el-option label="白班" :value="0" />
          <el-option label="夜班" :value="1" />
        </el-select>
      </el-form-item>
      <el-form-item label="归属日期：">
        <el-date-picker v-model="userForm.report_date" type="date" placeholder="选择归属日期" value-format="YYYY-MM-DD" style="width: 300px" />
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { edit } from '@/api/mes/EnergyConsumption/EnergyConsumption'
export default {
  name: 'EditorsReport',
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
      check_code: '后台自动生成',
      userForm: {
        report_num: '',
        yarn_count: '',
        id: '',
        report_date: '',
        class: 0
      },
      handleRules: {
        check_name: [{ required: true, message: '工序名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.userForm = this.view
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
