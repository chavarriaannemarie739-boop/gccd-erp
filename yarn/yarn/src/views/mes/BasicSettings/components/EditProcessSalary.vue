<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="工序编号：" prop="processes_code">
        <el-input v-model="userForm.processes_code" clearable placeholder="请输入工序编号" style="width: 300px" />
      </el-form-item>
      <el-form-item label="工序名称：" prop="processes_name">
        <el-input v-model="userForm.processes_name" clearable placeholder="请输入工序名称" style="width: 300px" />
      </el-form-item>
      <el-form-item label="工资额" prop="wage">
        <el-input v-model="userForm.wage" placeholder="月薪填月总额，日薪填单日全额" style="width: 300px" />
      </el-form-item>
      <el-form-item label="职位名称:" prop="post_name">
        <el-input v-model="userForm.post_name" clearable placeholder="请输入职位名称" style="width: 300px" />
      </el-form-item>
      <el-form-item label="用户:" prop="user_id">
        <el-select
          v-model="userForm.user_id"
          filterable
          clearable
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
      <el-form-item label="计薪方式" prop="is_month">
        <el-select v-model="userForm.is_month" style="width: 300px" placeholder="请选择">
          <el-option v-for="item in salary" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="启用" prop="status">
        <el-switch v-model="userForm.status" :active-value="1" :inactive-value="0" />
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
  name: 'EditProcessSalary',
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
        { id: 0, name: '日薪（元/天）' },
        { id: 1, name: '月薪（元/月）' }
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
        user_id: '',
        status: 1
      },
      queryGoods: {
        page: 1,
        limit: 100000,
        search_field: 'username',
        search_exp: 'like',
        date_field: 'create_time'
      },
      handleRules: {
        processes_code: [{ required: true, message: '请输入工序编号', trigger: 'blur' }],
        processes_name: [{ required: true, message: '请输入工序名称', trigger: 'blur' }],
        wage: [{ required: true, message: '请输入工资额', trigger: 'blur' }],
        post_name: [{ required: true, message: '请输入职位名称', trigger: 'blur' }],
        user_id: [{ required: true, message: '请选择用户', trigger: 'change' }],
        is_month: [{ required: true, message: '请选择计薪方式', trigger: 'change' }]
      }
    }
  },
  mounted() {
    this.syncFormFromView()
    this.listGoods()
  },
  methods: {
    syncFormFromView() {
      const v = this.view || {}
      this.userForm.id = v.id
      this.userForm.wage = v.wage != null ? String(v.wage) : ''
      this.userForm.processes_name = v.processes_name != null ? String(v.processes_name) : ''
      this.userForm.processes_code = v.processes_code != null ? String(v.processes_code) : ''
      const im = v.is_month
      this.userForm.is_month = im === '' || im === null || im === undefined ? '' : Number(im)
      this.userForm.post_name = v.post_name != null ? String(v.post_name) : ''
      const uid = v.user_id
      this.userForm.user_id = uid === '' || uid === null || uid === undefined ? '' : Number(uid)
      this.userForm.status =
        v.status !== undefined && v.status !== null ? Number(v.status) : 1
    },
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
          const payload = {
            ...this.userForm,
            wage: this.userForm.wage === '' ? '' : Number(this.userForm.wage),
            is_month: this.userForm.is_month === '' ? '' : Number(this.userForm.is_month),
            user_id: this.userForm.user_id === '' ? '' : Number(this.userForm.user_id),
            status: Number(this.userForm.status)
          }
          // 调接口发请求
          await edit(payload)
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
