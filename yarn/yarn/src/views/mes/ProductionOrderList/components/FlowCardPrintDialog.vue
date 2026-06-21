<template>
  <el-dialog
    v-model="visibleInner"
    title="打印流转卡"
    width="420px"
    :close-on-click-modal="false"
    destroy-on-close
    @closed="onClosed"
  >
    <div v-loading="loading">
      <p v-if="planName" class="hint">{{ planName }}</p>
      <el-form label-width="96px" label-position="left">
        <el-form-item v-if="options.need_pick_material" label="圆钢明细" required>
          <el-select v-model="materialId" placeholder="请选择" style="width: 100%">
            <el-option
              v-for="m in options.materials"
              :key="m.material_id"
              :label="`${m.y_material_spec}${m.y_material_model}（${m.plan_num}）`"
              :value="m.material_id"
            />
          </el-select>
        </el-form-item>
        <el-form-item v-if="options.need_pick_device" label="穿孔设备" required>
          <el-select v-model="deviceCode" placeholder="请选择" style="width: 100%">
            <el-option
              v-for="d in options.devices"
              :key="d.device_code"
              :label="d.device_name || d.device_code"
              :value="d.device_code"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <p class="hint hint--sub">打印预览可缩放比例/边距；标签纸宽度在浏览器 @page 与打印机驱动中设置。</p>
    </div>
    <template #footer>
      <el-button @click="visibleInner = false">取消</el-button>
      <el-button type="primary" :loading="printing" @click="handlePrint">打印</el-button>
    </template>
  </el-dialog>
</template>

<script>
import { flowCardPrintOptions, flowCardLabel } from '@/api/mes/ProductionOrderList/flowCard'

const escapeHtml = (s) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

const buildPrintHtml = (label) => {
  const ySteel = `${label.y_material_spec || ''}${label.y_material_model || ''}`
  const finished = `${label.c_material_spec || ''}×${label.c_material_model || ''}`
  const meta = `数量 ${label.plan_num}${label.device_name ? ` · ${label.device_name}` : ''}`
  return `<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>流转卡</title>
<style>
  /* 排版在此调整：@page 改纸张宽；.qr img 改码大小；font-size 改字号 */
  @page { margin: 2mm; size: 58mm auto; }
  * { box-sizing: border-box; }
  body { font-family: "PingFang SC", "Microsoft YaHei", sans-serif; margin: 0; padding: 0; }
  .label {
    width: 54mm;
    margin: 0 auto;
    padding: 1.5mm 2mm;
    display: flex;
    flex-direction: row;
    align-items: stretch;
    gap: 2mm;
  }
  .qr { flex: 0 0 24mm; display: flex; align-items: center; justify-content: center; }
  .qr img { width: 24mm; height: 24mm; display: block; }
  .info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
  .title {
    font-size: 10px;
    font-weight: 700;
    line-height: 1.25;
    margin-bottom: 1mm;
    word-break: break-all;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }
  .row { font-size: 9px; line-height: 1.35; color: #222; }
  .row + .row { margin-top: 0.5mm; }
  .lbl { color: #555; }
</style></head><body>
<div class="label">
  <div class="qr"><img src="${label.qr_image}" alt="qr" /></div>
  <div class="info">
    <div class="title">${escapeHtml(label.production_plan_name)}</div>
    <div class="row"><span class="lbl">圆钢 </span>${escapeHtml(ySteel)}</div>
    <div class="row"><span class="lbl">成品 </span>${escapeHtml(finished)}</div>
    <div class="row">${escapeHtml(meta)}</div>
  </div>
</div>
<script>window.onload=function(){window.print();};<\/script>
</body></html>`
}

export default {
  name: 'FlowCardPrintDialog',
  props: {
    modelValue: { type: Boolean, default: false },
    planId: { type: Number, default: 0 },
    planName: { type: String, default: '' }
  },
  emits: ['update:modelValue'],
  data() {
    return {
      loading: false,
      printing: false,
      options: {
        materials: [],
        devices: [],
        need_pick_material: false,
        need_pick_device: false
      },
      materialId: null,
      deviceCode: ''
    }
  },
  computed: {
    visibleInner: {
      get() {
        return this.modelValue
      },
      set(v) {
        this.$emit('update:modelValue', v)
      }
    }
  },
  watch: {
    modelValue(val) {
      if (val && this.planId) {
        this.loadOptions()
      }
    }
  },
  methods: {
    async loadOptions() {
      this.loading = true
      this.materialId = null
      this.deviceCode = ''
      try {
        const res = await flowCardPrintOptions({ id: this.planId })
        if (res.code !== 200) {
          ElMessage.error(res.msg || '加载失败')
          this.visibleInner = false
          return
        }
        this.options = res.data || this.options
        if (this.options.materials?.length === 1) {
          this.materialId = this.options.materials[0].material_id
        }
        if (this.options.devices?.length === 1) {
          this.deviceCode = this.options.devices[0].device_code
        }
      } catch (e) {
        ElMessage.error('加载打印选项失败')
        this.visibleInner = false
      } finally {
        this.loading = false
      }
    },
    async handlePrint() {
      if (this.options.need_pick_material && !this.materialId) {
        ElMessage.warning('请选择圆钢明细')
        return
      }
      if (this.options.need_pick_device && !this.deviceCode) {
        ElMessage.warning('请选择穿孔设备')
        return
      }
      this.printing = true
      try {
        const payload = { id: this.planId }
        if (this.materialId) payload.material_id = this.materialId
        if (this.deviceCode) payload.device_code = this.deviceCode
        const res = await flowCardLabel(payload)
        if (res.code !== 200) {
          ElMessage.error(res.msg || '获取标签失败')
          return
        }
        const html = buildPrintHtml(res.data)
        const win = window.open('', '_blank')
        if (!win) {
          ElMessage.error('请允许浏览器弹出窗口以打印')
          return
        }
        win.document.write(html)
        win.document.close()
        this.visibleInner = false
      } catch (e) {
        ElMessage.error('打印失败')
      } finally {
        this.printing = false
      }
    },
    onClosed() {
      this.options = { materials: [], devices: [], need_pick_material: false, need_pick_device: false }
    }
  }
}
</script>

<style scoped>
.hint {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--el-text-color-primary);
}
.hint--sub {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
