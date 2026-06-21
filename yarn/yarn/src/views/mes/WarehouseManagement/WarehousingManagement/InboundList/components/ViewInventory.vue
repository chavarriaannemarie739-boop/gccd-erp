<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="入库编号：">
        <el-input v-model="routing_name" disabled placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="仓库信息：">
        <el-select
          v-model="userForm.warehouse"
          filterable
          placeholder="请选择"
          style="width: 300px"
        >
          <el-option
            v-for="item in options"
            :key="item.stock_code"
            :label="item.stock_name"
            :value="item.stock_name"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="库位信息：">
        <el-input v-model="userForm.location" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="物料内容：" prop="material_spec">
        <el-button round type="primary" @click="addOrderMatter" style="margin-left: 20px"
          >添加物料</el-button
        >
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
          <el-table-column prop="material_spec" label="物料规格" />
          <el-table-column prop="in_num" label="入库数量">
            <template #default="{ row }">
              <el-input v-model="row.in_num" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="money" label="单价">
            <template #default="{ row }">
              <el-input v-model="row.money" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="sum" label="总额">
            <template #default="{ row }">
              <el-input v-model="row.sum" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注">
            <template #default="{ row }">
              <el-input v-model="row.remark" placeholder="请输入" style="width: 110px" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="100">
            <template #default="scope">
              <el-button type="danger" plain @click="delOrderMatter(scope.$index, scope.row)"
                >删除</el-button
              >
            </template>
          </el-table-column>
        </el-table>
      </el-form-item>
    </el-form>
    <el-dialog
      v-model="processVisible"
      v-if="processVisible"
      width="70%"
      :close-on-click-modal="false"
    >
      <el-row :gutter="24">
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
      </el-row>
      <el-row />
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
        @selection-change="handleSelectionChange"
      >
        <el-table-column show-overflow-tooltip :reserve-selection="true" type="selection" />
        <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
        <el-table-column prop="material_name" label="物料名称" show-overflow-tooltip />
        <el-table-column prop="material_spec" label="物料规格" show-overflow-tooltip />
        <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
      </el-table>
      <!-- 分页 -->
      <el-pagination
        style="margin-top: 10px"
        :page-sizes="[10, 30, 50, 100]"
        :current-page="query.page"
        :page-size="query.limit"
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        background
        @size-change="handleSizeChangeGoods"
        @current-change="handleCurrentChangeGoods"
      />
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
import { info, edit } from '@/api/mes/WarehouseManagement/WarehouseManagement'
import { listGoods } from '@/api/mes/WarehouseManagement/RepositoryList'
import { list } from '@/api/mes/MaterialManagement/MaterialManagement'
export default {
  name: 'CreateInventory',
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
      total: 0,
      selection: [],
      userForm: {
        id: '',
        AdminToken: '',
        location: '',
        in_code: '',
        warehouse: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 10
      },
      queryGoods: {
        AdminToken: '',
        page: 1,
        limit: 100000000
      },
      handleRules: {
        routing_name: [{ required: true, message: '工艺名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.userForm.id = this.view.id
    this.listGoods()
    this.info()
  },
  methods: {
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.options = resp.data.list
    },
    async info() {
      const resp = await info({ id: this.view.id })
      this.userForm = resp.data
    },
    addOrderMatter() {
      this.processVisible = true
      this.list()
    },
    // 操作
    handleSelectionChange(selection) {
      this.selection = selection
    },
    // 条数
    handleSizeChangeGoods(val) {
      this.query.limit = val
      // 获取列表
      this.list()
    },
    // 页数
    handleCurrentChangeGoods(val) {
      this.query.page = val
      // 获取列表
      this.list()
    },
    getRowKeys(row) {
      return row.id
    },
    processSubmit() {
      if (!this.selection.length) return
      for (const item of this.selection) {
        const mid = String(item.id)
        const dup = this.userForm.content.some(
          (el) => String(el.material_id ?? '') === mid
        )
        if (dup) {
          ElMessage.error('当前物料已存在，请重新选择')
          return
        }
        this.userForm.content.push({
          material_name: item.material_name,
          material_spec: item.material_spec,
          material_model: item.material_model ?? '',
          material_id: item.id,
          supplier: (item.supplier ?? '').trim(),
          in_num: '',
          money: '',
          sum: '',
          remark: '',
          _isNew: true
        })
      }
      this.selection = []
      this.$refs.tableDataRef?.clearSelection?.()
      this.processVisible = false
    },
    // 生产工序
    async list() {
      this.loading = true
      const resp = await list(this.query)
      this.processes = resp.data.list
      this.total = resp.data.count
      this.loading = false
    },
    // 处理序号
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
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
          const params = {
            list: {
              in_code: this.userForm.in_code,
              warehouse: this.userForm.warehouse,
              location: this.userForm.location,
              relation_code: this.userForm.relation_code ?? '',
              content: []
            }
          }
          this.userForm.content.forEach((item) => {
            const row = {
              material_id: item.material_id,
              material_name: item.material_name,
              material_spec: item.material_spec,
              remark: item.remark,
              in_num: item.in_num,
              money: item.money,
              sum: item.sum,
              supplier: item.supplier
            }
            if (!item._isNew && item.id != null && item.id !== '') {
              row.id = item.id
            }
            params.list.content.push(row)
          })
          await edit(params)
          this.$emit('list')
          this.hanleAddDialogClose()
          ElMessage({
            message: '操作成功',
            type: 'success'
          })
        } catch (err) {
          //
        }
        // try {
        //   // 调接口发请求
        //   await edit(this.userForm)
        //   this.$emit('list')
        //   // 成功的提示
        //   ElMessage({
        //     message: '操作成功',
        //     type: 'success'
        //   })
        //   this.hanleAddDialogClose()
        // } catch (err) {
        //   // 失败的提示
        //   // ElMessage.error(err.message || '添加失败')
        // }
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
