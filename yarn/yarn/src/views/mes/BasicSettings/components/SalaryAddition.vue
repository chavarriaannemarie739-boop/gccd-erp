<template>
  <div class="salary-addition">
    <el-alert type="info" :closable="false" show-icon class="salary-addition__alert">
      <template #title>
        <span class="salary-addition__alert-title">计件单价与「物料档案」不是一回事</span>
      </template>
      <p class="salary-addition__alert-p">
        报工算工资时，系统只认 <code>ya_wage.material_spec</code> 这一串<strong>计价键</strong>（断钢：<code>断钢</code>+圆钢<strong>型号</strong>段；穿孔：<code>设备名 + 空格 + 圆钢型号段</code>，与任务原料 <code>y_material_model</code> 一致）。
        不必、也不建议在物料表里造「穿孔40机 70钢」这种假档案；下面<strong>规则录入</strong>会直接生成与报工一致的键。
      </p>
    </el-alert>

    <el-tabs v-model="activeTab" class="salary-addition__tabs">
      <el-tab-pane label="规则录入（推荐）" name="rule">
        <div class="salary-rule">
          <div class="salary-rule__block">
            <div class="salary-rule__title">断钢</div>
            <p class="salary-rule__desc">
              与生产任务上的<strong>圆钢型号</strong>一致（取 <code>*</code> 前那一段，如 <code>50</code>、<code>65</code>、<code>70</code> 或 <code>东海50</code> 等）。将保存为计价键 <code>断钢{{ duangangSpec || '…' }}</code>；报工算薪按任务原料档案的<strong>圆钢型号</strong>（<code>y_material_model</code>）匹配，型号为空时才回退用规格。
            </p>
            <div class="salary-rule__row">
              <el-input
                v-model="duangangSpec"
                placeholder="型号段，如 50 或 东海50"
                clearable
                class="salary-rule__input"
              />
              <el-input
                v-model="duangangWage"
                placeholder="单价(元/吨)"
                clearable
                class="salary-rule__input wage"
              />
              <el-button type="primary" @click="addDuangangRule">加入列表</el-button>
            </div>
          </div>

          <div class="salary-rule__block">
            <div class="salary-rule__title">穿孔</div>
            <p class="salary-rule__desc">
              先选<strong>设备</strong>（如穿孔40机/50机/90机），再填<strong>圆钢型号段</strong>（须与任务上原料圆钢<strong>型号</strong>在取 <code>*</code> 前一致，如 <code>50</code>、<code>65</code>、<code>70钢</code>）。计价键为：<code>设备名 + 空格 + 型号段</code>；报工算薪优先按 <code>y_material_model</code>，型号为空时才回退用规格/成品规格。
            </p>
            <div class="salary-rule__row salary-rule__row--wrap">
              <el-select
                v-model="chuanDeviceId"
                placeholder="选择穿孔设备"
                filterable
                clearable
                class="salary-rule__select"
                @visible-change="onChuanSelectVisible"
              >
                <el-option
                  v-for="d in chuanDevices"
                  :key="d.id"
                  :label="`${d.device_name}（${d.device_code}）`"
                  :value="d.id"
                />
              </el-select>
              <el-input
                v-model="chuanSpec"
                placeholder="圆钢型号段，如 50、65、70钢"
                clearable
                class="salary-rule__input"
              />
              <el-input
                v-model="chuanWage"
                placeholder="单价(元/吨)"
                clearable
                class="salary-rule__input wage"
              />
              <el-button type="primary" @click="addChuanRule">加入列表</el-button>
            </div>
            <p v-if="chuanLoadError" class="salary-rule__err">{{ chuanLoadError }}</p>
          </div>
        </div>
      </el-tab-pane>

      <el-tab-pane label="从物料档案选（兼容旧数据）" name="material">
        <p class="salary-tab-note">
          若历史上已在物料里建过与计价键同名的规格，可在此勾选；仍会排除「配件」，仅圆钢/成品。
        </p>
        <div class="salary-addition__toolbar">
          <el-button type="primary" @click="openPickDialog">选择物料</el-button>
        </div>
      </el-tab-pane>
    </el-tabs>

    <el-form ref="handleRef" label-width="88px" :model="userForm" class="salary-addition__form">
      <el-form-item label="待保存">
        <el-table
          :data="userForm.content"
          border
          class="salary-addition__table"
          max-height="320"
          :header-cell-style="{ textAlign: 'center' }"
          :cell-style="{ textAlign: 'center' }"
        >
          <el-table-column prop="rowType" label="来源" width="100" />
          <el-table-column prop="material_spec" label="计价键（material_spec）" min-width="200" show-overflow-tooltip />
          <el-table-column prop="material_name" label="名称/说明" min-width="120" show-overflow-tooltip />
          <el-table-column prop="material_property" label="属性" width="88" show-overflow-tooltip />
          <el-table-column label="单价(元/吨)" width="140">
            <template #default="{ row }">
              <el-input v-model="row.wage" placeholder="必填" clearable class="salary-addition__wage-input" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="88" fixed="right">
            <template #default="scope">
              <el-button type="danger" link @click="delOrderMatter(scope.$index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-form-item>
    </el-form>

    <el-dialog
      v-model="processVisible"
      title="选择物料（圆钢 / 成品）"
      class="salary-pick-dialog"
      width="920px"
      top="5vh"
      append-to-body
      destroy-on-close
      :close-on-click-modal="false"
      @closed="onPickClosed"
    >
      <div class="salary-pick-toolbar">
        <el-input
          v-model="pickQuery.material_name"
          placeholder="名称"
          clearable
          class="salary-pick-field"
          @keyup.enter="handlePickSearch"
        />
        <el-input
          v-model="pickQuery.material_spec"
          placeholder="规格"
          clearable
          class="salary-pick-field"
          @keyup.enter="handlePickSearch"
        />
        <el-input
          v-model="pickQuery.material_model"
          placeholder="型号"
          clearable
          class="salary-pick-field"
          @keyup.enter="handlePickSearch"
        />
        <el-button type="primary" @click="handlePickSearch">搜索</el-button>
        <el-button @click="handlePickReset">重置</el-button>
      </div>
      <el-table
        :data="processes"
        v-loading="loading"
        border
        stripe
        height="420"
        :row-key="getRowKeys"
        :header-cell-style="{ textAlign: 'center' }"
        :cell-style="{ textAlign: 'center' }"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="48" :reserve-selection="true" />
        <el-table-column type="index" :index="handleIndex" label="序号" width="64" />
        <el-table-column prop="material_property" label="属性" width="88" show-overflow-tooltip />
        <el-table-column prop="material_name" label="名称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="material_spec" label="规格" min-width="120" show-overflow-tooltip />
        <el-table-column prop="material_model" label="型号" min-width="100" show-overflow-tooltip />
        <el-table-column prop="create_time" label="创建时间" width="168" show-overflow-tooltip />
      </el-table>
      <el-pagination
        class="salary-pick-pagination"
        :page-sizes="[10, 20, 50, 100]"
        :current-page="pickQuery.page"
        :page-size="pickQuery.limit"
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        background
        @size-change="handleSizeChangeGoods"
        @current-change="handleCurrentChangeGoods"
      />
      <template #footer>
        <el-button @click="processVisible = false">取消</el-button>
        <el-button type="primary" @click="processSubmit">加入已选</el-button>
      </template>
    </el-dialog>

    <div class="salary-addition__footer">
      <el-button @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" @click="handleAppEdit">保存</el-button>
    </div>
  </div>
</template>

<script>
import { add } from '@/api/mes/SalaryManagement/SalaryManagement'
import { list } from '@/api/mes/MaterialManagement/MaterialManagement'
import { list as processesList } from '@/api/mes/ProcessManagement/ProcessManagement'
import { list as deviceList } from '@/api/mes/EquipmentLedger/EquipmentLedger'

const MATERIAL_SCOPE_STEEL_PRODUCT = 'steel_and_product'

export default {
  name: 'SalaryAddition',
  props: {
    isShow: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      activeTab: 'rule',
      processVisible: false,
      loading: false,
      processes: [],
      total: 0,
      selection: [],
      userForm: {
        content: []
      },
      pickQuery: {
        page: 1,
        limit: 20,
        material_name: '',
        material_spec: '',
        material_model: '',
        material_scope: MATERIAL_SCOPE_STEEL_PRODUCT
      },
      duangangSpec: '',
      duangangWage: '',
      chuanProcessCode: '',
      chuanDevices: [],
      chuanDeviceId: null,
      chuanSpec: '',
      chuanWage: '',
      chuanLoadError: '',
      chuanDevicesLoaded: false
    }
  },
  mounted() {
    this.loadChuanDevices()
  },
  methods: {
    async loadChuanDevices() {
      this.chuanLoadError = ''
      try {
        const pr = await processesList({ processes_name: '穿孔', page: 1, limit: 30 })
        const listP = pr.data?.list || []
        const first = listP[0]
        if (!first?.processes_code) {
          this.chuanLoadError = '未找到工序名为「穿孔」的记录，请先在工序档案中维护。'
          this.chuanDevices = []
          return
        }
        this.chuanProcessCode = first.processes_code
        const dr = await deviceList({
          processes_code: first.processes_code,
          page: 1,
          limit: 500
        })
        this.chuanDevices = dr.data?.list || []
        this.chuanDevicesLoaded = true
      } catch (e) {
        this.chuanLoadError = '加载穿孔设备失败，请检查网络或权限。'
        this.chuanDevices = []
      }
    },
    onChuanSelectVisible(visible) {
      if (visible && !this.chuanDevicesLoaded) {
        this.loadChuanDevices()
      }
    },
    addDuangangRule() {
      const seg = (this.duangangSpec || '').trim()
      if (!seg) {
        ElMessage.warning('请填写断钢型号段')
        return
      }
      const key = `断钢${seg}`
      if (this.userForm.content.some((r) => r.material_spec === key)) {
        ElMessage.error('列表中已有相同计价键')
        return
      }
      const w = this.duangangWage
      this.userForm.content.push({
        rowType: '断钢规则',
        source: 'rule_duangang',
        material_spec: key,
        material_id: null,
        material_property: '—',
        material_name: `规格段：${seg}`,
        material_model: '—',
        wage: w === '' || w === null ? '' : String(w)
      })
      this.duangangSpec = ''
      this.duangangWage = ''
      ElMessage.success('已加入列表，请核对单价后保存')
    },
    addChuanRule() {
      const dev = this.chuanDevices.find((d) => d.id === this.chuanDeviceId)
      if (!dev?.device_name) {
        ElMessage.warning('请选择穿孔设备')
        return
      }
      const seg = (this.chuanSpec || '').trim()
      if (!seg) {
        ElMessage.warning('请填写圆钢型号段（如 50、70钢）')
        return
      }
      const key = `${dev.device_name} ${seg}`.trim()
      if (this.userForm.content.some((r) => r.material_spec === key)) {
        ElMessage.error('列表中已有相同计价键')
        return
      }
      const w = this.chuanWage
      this.userForm.content.push({
        rowType: '穿孔规则',
        source: 'rule_chuan',
        material_spec: key,
        material_id: null,
        material_property: '—',
        material_name: `${dev.device_name} / ${seg}`,
        material_model: dev.device_code || '—',
        wage: w === '' || w === null ? '' : String(w)
      })
      this.chuanSpec = ''
      this.chuanWage = ''
      this.chuanDeviceId = null
      ElMessage.success('已加入列表，请核对单价后保存')
    },
    openPickDialog() {
      this.activeTab = 'material'
      this.processVisible = true
      this.pickQuery.page = 1
      this.list()
    },
    handlePickSearch() {
      this.pickQuery.page = 1
      this.list()
    },
    handlePickReset() {
      this.pickQuery.material_name = ''
      this.pickQuery.material_spec = ''
      this.pickQuery.material_model = ''
      this.pickQuery.page = 1
      this.list()
    },
    onPickClosed() {
      this.selection = []
    },
    handleSelectionChange(selection) {
      this.selection = selection
    },
    handleSizeChangeGoods(val) {
      this.pickQuery.limit = val
      this.pickQuery.page = 1
      this.list()
    },
    handleCurrentChangeGoods(val) {
      this.pickQuery.page = val
      this.list()
    },
    getRowKeys(row) {
      return row.id
    },
    processSubmit() {
      const sel = this.selection || []
      if (!sel.length) {
        ElMessage.warning('请先勾选物料')
        return
      }
      for (const item of sel) {
        if (this.userForm.content.some((row) => row.material_spec === item.material_spec)) {
          ElMessage.error('所选中包含与列表重复的计价键（规格），请取消勾选')
          return
        }
      }
      sel.forEach((item) => {
        this.userForm.content.push({
          rowType: '物料档案',
          source: 'material',
          material_spec: item.material_spec,
          material_id: item.id,
          material_property: item.material_property,
          material_name: item.material_name,
          material_model: item.material_model,
          wage: ''
        })
      })
      this.processVisible = false
      ElMessage.success(`已添加 ${sel.length} 条，请填写或核对单价`)
    },
    async list() {
      this.loading = true
      try {
        const resp = await list(this.pickQuery)
        this.processes = resp.data.list
        this.total = resp.data.count
      } finally {
        this.loading = false
      }
    },
    handleIndex(index) {
      return index + 1 + this.pickQuery.limit * (this.pickQuery.page - 1)
    },
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    async handleAppEdit() {
      const rows = this.userForm.content
      if (!rows.length) {
        ElMessage.warning('请添加至少一条计价配置')
        return
      }
      for (const item of rows) {
        const w = item.wage
        if (w === '' || w === null || Number.isNaN(Number(w))) {
          ElMessage.warning('请为每条填写有效单价')
          return
        }
      }
      try {
        for (const item of rows) {
          const payload = {
            material_spec: item.material_spec,
            wage: item.wage
          }
          if (item.source === 'material' && item.material_id) {
            payload.material_id = item.material_id
          }
          await add(payload)
        }
        this.$emit('list')
        this.hanleAddDialogClose()
        ElMessage.success('操作成功')
      } catch (err) {
        //
      }
    },
    delOrderMatter(index) {
      this.userForm.content.splice(index, 1)
    }
  }
}
</script>

<style scoped>
.salary-addition {
  padding: 0 4px 8px;
}
.salary-addition__alert {
  margin-bottom: 14px;
}
.salary-addition__alert-title {
  font-weight: 600;
}
.salary-addition__alert-p {
  margin: 8px 0 0;
  font-size: 13px;
  line-height: 1.6;
  color: var(--el-text-color-regular);
}
.salary-addition__alert-p code {
  font-size: 12px;
  padding: 0 4px;
}
.salary-addition__tabs {
  margin-bottom: 12px;
}
.salary-tab-note {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
.salary-rule__block {
  margin-bottom: 20px;
  padding: 14px 16px;
  background: var(--el-fill-color-blank);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
}
.salary-rule__title {
  font-weight: 600;
  margin-bottom: 8px;
  font-size: 15px;
}
.salary-rule__desc {
  margin: 0 0 12px;
  font-size: 13px;
  line-height: 1.55;
  color: var(--el-text-color-secondary);
}
.salary-rule__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.salary-rule__row--wrap {
  align-items: flex-start;
}
.salary-rule__input {
  width: 220px;
  max-width: 100%;
}
.salary-rule__input.wage {
  width: 140px;
}
.salary-rule__select {
  width: 280px;
  max-width: 100%;
}
.salary-rule__err {
  margin: 8px 0 0;
  font-size: 12px;
  color: var(--el-color-danger);
}
.salary-addition__toolbar {
  margin-bottom: 10px;
}
.salary-addition__form {
  margin-top: 4px;
}
.salary-addition__table {
  width: 100%;
}
.salary-addition__wage-input {
  width: 112px;
}
.salary-addition__footer {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--el-border-color-lighter);
  text-align: right;
}
.salary-pick-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.salary-pick-field {
  width: 200px;
  max-width: 100%;
}
.salary-pick-pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>

<style>
.salary-pick-dialog .el-dialog__body {
  padding-top: 8px;
}
</style>
