<template>
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="工艺名称：" prop="routing_name">
        <el-input v-model="userForm.routing_name" placeholder="请输入" style="width: 300px" />
      </el-form-item>
      <el-form-item label="工序：" prop="material_spec">
        <el-button round type="primary" @click="addOrderMatter" style="margin-left: 20px"
          >添加工序</el-button
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
          <el-table-column label="工序名称" min-width="120">
            <template #default="{ row }">
              {{ processName(row.process_code) }}
            </template>
          </el-table-column>
          <el-table-column prop="process_code" label="工序编号" />
          <el-table-column prop="sort" label="顺序">
            <template #default="{ row }">
              <el-input v-model="row.sort" placeholder="序号越小越靠前" style="width: 150px" />
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
          <el-form-item label="工序编码：">
            <el-input style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="工序名称：">
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
        <el-table-column prop="processes_name" label="工序名称" show-overflow-tooltip />
        <el-table-column prop="processes_code" label="工序编号" show-overflow-tooltip />
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
import { add } from '@/api/mes/ProcessRoute/ProcessRoute'
import { list } from '@/api/mes/ProcessManagement/ProcessManagement'
export default {
  name: 'CreateProcess',
  props: {
    isShow: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      processVisible: false,
      loading: true,
      processes: [],
      total: 0,
      selection: [],
      processMap: {}, // process_code → process_name 映射表
      userForm: {
        routing_name: '',
        content: []
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 2000
      },
      handleRules: {
        routing_name: [{ required: true, message: '工艺名称不能为空', trigger: 'blur' }]
      }
    }
  },
  mounted() {
    this.loadProcessMap()
  },
  methods: {
    /** 加载工序列表，构建 code→name 映射 */
    async loadProcessMap() {
      try {
        const resp = await list({ page: 1, limit: 2000 })
        const arr = (resp.data && resp.data.list) || []
        const map = {}
        arr.forEach(p => { if (p.processes_code) map[p.processes_code] = p.processes_name })
        this.processMap = map
      } catch(e) {
        console.warn('加载工序列表失败', e)
      }
    },
    /** 通过工序编码获取名称 */
    processName(code) {
      return this.processMap[code] || code
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
      let maxSort = 0
      this.userForm.content.forEach(c => {
        const s = parseInt(c.sort, 10)
        if (!isNaN(s) && s > maxSort) maxSort = s
      })
      this.selection.forEach((item) => {
        const code = item.processes_code
        if (this.userForm.content.some(c => c.process_code === code)) return
        maxSort++
        this.userForm.content.push({
          process_code: code,
          sort: maxSort
        })
      })
      this.selection = []
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
