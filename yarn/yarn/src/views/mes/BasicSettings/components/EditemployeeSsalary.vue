<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="工序名称：" prop="processes_name">
        <el-input
          v-model="userForm.processes_name"
          disabled
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="工资：" prop="wage">
        <el-input v-model="userForm.wage" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="职位名称:" prop="post_name">
        <el-input v-model="userForm.post_name" disabled placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="用户:" prop="user_id">
        <el-select
          v-model="userForm.user_id"
          disabled
          style="width: 300px"
          value-key="id"
          placeholder="请选择"
        >
          <el-option
            v-for="item in optionsGoods"
            :key="item.user_id"
            :label="item.nickname"
            :value="item.user_id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="是否月工资:" prop="is_month">
        <el-select
          disabled
          v-model="userForm.is_month"
          style="width: 300px"
          value-key="id"
          placeholder="请选择"
        >
          <el-option v-for="item in salary" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { edit } from '@/api/mes/ProcessSalary/ProcessSalary'
import { listGoods } from '@/api/system/user'
export default {
  name: 'EditemployeeSsalary',
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
      salary: [
        { id: 0, name: '否' },
        { id: 1, name: '是' }
      ],
      optionsGoods: [],
      check_code: '后台自动生成',
      userForm: {
        id: '',
        wage: '',
        processes_name: '',
        is_month: '',
        processes_code: '',
        post_name: '',
        user_id: ''
      },
      handleRules: {
        check_name: [{ required: true, message: '工序名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.userForm.id = this.view.id
    this.userForm.wage = this.view.wage
    this.userForm.processes_name = this.view.processes_name
    this.userForm.processes_code = this.view.processes_code
    this.userForm.is_month = this.view.is_month
    this.userForm.post_name = this.view.post_name
    this.userForm.user_id = this.view.user_id
    this.listGoods()
  },
  methods: {
    // 生产工序
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.optionsGoods = resp.data.list
      // console.log(resp, '12312')
    },
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
