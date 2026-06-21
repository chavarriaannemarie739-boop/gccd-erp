<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="圆钢名称：" prop="material_name">
        <el-input v-model="userForm.material_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="圆钢规格：" prop="material_spec">
        <el-input v-model="userForm.material_spec" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="圆钢型号：" prop="material_model">
        <el-input v-model="userForm.material_model" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="物料属性：" prop="material_property">
        <el-input
          v-model="userForm.material_property"
          placeholder="创建后不可修改"
          style="width: 300px"
          disabled
        />
      </el-form-item>
      <el-form-item label="最低库存阈值：" prop="min_stock_qty">
        <el-input-number
          v-model="userForm.min_stock_qty"
          :min="0"
          :precision="3"
          :controls="true"
          placeholder="不填则不预警"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="是否为生产原料：" prop="is_product">
        <el-select v-model="userForm.is_product" placeholder="请选择" style="width: 300px">
          <el-option v-for="item in warehouse" :key="item.id" :label="item.name" :value="item.id" />
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
import { info, edit } from '@/api/mes/MaterialManagement/MaterialManagement'
export default {
  name: 'CreateMaterials',
  props: {
    view: {
      type: Object,
      default: () => ({})
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
      userForm: {
        material_name: '',
        material_spec: '',
        material_model: '',
        material_property: '',
        min_stock_qty: null,
        is_product: 0
      },
      handleRules: {
        material_name: [{ required: true, message: '圆钢名称不能为空', trigger: 'blur' }],
        material_spec: [{ required: true, message: '圆钢规格不能为空', trigger: 'blur' }],
        // material_model: [{ required: true, message: '型号不能为空', trigger: 'blur' }],
        material_property: [{ required: true, message: '物料属性不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.info()
  },
  methods: {
    async info() {
      const id = this.view?.id
      if (id == null || id === '') return
      const resp = await info({ id })
      this.userForm = { ...resp.data, id: resp.data?.id ?? id }
    },
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        // 判断
        if (!value) return
        const id = this.view?.id ?? this.userForm?.id
        if (id == null || id === '') {
          ElMessage.error('缺少物料编号，无法保存')
          return
        }
        try {
          await edit({ ...this.userForm, id })
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
