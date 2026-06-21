<template>
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
          @change="onPunchDevicesChange"
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
          <el-table-column prop="material_spec" label="圆钢规格" />
          <el-table-column prop="material_model" label="圆钢型号" />
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
          <el-form-item label="圆钢规格：">
            <el-input
              v-model="query.material_spec"
              style="width: 260px"
              placeholder="请输入"
              class="filter-item"
            />
          </el-form-item>
        </el-col>
        <el-col :span="6">
          <el-form-item label="圆钢型号：">
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
        <el-table-column prop="material_spec" label="圆钢规格" show-overflow-tooltip />
        <el-table-column prop="material_model" label="圆钢型号" show-overflow-tooltip />
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
import { add } from '@/api/mes/ProductionOrderList/ProductionOrderList'
import { list } from '@/api/mes/MaterialManagement/MaterialManagement'
import { listGoods, getProcess } from '@/api/mes/ProcessRoute/ProcessRoute'
import { listByProcess } from '@/api/mes/EquipmentLedger/EquipmentLedger'
export default {
  name: 'NewOutbound',
  props: {
    isShow: {
      type: Boolean,
      default: false
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
      /** 选择圆钢弹窗：单选 */
      roundSteelPickId: null,
      roundSteelSelectedRow: null,
      english_material: '',
      userForm: {
        AdminToken: '',
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
        english_material: [
          { required: true, message: '成品物料不能为空', trigger: 'change' }
        ],
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
  mounted() {
    this.listGoods()
  },
  watch: {
    'userForm.routing_code'(val) {
      this.syncPunchDeviceField(val)
    }
  },
  methods: {
    async syncPunchDeviceField(routingCode) {
      if (!routingCode) {
        this.showPunchDevices = false
        this.userForm.punchDeviceCodes = []
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
    onPunchDevicesChange() {
      this.$refs.handleRef?.validateField('punchDeviceCodes')
    },
    // 打开成品物料（仓库管理-物料档案-成品档案，与 ProductList 一致：material_property=成品）
    handleClickFinishedProduct() {
      this.query.stock = ''
      this.query.material_property = '成品'
      this.dialogTableVisibleGoods = true
      this.list()
    },
    // 物料
    handleClickAddGoods(row) {
      this.userForm.english_material = row.id
      this.english_material =
        row.material_name + '，' + row.material_spec + '，' + row.material_model
      this.dialogTableVisibleGoods = false
      this.query.material_spec = ''
      this.query.material_name = ''
      this.query.material_model = ''
      this.$nextTick(() => {
        this.$refs.handleRef?.validateField('english_material')
      })
    },
    /** 新增生产计划时「工艺信息」默认选「全工艺」（按 routing_name 匹配，兼容不同 routing_code） */
    applyDefaultRoutingCode() {
      const row = this.options.find((o) => o.routing_name === '全工艺')
      if (row && row.routing_code) {
        this.userForm.routing_code = row.routing_code
        this.syncPunchDeviceField(row.routing_code)
      }
    },
    async listGoods() {
      const resp = await listGoods(this.queryGoods)
      this.options = resp.data.list
      this.applyDefaultRoutingCode()
    },
    // 物料内容：仓库管理-物料档案-圆钢档案（与 RoundSteelList 一致：material_property=圆钢）；单选一条
    openRoundSteelPicker() {
      const existing = this.userForm.content[0]
      this.roundSteelPickId = existing ? existing.id : null
      this.roundSteelSelectedRow = existing
        ? {
            id: existing.id,
            material_name: existing.material_name,
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
    // 物料列表（成品选择：material_property=成品；圆钢选择：material_property=圆钢）
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
      if (this.submitting) {
        return
      }
      this.$emit('update:isShow', false)
    },
    handleAppEdit() {
      if (this.submitting) {
        return
      }
      this.$refs.handleRef.validate(async (value) => {
        // 判断
        if (!value || this.submitting) {
          return
        }
        this.submitting = true
        try {
          const params = {
            production_plan_name: this.userForm.production_plan_name,
            routing_code: this.userForm.routing_code,
            english_material: this.userForm.english_material,
            content: []
          }
          if (this.showPunchDevices) {
            params.punch_devices = (this.userForm.punchDeviceCodes || []).map((code) => {
              const dev = this.punchDeviceOptions.find((d) => d.device_code === code)
              return {
                device_code: code,
                device_name: dev ? dev.device_name : ''
              }
            })
          }
          this.userForm.content.forEach((item) => {
            params.content.push({
              material_id: item.id,
              material_name: item.material_name,
              material_spec: item.material_spec,
              material_model: item.material_model,
              remark: item.remark,
              plan_num: item.plan_num
            })
          })
          await add(params)
          this.$emit('list')
          this.hanleAddDialogClose()
          ElMessage({
            message: '操作成功',
            type: 'success'
          })
        } catch (err) {
          ElMessage.error(err?.msg || err?.message || '保存失败')
        } finally {
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
