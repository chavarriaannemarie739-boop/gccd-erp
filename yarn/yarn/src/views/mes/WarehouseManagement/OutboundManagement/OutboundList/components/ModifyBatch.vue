<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="物料批次：">
        <el-input
          @click="handleClickmaterial"
          v-model="material_batch"
          placeholder="请输入"
          style="width: 300px"
        />
        <!-- <el-select
          filterable
          v-model="material_batch"
          @change="handleChang"
          value-key="material_id"
          placeholder="请选择"
          style="width: 260px"
        >
          <el-option
            v-for="item in processes"
            :key="item.material_id"
            :label="item.material_batch"
            :value="item"
          />
        </el-select> -->
      </el-form-item>
      <!-- <el-form-item label="出库内容：" prop="material_spec">
        <el-table
          :data="userForm.content"
          border
          fit
          style="width: 100%; margin-top: 20px"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
        >
          <el-table-column type="index" label="序号" width="55" />
          <el-table-column prop="material_name" label="物料名称" />
          <el-table-column prop="material_batch" label="物料批次" show-overflow-tooltip />
          <el-table-column prop="batch_balance" label="物料批次剩余数量" />
          <el-table-column prop="price" label="供应商价格">
            <template #default="{ row }">
              <el-input v-model="row.price" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="supplier" label="供应商">
            <template #default="{ row }">
              <el-input v-model="row.supplier" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
        </el-table>
      </el-form-item> -->
    </el-form>
    <el-dialog
      v-model="processVisible"
      v-if="processVisible"
      width="70%"
      :close-on-click-modal="false"
    >
      <!-- <el-row :gutter="24">
        <el-col :span="6">
          <el-form-item label="物料名称：">
            <el-input style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="物料规格：">
            <el-input style="width: 260px" placeholder="请输入" class="filter-item" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item>
            <el-button v-preventReClick type="primary">搜索</el-button>
            <el-button v-preventReClick>重置</el-button>
          </el-form-item>
        </el-col>
      </el-row> -->
      <el-table
        :border="true"
        :show-overflow-tooltip="true"
        :data="processes"
        v-loading="loading"
        stripe
        :row-key="getRowKeys"
        ref="tableDataRef"
        :header-cell-style="{ 'text-align': 'center' }"
        :cell-style="{ 'text-align': 'center' }"
        @row-click="handleClickAdd"
      >
        <!-- <el-table-column show-overflow-tooltip :reserve-selection="true" type="selection" /> -->
        <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
        <el-table-column prop="material_name" label="物料名称" />
        <el-table-column prop="material_batch" label="物料批次" show-overflow-tooltip />
        <el-table-column prop="batch_balance" label="物料批次剩余数量" />
        <el-table-column prop="price" label="供应商价格" />
        <el-table-column prop="supplier" label="供应商" />
      </el-table>
      <!-- 分页 -->
      <!-- <el-pagination
        style="margin-top: 10px"
        :page-sizes="[10, 30, 50, 100]"
        :current-page="query.page"
        :page-size="query.limit"
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        background
        @size-change="handleSizeChangeGoods"
        @current-change="handleCurrentChangeGoods"
      /> -->
      <template #footer>
        <div class="dialog-footer">
          <el-button @click="processVisible = false">取消</el-button>
          <el-button type="primary" v-preventReClick @click="processSubmit"> 确认 </el-button>
        </div>
      </template>
    </el-dialog>
    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { editStatus } from '@/api/mes/WarehouseManagement/OutboundManagement'
import { list, getBatch, getMaterialBatch } from '@/api/mes/WarehouseManagement/WarehouseManagement'
// import { list } from '@/api/mes/MaterialManagement/MaterialManagement'
export default {
  name: 'ModifyBatch',
  props: {
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
      options: [],
      routing_name: '后台自动生成',
      processVisible: false,
      loading: true,
      processes: [],
      material_id: '',
      total: 0,
      selection: [],
      userForm: {
        AdminToken: '',
        out_code: '',
        content: []
      },
      material_batch: '',
      query: {
        AdminToken: '',
        page: 1,
        limit: 99999
      },
      handleRules: {
        routing_name: [{ required: true, message: '工艺名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    // this.list()
    this.material_id = this.view.material_id
    console.log(this.view)
    // this.getBatch()
    // this.getMaterialBatch()
  },
  methods: {
    handleClickmaterial() {
      this.processVisible = true
      this.getMaterialBatch()
    },
    // 操作
    handleSelectionChange(selection) {
      this.selection = selection
    },
    // 条数
    handleSizeChangeGoods(val) {
      this.query.limit = val
      // 获取列表
      this.getMaterialBatch()
    },
    // 页数
    handleCurrentChangeGoods(val) {
      this.query.page = val
      // 获取列表
      this.getMaterialBatch()
    },
    async getMaterialBatch() {
      this.loading = true
      const resp = await getMaterialBatch({ material_id: this.material_id })
      this.processes = resp.data
      this.loading = false
    },
    // 物料
    handleClickAdd(row) {
      this.material_batch = row.material_batch
      this.processVisible = false
    },
    getRowKeys(row) {
      return row.id
    },
    processSubmit() {
      this.selection.forEach((item, index) => {
        for (let i = 0; i < this.processes.length; i++) {
          const element = this.processes[i]
          if (element.id === item.id) {
            return ElMessage.error('当前物料已存在，请从新选择！')
          }
        }
        this.processes.push({
          material_name: item.material_name,
          material_spec: item.material_spec,
          id: item.id
        })
      })
      this.processVisible = false
    },
    // // 生产工序
    // async list() {
    //   this.loading = true
    //   const resp = await list(this.query)
    //   this.processes = resp.data.list
    //   this.loading = false
    // },
    // 生产工序
    async getBatch() {
      const resp = await getBatch()
      this.processes = resp.data
    },
    // 处理序号
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    // 关闭
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleChang(row) {
      console.log(row)
      this.material_batch = row.material_batch
    },
    handleAppEdit() {
      this.$refs.handleRef.validate(async (value) => {
        // 判断
        if (!value) return
        try {
          const params = {
            id: this.view.id,
            material_batch: this.material_batch
          }
          // this.userForm.content.forEach((item) => {
          //   params.list.content.push({
          //     material_id: item.id,
          //     material_name: item.material_name,
          //     material_spec: item.material_spec,
          //     remark: item.remark,
          //     out_num: item.out_num
          //   })
          // })
          await editStatus(params)
          this.$emit('list')
          this.hanleAddDialogClose()
          ElMessage({
            message: '操作成功',
            type: 'success'
          })
        } catch (err) {
          //
        }
      })
    },
    delOrderMatter(index, row) {
      var that = this
      that.userForm.content.splice(index, 1)
    }
  }
}
</script>

<style scoped>
.btn {
  text-align: right;
}
</style>
