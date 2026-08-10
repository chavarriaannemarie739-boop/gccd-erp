<template>
  <!-- [AI-2026-08] 生产计划编辑圆钢统一名称、厂商、材质、型号 -->
  <div class="app-container">
    <!-- 表单 -->
    <el-form ref="handleRef" label-width="180px" :model="userForm" :rules="handleRules">
      <el-form-item label="生产计划名称：" prop="production_plan_name">
        <el-input
          v-model="userForm.production_plan_name"
          placeholder="请输入"
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item label="工艺信息：" prop="routing_code">
        <el-select
          filterable
          v-model="userForm.routing_code"
          style="width: 300px"
          placeholder="请选择"
        >
          <el-option
            v-for="item in options"
            :key="item.id"
            :label="item.routing_name"
            :value="item.routing_code"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="成品物料：" prop="english_material">
        <el-input
          @click="handleClickFinishedProduct"
          v-model="english_material"
          placeholder="请选择成品物料"
          readonly
          style="width: 300px"
        />
      </el-form-item>
      <el-form-item v-if="showPunchDevices" label="穿孔设备：" prop="punchDeviceCodes">
        <el-select
          v-model="userForm.punchDeviceCodes"
          multiple
          :multiple-limit="2"
          filterable
          style="width: 420px"
          placeholder="请选择1~2台穿孔设备"
        >
          <el-option
            v-for="item in punchDeviceOptions"
            :key="item.device_code"
            :label="item.device_name || item.device_code"
            :value="item.device_code"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="物料内容：" prop="content">
        <el-button round type="primary" @click="openRoundSteelPicker" style="margin-left: 20px"
          >选择圆钢</el-button
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
          <el-table-column prop="material_name" label="圆钢名称" />
          <el-table-column prop="manufacturer" label="厂商" />
          <el-table-column prop="material_spec" label="材质" />
          <el-table-column prop="material_model" label="型号" />
          <el-table-column prop="plan_num" label="计划数量">
            <template #default="{ row }">
              <el-input v-model="row.plan_num" placeholder="请输入" style="width: 110px" />
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
          <el-form-item label="圆钢名称：">
            <el-input v-model="query.material_name" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="厂商：">
            <el-input v-model="query.manufacturer" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="材质：">
            <el-input
              v-model="query.material_spec"
              style="width: 260px"
              placeholder="请输入"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="型号：">
            <el-input v-model="query.material_model" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item>
            <el-button v-preventReClick type="primary" @click="list">搜索</el-button>
            <el-button v-preventReClick @click="handleAdf">重置</el-button>
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
      >
        <el-table-column label="" width="52" align="center">
          <template #default="{ row }">
            <el-radio v-model="roundSteelPickId" :label="row.id" @change="onRoundSteelPick(row)">
              &nbsp;
            </el-radio>
          </template>
        </el-table-column>
        <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
        <el-table-column prop="material_name" label="圆钢名称" show-overflow-tooltip />
        <el-table-column prop="manufacturer" label="厂商" show-overflow-tooltip />
        <el-table-column prop="material_spec" label="材质" show-overflow-tooltip />
        <el-table-column prop="material_model" label="型号" show-overflow-tooltip />
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
    <!-- 物料-->
    <el-dialog
      v-model="dialogTableVisibleGoods"
      v-if="dialogTableVisibleGoods"
      width="70%"
      :close-on-click-modal="false"
    >
      <el-row :gutter="24">
        <el-col :span="6">
          <el-form-item label="物料名称：">
            <el-input v-model="query.material_name" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="物料规格：">
            <el-input
              v-model="query.material_spec"
              style="width: 260px"
              placeholder="请输入"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="型号：">
            <el-input v-model="query.material_model" style="width: 260px" placeholder="请输入" />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item>
            <el-button v-preventReClick type="primary" @click="list">搜索</el-button>
            <el-button v-preventReClick @click="handleAdf">重置</el-button>
          </el-form-item>
        </el-col>
      </el-row>
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
        @row-click="handleClickAddGoods"
      >
        <el-table-column type="index" :index="handleIndex" label="序号" width="70" />
        <el-table-column prop="material_name" label="物料名称" show-overflow-tooltip />
        <el-table-column prop="material_spec" label="物料规格" show-overflow-tooltip />
        <el-table-column prop="material_model" label="型号" show-overflow-tooltip />
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
    </el-dialog>
    <div class="btn">
      <el-button :disabled="submitting" @click="hanleAddDialogClose">取消</el-button>
      <el-button type="primary" :loading="submitting" :disabled="submitting" @click="handleAppEdit">
        {{ submitting ? '保存中…' : '保存' }}
      </el-button>
    </div>
  </div>
</template>

<script>
import { edit, info } from '@/api/mes/ProductionOrderList/ProductionOrderList'
import { list } from '@/api/mes/MaterialManagement/MaterialManagement'
import { listGoods, getProcess } from '@/api/mes/ProcessRoute/ProcessRoute'
import { listByProcess } from '@/api/mes/EquipmentLedger/EquipmentLedger'
export default {
  name: 'EditingAndProduction',
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
      processVisible: false,
      dialogTableVisibleGoods: false,
      loading: true,
      submitting: false,
      processes: [],
      total: 0,
      roundSteelPickId: null,
      roundSteelSelectedRow: null,
      english_material: '',
      userForm: {
        AdminToken: '',
        id: '',
        production_plan_code: '',
        production_plan_name: '',
        english_material: '',
        routing_code: '',
        punchDeviceCodes: [],
        content: []
      },
      showPunchDevices: false,
      punchDeviceOptions: [],
      query: {
        AdminToken: '',
        page: 1,
        limit: 10,
        stock: '',
        material_property: '',
        material_name: '',
        manufacturer: '',
        material_spec: '',
        material_model: ''
      },
      material_property: '',
      queryGoods: {
        AdminToken: '',
        page: 1,
        limit: 10000000
      },
      handleRules: {
        production_plan_name: [
          { required: true, message: '生产计划名称不能为空', trigger: 'blur' }
        ],
        routing_code: [{ required: true, message: '工艺信息不能为空', trigger: 'blur' }],
        english_material: [{ required: true, message: '成品物料不能为空', trigger: 'change' }],
        punchDeviceCodes: [
          {
            validator: (_rule, value, callback) => {
              if (!this.showPunchDevices) {
                callback()
                return
              }
              if (!value || value.length < 1) {
                callback(new Error('请选择1~2台穿孔设备'))
              } else {
                callback()
              }
            },
            trigger: 'change'
          }
        ],
        content: [
          {
            validator: (_rule, value, callback) => {
              if (!value || value.length === 0) {
                callback(new Error('请选择圆钢物料'))
              } else {
                callback()
              }
            },
            trigger: 'change'
          }
        ]
      }
    }
  },
  watch: {
    'userForm.routing_code'(val) {
      this.syncPunchDeviceField(val)
    }
  },
  mounted() {
    this.listGoods()
    this.info()
  },
  methods: {
    async syncPunchDeviceField(routingCode) {
      if (!routingCode) {
        this.showPunchDevices = false
        return
      }
      try {
        const resp = await getProcess(routingCode)
        const content = resp.data?.content || []
        const hasPunch = content.some((p) => (p.processes_name || '') === '穿孔')
        this.showPunchDevices = hasPunch
        if (!hasPunch) {
          this.userForm.punchDeviceCodes = []
          return
        }
        const devResp = await listByProcess({ processes_name: '穿孔', page: 0, limit: 0 })
        this.punchDeviceOptions = devResp.data?.list || []
      } catch (e) {
        this.showPunchDevices = false
      }
    },
    async info() {
      const resp = await info({ id: this.view.id })
      this.userForm = resp.data
      this.userForm.punchDeviceCodes = (resp.data.punch_devices || []).map((d) => d.device_code)
      this.english_material = [
        this.userForm.material_name,
        this.userForm.material_spec,
        this.userForm.material_model
      ]
        .filter(Boolean)
        .join('，')
      if (this.userForm.routing_code) {
        await this.syncPunchDeviceField(this.userForm.routing_code)
      }
    },
    handleClickFinishedProduct() {
      this.query.stock = ''
      this.query.material_property = '成品'
      this.query.manufacturer = ''
      this.dialogTableVisibleGoods = true
      this.list()
    },
    handleClickAddGoods(row) {
      this.userForm.english_material = row.id
      this.english_material =
        row.material_name + '，' + row.material_spec + '，' + row.material_model
      this.dialogTableVisibleGoods = false
      this.query.material_spec = ''
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_model = ''
      this.$nextTick(() => {
        this.$refs.handleRef?.validateField('english_material')
      })
    },
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.options = resp.data.list
    },
    openRoundSteelPicker() {
      const existing = this.userForm.content[0]
      this.roundSteelPickId = existing ? existing.id : null
      this.roundSteelSelectedRow = existing
        ? {
            id: existing.id,
            material_name: existing.material_name,
            manufacturer: existing.manufacturer,
            material_spec: existing.material_spec,
            material_model: existing.material_model
          }
        : null
      this.processVisible = true
      this.query.stock = ''
      this.query.material_property = '圆钢'
      this.list()
    },
    onRoundSteelPick(row) {
      this.roundSteelSelectedRow = row
    },
    handleAdf() {
      this.query.material_spec = ''
      this.query.material_name = ''
      this.query.manufacturer = ''
      this.query.material_model = ''
      this.list()
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
      if (!this.roundSteelSelectedRow) {
        ElMessage.warning('请选择一条圆钢')
        return
      }
      const item = this.roundSteelSelectedRow
      const prev = this.userForm.content[0]
      const sameId = prev && String(prev.id) === String(item.id)
      this.userForm.content = [
        {
          material_name: item.material_name,
          manufacturer: item.manufacturer,
          material_spec: item.material_spec,
          material_model: item.material_model,
          id: item.id,
          plan_num: sameId ? prev.plan_num : '',
          remark: sameId ? prev.remark : ''
        }
      ]
      this.processVisible = false
      this.$nextTick(() => {
        this.$refs.handleRef?.validateField('content')
      })
    },
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
    // 关闭（取消按钮已通过 :disabled="submitting" 防重复点击）
    hanleAddDialogClose() {
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      if (this.submitting) {
        return
      }
      this.submitting = true
      this.$refs.handleRef.validate(async (value) => {
        if (!value) {
          this.submitting = false
          return
        }
        try {
          const params = {
            id: this.userForm.id,
            production_plan_code: this.userForm.production_plan_code,
            production_plan_name: this.userForm.production_plan_name,
            routing_code: this.userForm.routing_code,
            english_material: this.userForm.english_material,
            materials: []
          }
          if (this.showPunchDevices) {
            params.punch_devices = (this.userForm.punchDeviceCodes || []).map((code) => {
              const dev = this.punchDeviceOptions.find((d) => d.device_code === code)
              return { device_code: code, device_name: dev ? dev.device_name : '' }
            })
          }
          this.userForm.content.forEach((item) => {
            params.materials.push({
              material_id: item.material_id || item.id,
              material_name: item.material_name,
              manufacturer: item.manufacturer,
              material_spec: item.material_spec,
              material_model: item.material_model,
              remark: item.remark,
              plan_num: item.plan_num
            })
          })
          await edit(params)
          this.$emit('list')
          ElMessage({
            message: '操作成功',
            type: 'success'
          })
          this.hanleAddDialogClose()
        } catch (err) {
          ElMessage.error(err?.msg || err?.message || '保存失败')
          this.submitting = false
        }
      })
    },
    delOrderMatter(index, row) {
      this.userForm.content.splice(index, 1)
      this.$nextTick(() => {
        this.$refs.handleRef?.validateField('content')
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
