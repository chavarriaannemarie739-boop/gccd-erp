<template>
  <div class="salary-details" v-loading="loading">
    <el-form ref="handleRef" label-width="100px" :model="userForm">
      <el-form-item label="员工">
        <el-input :model-value="employeeName" disabled placeholder="—" />
      </el-form-item>
      <el-form-item label="所属年月">
        <el-input :model-value="periodLabel" disabled placeholder="—" />
      </el-form-item>
      <el-form-item label="当月工资">
        <el-input :model-value="fmtMoney(userForm.wage)" disabled placeholder="—" />
      </el-form-item>
      <el-form-item label="创建时间">
        <el-input v-model="userForm.create_time" disabled placeholder="—" />
      </el-form-item>
      <el-form-item label="更新时间">
        <el-input v-model="userForm.update_time" disabled placeholder="—" />
      </el-form-item>
    </el-form>

    <!-- 考勤汇总（仅主任/主管有签到数据时显示） -->
    <div v-if="attendance && attendance.has_attendance" class="salary-details__block">
      <div class="salary-details__block-title">考勤汇总（签到）</div>
      <div class="attendance-summary">
        <div class="attendance-item">
          <span class="attendance-num">{{ attendance.attendance_days }}</span>
          <span class="attendance-label">出勤天数</span>
        </div>
        <div class="attendance-item">
          <span class="attendance-num">{{ attendance.total_hours }}</span>
          <span class="attendance-label">总工时</span>
        </div>
        <div class="attendance-item">
          <span class="attendance-num">{{ attendance.signed_out_days }}</span>
          <span class="attendance-label">已签退天数</span>
        </div>
      </div>
    </div>

    <!-- 签到明细（每日记录） -->
    <div v-if="attendance && attendance.records && attendance.records.length" class="salary-details__block">
      <div class="salary-details__block-title">签到明细</div>
      <el-table :data="attendance.records" border stripe size="small" max-height="200">
        <el-table-column type="index" label="#" width="48" align="center" />
        <el-table-column prop="attendance_date" label="日期" width="110" />
        <el-table-column prop="check_in_time" label="签到时间" width="155" />
        <el-table-column prop="check_out_time" label="签退时间" width="155" />
        <el-table-column prop="work_hours" label="工时" width="70" align="center" />
      </el-table>
    </div>

    <div v-if="detailRows.length" class="salary-details__block">
      <div class="salary-details__block-title">月内报工明细</div>
      <el-table :data="detailRows" border stripe size="small" max-height="280">
        <el-table-column type="index" label="#" width="48" align="center" />
        <el-table-column prop="date" label="日期" width="110" />
        <el-table-column
          prop="relation_code"
          label="关联单号"
          min-width="130"
          show-overflow-tooltip
        />
        <el-table-column prop="report_num" label="报工数量" width="100" align="right" />
        <el-table-column prop="wage" label="工资" width="100" align="right" />
        <el-table-column prop="username" label="姓名" width="90" show-overflow-tooltip />
      </el-table>
    </div>

    <div class="btn">
      <el-button @click="hanleAddDialogClose">取消</el-button>
    </div>
  </div>
</template>

<script>
import { info } from '@/api/mes/SalaryList/SalaryList'
export default {
  name: 'SalaryDetails',
  props: {
    view: {
      type: Object,
      default: () => {}
    },
    isShow: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      loading: false,
      userForm: {},
      detailRows: [],
      attendance: null
    }
  },
  computed: {
    employeeName() {
      return this.view?.name || this.userForm?.username || '—'
    },
    periodLabel() {
      const y = this.userForm?.year ?? this.view?.year
      const m = this.userForm?.month ?? this.view?.month
      if (y == null && m == null) return '—'
      const mm = m != null ? String(m).padStart(2, '0') : ''
      return `${y ?? ''}-${mm}`
    }
  },
  mounted() {
    this.loadInfo()
  },
  methods: {
    fmtMoney(v) {
      if (v === null || v === undefined || v === '') return '—'
      const n = Number(v)
      if (!Number.isFinite(n)) return String(v)
      return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    async loadInfo() {
      if (!this.view?.id) return
      this.loading = true
      try {
        const resp = await info({ id: this.view.id })
        const d = resp.data || {}
        this.userForm = { ...d }
        this.detailRows = Array.isArray(d.details) ? d.details : []
        this.attendance = d.attendance || null
      } catch {
        this.userForm = {}
        this.detailRows = []
        this.attendance = null
      } finally {
        this.loading = false
      }
    },
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    }
  }
}
</script>

<style scoped>
.salary-details {
  padding: 0 4px;
}

.salary-details__block {
  margin-top: 12px;
}

.salary-details__block-title {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--el-text-color-secondary);
}

.btn {
  margin-top: 16px;
  text-align: right;
}

/* 考勤汇总样式 */
.attendance-summary {
  display: flex;
  gap: 16px;
  padding: 16px;
  background: #f0f9ff;
  border-radius: 8px;
  border: 1px solid #e0f2fe;
}

.attendance-item {
  flex: 1;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.attendance-num {
  font-size: 28px;
  font-weight: 700;
  color: #0d9488;
}

.attendance-label {
  font-size: 12px;
  color: #6b7280;
}
</style>
