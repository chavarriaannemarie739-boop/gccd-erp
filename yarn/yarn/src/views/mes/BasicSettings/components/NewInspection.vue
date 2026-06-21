<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="工序编码：" prop="processes_code">
        <el-input
          v-model="userForm.processes_code"
          disabled
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="检验编码：" prop="check_code">
        <el-select
          @change="dwadadwa"
          v-model="userForm.check_code"
          style="width: 300px"
          value-key="id"
          placeholder="请选择"
        >
          <el-option
            v-for="item in tableData"
            :key="item.id"
            :label="item.check_code"
            :value="item"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="检验名称：" prop="check_name">
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
import { addCheck, listGoods } from '@/api/mes/ProcessManagement/ProcessManagement'
import { list } from '@/api/mes/QualityInspectionItem/QualityInspectionItem'
export default {
  name: 'NewProcess',
  props: {
    processes: {
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
      tableData: [],
      warehouse: [
        { id: 0, name: '否' },
        { id: 1, name: '是' }
      ],
      check_code: '后台自动生成',
      userForm: {
        processes_code: '',
        check_code: '',
        check_name: ''
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 1000000,
        check_code: ''
      },
      handleRules: {
        check_name: [{ required: true, message: ' 检验名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.listGoods()
    this.list()
    this.userForm.processes_code = this.processes.processes_code
  },
  methods: {
    // 检验项
    async list() {
      const resp = await list(this.query)
      this.tableData = resp.data.list
    },
    dwadadwa(row) {
      this.userForm.check_code = row.check_code
      this.userForm.check_name = row.check_name
      console.log(row)
    },
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    // 生产工序
    async listGoods() {
      const resp = await listGoods({ processes_code: this.processes.processes_code })
      console.log(resp)
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        // 判断
        if (!value) return
        try {
          // 调接口发请求
          await addCheck(this.userForm)
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
