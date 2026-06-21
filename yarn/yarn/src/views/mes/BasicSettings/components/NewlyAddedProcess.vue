<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="工序名称：" prop="processes_code">
        <el-select
          v-model="userForm.processes_name"
          style="width: 300px"
          value-key="id"
          placeholder="请选择"
          @change="handleChange"
        >
          <el-option
            v-for="item in options"
            :key="item.id"
            :label="item.processes_name"
            :value="item"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="工资额" prop="wage">
        <el-input v-model="userForm.wage" placeholder="月薪填月总额，日薪填单日全额" style="width: 300px" />
      </el-form-item>
      <el-form-item label="职位名称:" prop="post_name">
        <el-input v-model="userForm.post_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="用户:" prop="user_id">
        <el-select
          v-model="userForm.user_id"
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
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { add } from '@/api/mes/ProcessSalary/ProcessSalary'
import { list } from '@/api/mes/ProcessManagement/ProcessManagement'
import { listGoods } from '@/api/system/user'
export default {
  name: 'NewlyAddedProcess',
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
      options: [],
      salary: [
        { id: 0, name: '日薪（元/天）' },
        { id: 1, name: '月薪（元/月）' }
      ],
      optionsGoods: [],
      check_code: '后台自动生成',
      userForm: {
        processes_name: '',
        processes_code: '',
        wage: '',
        is_month: 1,
        post_name: '',
        user_id: '',
        status: 1
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 10000000
      },
      // 与 /admin/system.User/list 对齐：分页参数须顶层 page、limit（勿包在 query 内，否则后端取默认 limit=10，下拉只显示少量用户）
      queryGoods: {
        page: 1,
        limit: 100000,
        search_field: 'username',
        search_exp: 'like',
        date_field: 'create_time'
      },
      handleRules: {
        processes_code: [{ required: true, message: '请选择工序', trigger: 'change' }],
        wage: [{ required: true, message: '请输入工资额', trigger: 'blur' }],
        post_name: [{ required: true, message: '请输入职位名称', trigger: 'blur' }],
        user_id: [{ required: true, message: '请选择人员', trigger: 'change' }],
        is_month: [{ required: true, message: '请选择计薪方式', trigger: 'change' }]
      }
    }
  },
  mounted() {
    this.list()
    this.listGoods()
  },
  methods: {
    // 生产工序
    async list() {
      const resp = await list(this.query)
      this.options = resp.data.list
    },
    // 生产工序
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.optionsGoods = resp.data.list
      // console.log(resp, '12312')
    },
    handleChange(row) {
      if (!row) {
        return
      }
      this.userForm.processes_code = row.processes_code
      this.userForm.processes_name = row.processes_name
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
          const payload = { ...this.userForm, status: 1 }
          if (payload.processes_name && typeof payload.processes_name === 'object') {
            payload.processes_code = payload.processes_name.processes_code
            payload.processes_name = payload.processes_name.processes_name
          }
          await add(payload)
          // console.log(this.userForm)
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
