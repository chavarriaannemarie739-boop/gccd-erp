<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input v-model="query.repair_name" placeholder="维修项名称" clearable size="small" class="filter-input" />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-select v-model="query.parent_id" placeholder="所属大类" clearable size="small" class="filter-input">
            <el-option v-for="item in categoryList" :key="item.id" :label="item.repair_name" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="handleSearch">搜索</el-button>
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter">
      <el-card style="max-width: 800px">
        <div style="margin-bottom: 20px">
          <el-button type="primary" @click="handleAdd(0)">新增</el-button>
          <el-button type="danger" :disabled="type" v-preventReClick @click="dele">删除</el-button>
          <el-button type="primary" v-preventReClick :disabled="selectList.length !== 1" @click="EditMaterials(selectList[0])">编辑</el-button>
        </div>
        <el-table
          ref="tableDataRefss"
          :data="tableData"
          border
          fit
          style="width: 100%"
          :header-cell-style="{ 'text-align': 'center' }"
          :cell-style="{ 'text-align': 'center' }"
          v-loading="loading"
          row-key="id"
          :tree-props="{ children: 'children', hasChildren: 'hasChildren' }"
          default-expand-all
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" align="center" :reserve-selection="true" width="50" />
          <el-table-column prop="parent_name" label="所属大类" width="120" />
          <el-table-column prop="repair_name" label="维修项名称" min-width="200" />
        </el-table>
        <el-dialog
          title="新增"
          v-if="processVisible"
          v-model="processVisible"
          width="30%"
          :close-on-click-modal="false"
        >
          <NewMaintenance :default-parent-id="defaultParentId" :category-list="categoryList" v-model:is-show="processVisible" @list="list" />
        </el-dialog>
        <el-dialog
          title="编辑"
          v-if="processVisibleGoodsquery"
          v-model="processVisibleGoodsquery"
          width="30%"
          :close-on-click-modal="false"
        >
          <EditAndRepair :view="view" :category-list="categoryList" @list="list" v-model:is-show="processVisibleGoodsquery" />
        </el-dialog>
      </el-card>
    </div>
    <router-view />
  </div>
</template>

<script>
import { list, dele, category } from '@/api/mes/RepairItem/RepairItem'
import NewMaintenance from './components/NewMaintenance.vue'
import EditAndRepair from './components/EditAndRepair.vue'
export default {
  name: 'RepairItem',
  components: {
    NewMaintenance,
    EditAndRepair
  },
  data() {
    return {
      type: true,
      view: {},
      selectList: [],
      processVisible: false,
      processVisibleGoodsquery: false,
      defaultParentId: 0,
      categoryList: [],
      tableData: [],
      loading: true,
      query: {
        page: 1,
        limit: 100,
        repair_name: '',
        parent_id: ''
      }
    }
  },
  activated() {
    this.getCategoryList()
    this.list()
  },
  methods: {
    async getCategoryList() {
      const resp = await category()
      this.categoryList = resp.data || []
    },
    handleAdd(parentId) {
      this.defaultParentId = parentId
      this.processVisible = true
    },
    EditMaterials(row) {
      this.view = row
      this.processVisibleGoodsquery = true
    },
    handleSelectionChange(val) {
      this.selectList = val
      this.type = this.selectList.length === 0
    },
    async list() {
      this.loading = true
      const resp = await list(this.query)
      let tree = resp.data.list || []
      this.fillParentName(tree)
      this.tableData = tree
      this.loading = false
    },
    fillParentName(tree) {
      const catMap = {}
      this.categoryList.forEach(c => { catMap[c.id] = c.repair_name })
      const walk = (items) => {
        items.forEach(item => {
          item.parent_name = item.parent_id ? (catMap[item.parent_id] || '') : ''
          if (item.children) walk(item.children)
        })
      }
      walk(tree)
    },
    handleSearch() {
      this.query.page = 1
      this.list()
    },
    handleResetting() {
      this.query.repair_name = ''
      this.query.parent_id = ''
      this.handleSearch()
    },
    dele() {
      ElMessageBox.confirm('是否删除所选维修项', '温馨提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
        .then(async () => {
          const params = { ids: [] }
          this.selectList.forEach((item) => {
            params.ids.push(item.id)
          })
          await dele(params)
          this.list()
          this.getCategoryList()
          ElMessage({ type: 'success', message: '删除成功' })
          this.type = true
        })
        .catch(() => {})
    }
  }
}
</script>
<style scoped>
.contenter {
  margin-top: 10px;
  text-align: center;
}
</style>
