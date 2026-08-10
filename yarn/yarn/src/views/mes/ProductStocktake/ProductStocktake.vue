<template>
  <!-- [AI-2026-08] 盘点圆钢业务字段统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_name"
            placeholder="圆钢名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_manufacturer"
            placeholder="圆钢厂商"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.process_name"
            placeholder="工序名称"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_spec"
            placeholder="圆钢材质"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.y_material_model"
            placeholder="型号"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.c_material_spec"
            placeholder="工序规格"
            clearable
            size="small"
            class="filter-input"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--date">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            size="small"
            class="filter-date"
            @change="handleDateChange"
          />
        </el-form-item>
        <el-form-item class="filter-form-item--actions">
          <el-button type="primary" size="small" @click="handleSearch">搜索</el-button>
          <el-button size="small" @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
    <el-card class="main-list-card" shadow="never">
      <el-table v-loading="loading" :data="tableData" border style="width: 100%; margin-top: 15px">
        <el-table-column type="index" label="序号" width="60" align="center" />
        <el-table-column prop="inventory_date" label="盘点日期" width="120" align="center" />
        <el-table-column prop="process_name" label="工序" width="100" align="center" />
        <el-table-column prop="y_material_name" label="圆钢名称" min-width="120" />
        <el-table-column prop="y_material_manufacturer" label="圆钢厂商" min-width="120" />
        <el-table-column prop="y_material_spec" label="圆钢材质" min-width="100" />
        <el-table-column prop="y_material_model" label="圆钢型号" min-width="100" />
        <el-table-column label="工序规格" min-width="120">
          <template #default="scope">
            <span>{{ scope.row.c_material_spec || scope.row.c_product_spec || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="total_tonnage" label="吨数" width="100" align="center">
          <template #default="scope">
            <el-tag type="success" effect="dark">{{ scope.row.total_tonnage }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="提交时间" width="160" align="center" />
      </el-table>

      <!-- 分页 -->
      <div style="margin-top: 20px; text-align: right">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.limit"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          @size-change="getList"
          @current-change="getList"
        />
      </div>
    </el-card>
  </div>
</template>

<script>
import { list } from '@/api/mes/ProductStocktake/ProductStocktake'
import { list as materialList } from '@/api/mes/MaterialManagement/MaterialManagement'

export default {
  name: 'ProductStocktake',
  data() {
    return {
      query: {
        page: 1,
        limit: 10,
        y_material_name: '',
        y_material_manufacturer: '',
        process_name: '',
        inventory_start_date: '',
        inventory_end_date: '',
        y_material_spec: '',
        y_material_model: '',
        c_material_spec: ''
      },
      dateRange: [],
      tableData: [],
      total: 0,
      loading: false,
      // 物料档案查找表：用于反查缺失的圆钢型号
      matLookup: {}
    }
  },
  created() {
    this._loadMaterialArchive()
    this.getList()
  },
  methods: {
    /** 加载圆钢物料档案，构建 "名称|规格" -> 型号 的查找表 */
    async _loadMaterialArchive() {
      try {
        const resp = await materialList({ page: 1, limit: 2000, material_property: '圆钢' })
        const matArr = (resp.data && resp.data.list) || []
        const lookup = {}
        matArr.forEach((m) => {
          const name = (m.material_name || '').trim()
          const spec = (m.material_spec || '').trim()
          const model = (m.material_model || '').trim()
          if (name && spec && model) {
            const key = name + '|' + spec
            if (!lookup[key]) lookup[key] = model
          }
        })
        this.matLookup = lookup
        // 如果表格数据已加载，重新填充型号
        if (this.tableData.length > 0) {
          this._enrichTableData()
        }
      } catch (e) {
        console.warn('加载物料档案失败', e)
      }
    },
    /** 用物料档案反查填充 y_material_model */
    _enrichTableData() {
      const lookup = this.matLookup || {}
      this.tableData = this.tableData.map((row) => {
        if (!row.y_material_model || !(row.y_material_model || '').trim()) {
          const name = (row.y_material_name || row.material_name || '').trim()
          const spec = (row.y_material_spec || '').trim()
          if (name && spec) {
            const found = lookup[name + '|' + spec]
            if (found) {
              return { ...row, y_material_model: found }
            }
          }
        }
        return row
      })
    },
    handleDateChange(val) {
      if (val && val.length === 2) {
        this.query.inventory_start_date = val[0]
        this.query.inventory_end_date = val[1]
      } else {
        this.query.inventory_start_date = ''
        this.query.inventory_end_date = ''
      }
    },
    handleSearch() {
      this.query.page = 1
      this.getList()
    },
    handleReset() {
      this.query = {
        page: 1,
        limit: 10,
        y_material_name: '',
        y_material_manufacturer: '',
        process_name: '',
        inventory_start_date: '',
        inventory_end_date: '',
        y_material_spec: '',
        y_material_model: '',
        c_material_spec: ''
      }
      this.dateRange = []
      this.handleSearch()
    },
    async getList() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = (resp.data.list || []).map((row) => ({
          ...row,
          y_material_name: row.y_material_name || row.material_name || '',
          y_material_manufacturer: row.y_material_manufacturer || row.manufacturer || ''
        }))
        this.total = resp.data.count
        // 反查填充缺失的圆钢型号
        this._enrichTableData()
      } catch (error) {
        console.error('获取盘点列表失败', error)
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.app-container {
  padding: 20px;
}
</style>
