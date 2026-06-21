<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="设备编码：" prop="device_code">
        <el-input v-model="device_code" disabled placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="设备名称：" prop="device_name">
        <el-input v-model="userForm.device_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="选择工序：" prop="processes_code">
        <el-select v-model="userForm.processes_code" placeholder="请选择" style="width: 300px">
          <el-option
            v-for="item in tableData"
            :key="item.id"
            :label="item.processes_name"
            :value="item.processes_code"
          />
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
import { add } from '@/api/mes/EquipmentLedger/EquipmentLedger'
import { list } from '@/api/mes/ProcessManagement/ProcessManagement'
export default {
  name: 'AddDevice',
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
      tableData: [],
      device_code: '后台自动生成',
      userForm: {
        device_name: '',
        device_code: '',
        processes_code: ''
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 100000000
      },
      handleRules: {
        device_name: [{ required: true, message: '工序名称不能为空', trigger: 'blur' }],
        processes_code: [{ required: true, message: '对应工序不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.list()
  },
  methods: {
    async list() {
      const resp = await list(this.query)
      this.tableData = resp.data.list
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
