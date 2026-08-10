<template>
  <!-- [AI-2026-08] 圆钢档案：左树（名称>厂商>材质>型号）+ 右表，树只做导航筛选 -->
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_name"
            placeholder="圆钢名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.manufacturer"
            placeholder="厂商"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_spec"
            placeholder="材质"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.material_model"
            placeholder="型号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="order_times"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
            @change="handleChange"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button v-preventReClick type="primary" size="small" @click="handleSearch"
            >搜索</el-button
          >
          <el-button v-preventReClick size="small" @click="handleResetting">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <div class="contenter round-steel-layout">
      <el-card class="round-steel-tree-card" shadow="never">
        <div class="tree-toolbar">
          <el-input
            v-model="treeFilterText"
            placeholder="筛选名称或路径"
            clearable
            size="small"
            class="tree-filter-input"
          />
          <div class="tree-toolbar-actions">
            <el-button link type="primary" size="small" @click="expandAllTree">展开</el-button>
            <el-button link type="primary" size="small" @click="collapseAllTree">收起</el-button>
          </div>
        </div>
        <el-tree
          ref="roundSteelTreeRef"
          class="round-steel-tree"
          :data="treeData"
          node-key="id"
          highlight-current
          :expand-on-click-node="false"
          :default-expanded-keys="defaultExpandedKeys"
          :filter-node-method="filterTreeNode"
          :props="treeProps"
          @node-click="handleTreeNodeClick"
        >
          <template #default="{ data }">
            <span class="tree-node">
              <el-tag size="small" effect="plain" class="tree-node-tag">{{ data.tag }}</el-tag>
              <span class="tree-node-label">{{ data.label }}</span>
              <span class="tree-node-count">({{ data.count }})</span>
            </span>
          </template>
        </el-tree>
      </el-card>
      <el-card class="round-steel-table-card">
        <div style="margin-bottom: 20px">
          <el-button type="success" @click="CheckbelowGoods">新增</el-button>
          <el-button type="danger" :disabled="type" v-preventReClick @click="dele(row)"
            >删除</el-button
          >
          <el-button
            type="primary"
            :disabled="selectList.length !== 1"
            v-preventReClick
            @click="editSelected"
            >编辑</el-button
          >
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
          :row-key="getRowKeys"
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" align="center" :reserve-selection="true" width="40" />
          <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
          <el-table-column prop="material_name" label="圆钢名称" show-overflow-tooltip />
          <el-table-column prop="manufacturer" label="厂商" show-overflow-tooltip />
          <el-table-column prop="material_spec" label="材质" show-overflow-tooltip />
          <el-table-column prop="material_model" label="型号" show-overflow-tooltip />
          <el-table-column prop="create_time" label="创建时间" show-overflow-tooltip />
        </el-table>
        <el-pagination
          style="margin-top: 10px"
          :page-sizes="[30, 60, 100, 200]"
          :current-page="query.page"
          :page-size="query.limit"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          background
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </el-card>
      <el-dialog
        title="新增"
        v-if="processVisible"
        v-model="processVisible"
        width="30%"
        :close-on-click-modal="false"
      >
        <CreateMaterials
          v-model:is-show="processVisible"
          :material-property="query.material_property"
          @list="onArchiveChanged"
        />
      </el-dialog>
      <el-dialog
        title="编辑"
        v-if="processVisibleGoodsquery"
        v-model="processVisibleGoodsquery"
        width="30%"
        :close-on-click-modal="false"
      >
        <EditMaterials
          :view="view"
          @list="onArchiveChanged"
          v-model:is-show="processVisibleGoodsquery"
        />
      </el-dialog>
    </div>
  </div>
</template>

<script>
import { list, dele, roundSteelTree } from '@/api/mes/MaterialManagement/MaterialManagement'
import CreateMaterials from './components/CreateMaterials.vue'
import EditMaterials from './components/EditMaterials.vue'

const IDENTITY_FIELDS = ['material_name', 'manufacturer', 'material_spec', 'material_model']

export default {
  name: 'RoundSteelList',
  components: {
    CreateMaterials,
    EditMaterials
  },
  data() {
    return {
      type: true,
      view: {},
      selectList: [],
      processVisible: false,
      processVisibleGoodsquery: false,
      order_times: '',
      tableData: [],
      total: 0,
      treeData: [],
      treeFilterText: '',
      currentTreeKey: '',
      defaultExpandedKeys: [],
      treeProps: {
        children: 'children',
        label: 'label'
      },
      query: {
        AdminToken: '',
        page: 1,
        limit: 30,
        material_thickness: '',
        manufacturer: '',
        material_model: '',
        material_spec: '',
        create_end_date: '',
        create_start_date: '',
        material_property: '圆钢',
        material_name: ''
      },
      loading: true
    }
  },
  watch: {
    treeFilterText(val) {
      this.$refs.roundSteelTreeRef?.filter?.(val)
    }
  },
  mounted() {
    this.loadTree()
    this.list()
  },
  activated() {
    this.loadTree()
    this.list()
  },
  methods: {
    async loadTree() {
      try {
        const resp = await roundSteelTree({})
        this.treeData = resp.data?.tree || []
        this.defaultExpandedKeys = this.treeData.map((item) => item.id)
        await this.$nextTick()
        if (this.currentTreeKey) {
          this.$refs.roundSteelTreeRef?.setCurrentKey?.(this.currentTreeKey)
        }
      } catch (e) {
        /* request 拦截器已提示 */
      }
    },
    filterTreeNode(value, data) {
      if (!value) return true
      const keyword = String(value).trim().toLowerCase()
      if (!keyword) return true
      const label = String(data.label || '').toLowerCase()
      const id = String(data.id || '').toLowerCase()
      return label.includes(keyword) || id.includes(keyword)
    },
    expandAllTree() {
      const tree = this.$refs.roundSteelTreeRef
      if (!tree?.store?.nodesMap) return
      Object.values(tree.store.nodesMap).forEach((node) => {
        node.expanded = true
      })
    },
    collapseAllTree() {
      const tree = this.$refs.roundSteelTreeRef
      if (!tree?.store?.nodesMap) return
      Object.values(tree.store.nodesMap).forEach((node) => {
        node.expanded = false
      })
    },
    parseTreeNodeFilters(nodeId) {
      const filters = {
        material_name: '',
        manufacturer: '',
        material_spec: '',
        material_model: ''
      }
      String(nodeId || '')
        .split('|')
        .filter(Boolean)
        .forEach((segment) => {
          const idx = segment.indexOf(':')
          if (idx < 0) return
          const field = segment.slice(0, idx)
          const value = segment.slice(idx + 1)
          if (IDENTITY_FIELDS.includes(field)) {
            filters[field] = value
          }
        })
      return filters
    },
    handleTreeNodeClick(data) {
      const filters = this.parseTreeNodeFilters(data.id)
      this.currentTreeKey = data.id
      this.query.material_name = filters.material_name
      this.query.manufacturer = filters.manufacturer
      this.query.material_spec = filters.material_spec
      this.query.material_model = filters.material_model
      this.query.page = 1
      this.list()
    },
    clearTreeSelection() {
      this.currentTreeKey = ''
      this.$refs.roundSteelTreeRef?.setCurrentKey?.(null)
    },
    CheckbelowGoods() {
      this.processVisible = true
    },
    editSelected() {
      if (this.selectList.length !== 1) return
      this.view = this.selectList[0]
      this.processVisibleGoodsquery = true
    },
    getRowKeys(row) {
      return row.id
    },
    handleSelectionChange(val) {
      this.selectList = val
      this.type = val.length === 0
    },
    async list() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = resp.data.list
        this.total = resp.data.count
      } finally {
        this.loading = false
      }
    },
    async onArchiveChanged() {
      await Promise.all([this.list(), this.loadTree()])
    },
    handleSearch() {
      this.query.page = 1
      this.clearTreeSelection()
      this.list()
    },
    handleChange(val) {
      if (val && val.length === 2) {
        this.query.create_start_date = val[0]
        this.query.create_end_date = val[1]
      } else {
        this.query.create_start_date = ''
        this.query.create_end_date = ''
      }
    },
    handleIndex(index) {
      return index + 1 + this.query.limit * (this.query.page - 1)
    },
    handleSizeChange(val) {
      this.query.limit = val
      this.list()
    },
    handleCurrentChange(val) {
      this.query.page = val
      this.list()
    },
    handleResetting() {
      this.query.material_thickness = ''
      this.query.manufacturer = ''
      this.query.material_model = ''
      this.query.material_spec = ''
      this.query.create_end_date = ''
      this.query.create_start_date = ''
      this.query.material_property = '圆钢'
      this.query.material_name = ''
      this.order_times = []
      this.treeFilterText = ''
      this.clearTreeSelection()
      this.handleSearch()
    },
    dele() {
      ElMessageBox.confirm('是否删除该产品', '温馨提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
        .then(async () => {
          const params = {
            AdminToken: '',
            ids: []
          }
          this.selectList.forEach((item) => {
            params.ids.push(item.id)
          })
          await dele(params)
          await this.onArchiveChanged()
          this.selectList = []
          this.type = true
          await this.$nextTick()
          this.$refs.tableDataRefss?.clearSelection?.()
          ElMessage({
            type: 'success',
            message: '删除成功'
          })
        })
        .catch(() => {})
    }
  }
}
</script>
<style scoped>
.contenter {
  margin-top: 10px;
}
.round-steel-layout {
  display: flex;
  align-items: stretch;
  gap: 12px;
}
.round-steel-tree-card {
  width: 320px;
  flex: 0 0 320px;
  min-height: 560px;
}
.round-steel-table-card {
  flex: 1;
  min-width: 0;
}
.tree-toolbar {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}
.tree-toolbar-actions {
  display: flex;
  justify-content: flex-end;
  gap: 4px;
}
.tree-filter-input {
  width: 100%;
}
.round-steel-tree {
  max-height: calc(100vh - 280px);
  overflow: auto;
}
.tree-node {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding-right: 8px;
}
.tree-node-tag {
  flex-shrink: 0;
}
.tree-node-label {
  color: #303133;
}
.tree-node-count {
  color: #909399;
  font-size: 12px;
}
</style>
