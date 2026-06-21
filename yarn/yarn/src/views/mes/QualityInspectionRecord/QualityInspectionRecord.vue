<template>
  <div class="app-container">
    <el-card class="filter-card-compact" shadow="never">
      <el-form :model="query" class="filter-form-flex" @submit.prevent>
        <el-form-item class="filter-form-item--field">
          <el-input
            v-model="query.process_name"
            placeholder="工序名称"
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

    <el-card shadow="never" class="main-list-card" style="margin-top: 10px">
      <!-- 主表列表 -->
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        stripe
        style="width: 100%"
        header-row-class-name="custom-header"
      >
        <el-table-column type="index" label="序号" width="60" align="center" />
        <el-table-column prop="check_date" label="质检日期" width="130" align="center">
          <template #default="scope">
            <span class="date-text">{{ scope.row.check_date }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="process_name" label="工序" width="120" align="center">
          <template #default="scope">
            <el-tag effect="light" round>{{ scope.row.process_name }}</el-tag>
          </template>
        </el-table-column>
        
        <el-table-column label="提报内容摘要" min-width="250">
          <template #default="scope">
            <div class="summary-cell">
              <span class="summary-text">
                包含 <b>{{ scope.row.items ? scope.row.items.length : 0 }}</b> 种物料明细
                <span v-if="scope.row.total_defects_count > 0" class="defect-alert">
                  （发现 {{ scope.row.total_defects_count }} 支不良品）
                </span>
                <span v-else class="success-alert">（无不良品）</span>
              </span>
              <el-button 
                type="primary" 
                link 
                icon="View" 
                @click="showDetail(scope.row)"
                class="view-btn"
              >
                查看详情
              </el-button>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip>
          <template #default="scope">
            <span class="remark-text">{{ scope.row.remark || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="提交时间" width="180" align="center" />
      </el-table>

      <!-- 分页 -->
      <div class="pagination-container">
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

    <!-- 侧边详情抽屉 -->
    <el-drawer
      v-model="drawerVisible"
      title="质量检验提报详情"
      size="500px"
      direction="rtl"
      destroy-on-close
    >
      <div v-if="selectedRow" class="drawer-content">
        <!-- 抽屉头部概览 -->
        <div class="detail-header">
          <div class="header-item">
            <span class="label">质检日期：</span>
            <span class="value">{{ selectedRow.check_date }}</span>
          </div>
          <div class="header-item">
            <span class="label">工序名称：</span>
            <span class="value">{{ selectedRow.process_name }}</span>
          </div>
          <div class="header-item remark-item" v-if="selectedRow.remark">
            <span class="label">备注信息：</span>
            <div class="value remark-val">{{ selectedRow.remark }}</div>
          </div>
        </div>

        <!-- 汇总统计卡片 -->
        <div class="stats-card">
          <div class="stats-item">
            <div class="stats-val">{{ selectedRow.items.length }}</div>
            <div class="stats-label">物料种类</div>
          </div>
          <div class="stats-item red">
            <div class="stats-val">{{ selectedRow.total_defects_count }}</div>
            <div class="stats-label">不良品总计</div>
          </div>
        </div>

        <el-divider content-position="left">物料明细及分布</el-divider>

        <!-- 物料卡片列表 -->
        <div class="material-list">
          <div v-for="(item, index) in selectedRow.items" :key="index" class="material-card">
            <div class="card-title">
              <el-icon><Collection /></el-icon>
              <span>{{ item.y_material_name }}</span>
              <span class="spec-tag">{{ item.y_material_spec }}</span>
            </div>
            <div class="card-info">
              <span class="info-label">成品型号：</span>
              <span class="info-value">{{ item.c_material_model }}</span>
            </div>

            <!-- 不良项表格 -->
            <div class="defect-section">
              <div v-if="item.defects && item.defects.length > 0">
                <div class="defect-grid header">
                  <span>不良原因</span>
                  <span class="center">白班 (支)</span>
                  <span class="center">夜班 (支)</span>
                </div>
                <div v-for="(d, dIdx) in item.defects" :key="dIdx" class="defect-grid row">
                  <span class="defect-name">{{ d.defect_text }}</span>
                  <span class="center qty-box day">{{ d.day_qty || 0 }}</span>
                  <span class="center qty-box night">{{ d.night_qty || 0 }}</span>
                </div>
              </div>
              <div v-else class="empty-defect">
                <el-icon><CircleCheckFilled /></el-icon> 该物料未发现异常
              </div>
            </div>
          </div>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { list } from '@/api/mes/QualityInspectionRecord/QualityInspectionRecord'
import { Search, Refresh, View, Collection, CircleCheckFilled } from '@element-plus/icons-vue'

export default {
  name: 'QualityInspectionRecord',
  components: { Collection, CircleCheckFilled },
  data() {
    return {
      query: {
        page: 1,
        limit: 10,
        process_name: '',
        check_start_date: '',
        check_end_date: ''
      },
      dateRange: [],
      tableData: [],
      total: 0,
      loading: false,
      drawerVisible: false,
      selectedRow: null
    }
  },
  created() {
    this.getList()
  },
  methods: {
    handleDateChange(val) {
      if (val && val.length === 2) {
        this.query.check_start_date = val[0]
        this.query.check_end_date = val[1]
      } else {
        this.query.check_start_date = ''
        this.query.check_end_date = ''
      }
    },
    handleSearch() {
      this.query.page = 1
      this.getList()
    },
    handleReset() {
      this.query = { page: 1, limit: 10, process_name: '', check_start_date: '', check_end_date: '' }
      this.dateRange = []
      this.handleSearch()
    },
    showDetail(row) {
      this.selectedRow = row
      this.drawerVisible = true
    },
    async getList() {
      this.loading = true
      try {
        const resp = await list(this.query)
        this.tableData = resp.data.list
        this.total = resp.data.count
      } catch (error) {
        console.error('获取质检列表失败', error)
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
  background-color: #f5f7fa;
}

.custom-header {
  background-color: #f8f9fb !important;
  color: #333;
}

.date-text {
  font-weight: bold;
  color: #606266;
}

.remark-text {
  color: #909399;
  font-size: 13px;
}

.summary-cell {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.summary-text {
  font-size: 14px;
  color: #666;
}

.defect-alert {
  color: #f56c6c;
  font-weight: bold;
}

.success-alert {
  color: #67c23a;
}

.view-btn {
  font-weight: bold;
}

.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}

/* 抽屉样式 */
.drawer-content {
  padding: 0 20px 20px;
}

.detail-header {
  background: #f0f7ff;
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
}

.header-item {
  margin-bottom: 8px;
  font-size: 14px;
}

.header-item:last-child {
  margin-bottom: 0;
}

.header-item .label {
  color: #909399;
}

.header-item .value {
  color: #303133;
  font-weight: bold;
}

.remark-item {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed #dcdfe6;
}

.remark-val {
  margin-top: 5px;
  font-weight: normal !important;
  color: #606266 !important;
  line-height: 1.6;
  background: #fff;
  padding: 8px;
  border-radius: 4px;
}

.stats-card {
  display: flex;
  justify-content: space-around;
  background: #fff;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  padding: 15px 0;
  margin-bottom: 20px;
  box-shadow: 0 2px 12px 0 rgba(0,0,0,0.05);
}

.stats-item {
  text-align: center;
  flex: 1;
}

.stats-item.red .stats-val {
  color: #f56c6c;
}

.stats-val {
  font-size: 24px;
  font-weight: bold;
  color: #409eff;
}

.stats-label {
  font-size: 12px;
  color: #909399;
  margin-top: 5px;
}

.material-card {
  border: 1px solid #ebeef5;
  border-radius: 10px;
  padding: 15px;
  margin-bottom: 15px;
  background: #fff;
  transition: all 0.3s;
}

.material-card:hover {
  box-shadow: 0 4px 16px 0 rgba(0,0,0,0.1);
  transform: translateY(-2px);
}

.card-title {
  display: flex;
  align-items: center;
  font-weight: bold;
  font-size: 16px;
  margin-bottom: 10px;
  color: #303133;
}

.card-title .el-icon {
  margin-right: 8px;
  color: #409eff;
}

.spec-tag {
  background: #f2f6fc;
  color: #409eff;
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 4px;
  margin-left: 10px;
}

.card-info {
  font-size: 13px;
  margin-bottom: 15px;
}

.info-label {
  color: #909399;
}

.defect-section {
  background: #fafafa;
  border-radius: 6px;
  padding: 10px;
}

.defect-grid {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr;
  padding: 8px 0;
  font-size: 13px;
}

.defect-grid.header {
  border-bottom: 1px solid #eee;
  color: #909399;
  font-size: 12px;
}

.center {
  text-align: center;
}

.qty-box {
  font-weight: bold;
  width: 50px;
  justify-self: center;
  border-radius: 4px;
}

.qty-box.day {
  color: #e6a23c;
  background: #fdf6ec;
}

.qty-box.night {
  color: #6366f1;
  background: #eef2ff;
}

.empty-defect {
  text-align: center;
  color: #67c23a;
  padding: 10px;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.empty-defect .el-icon {
  margin-right: 5px;
}
</style>
