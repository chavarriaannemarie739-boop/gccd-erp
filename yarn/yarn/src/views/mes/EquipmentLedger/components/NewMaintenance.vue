<template>
  <div class="app-container">
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="分类：" prop="parent_id">
        <el-select v-model="userForm.parent_id" placeholder="请选择" clearable style="width: 300px">
          <el-option label="作为大类" :value="0" />
          <el-option v-for="item in categoryList" :key="item.id" :label="item.repair_name" :value="item.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="维修项名称：" prop="repair_name">
        <el-input v-model="userForm.repair_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="排序：" prop="sort">
        <el-input-number v-model="userForm.sort" :min="0" :max="999" style="width: 300px" />
      </el-form-item>
      <el-form-item label="备注：" prop="remark">
        <el-input v-model="userForm.remark" placeholder="请输入" style="width: 300px" />
      </el-form-item>
    </el-form>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { add } from '@/api/mes/RepairItem/RepairItem'
export default {
  name: 'NewMaintenance',
  props: {
    defaultParentId: {
      type: [Number, String],
      default: 0
    },
    categoryList: {
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
      userForm: {
        parent_id: 0,
        repair_name: '',
        sort: 0,
        remark: ''
      },
      handleRules: {
        repair_name: [{ required: true, message: '维修项名称不能为空', trigger: 'blur' }]
      }
    }
  },
  watch: {
    defaultParentId: {
      immediate: true,
      handler(val) {
        this.userForm.parent_id = val === '' ? 0 : val
      }
    }
  },
  methods: {
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        if (!value) return
        try {
          await add(this.userForm)
          this.$emit('list')
          ElMessage({ message: '操作成功', type: 'success' })
          this.hanleAddDialogClose()
        } catch (err) {}
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
