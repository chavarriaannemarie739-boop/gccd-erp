const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    processName: '',
    checkDate: '',

    _allModels: [],
    filteredModels: [],
    modelSearch: '',
    selectedModel: null,
    showModelPopup: false,

    // 不良品项目（多选）
    _allDefects: [],
    filteredDefects: [],
    defectSearch: '',
    selectedDefectIds: [],
    selectedDefectMap: {},
    showDefectPopup: false,

    // 已选不良品明细（每项带白班/夜班数量）
    defectItems: [],

    // 已提交列表
    addedRecords: [],

    // 提交
    loading: false,
    submitting: false,
    showSubmitSuccessDialog: false,
    showConfirmDialog: false,
    remark: '',
  },

  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => { this.setData({ statusBarHeight: res.statusBarHeight }); },
    });
    this.setData({
      processName: options.process || '',
      checkDate: options.date || '',
    });
    this._loadDraft();
    this._loadMaterialOptions();
    this._loadDefectOptions();
  },

  navigateBack() { wx.navigateBack({ delta: 1 }); },

  // ======== 物料 (成品档案) ========
  _loadMaterialOptions() {
    this.setData({ materialLoading: true });
    util.request("admin/production.Material/list", { page: 1, limit: 1000, material_property: "成品" }, "POST")
      .then((res) => {
        if (res.data.code === 200 && res.data.data) {
          const list = res.data.data.list || [];
          this._buildModelOptions(list);
        } else {
          Toast.fail(res.data.msg || '获取成品档案失败');
        }
      })
      .catch(() => Toast.fail('获取成品档案失败'))
      .finally(() => this.setData({ materialLoading: false }));
  },

  _buildModelOptions(list) {
    const seen = new Map();
    list.forEach((t) => {
      const cName = (t.material_name || '').trim();
      const cSpec = (t.material_spec || '').trim();
      const cModel = (t.material_model || '').trim();
      if (!cName && !cSpec && !cModel) return;
      const key = `${cName}|${cSpec}|${cModel}`;
      if (!seen.has(key)) {
        seen.set(key, {
          label: [cName, cSpec, cModel].filter(Boolean).join(' / '),
          c_material_name: cName,
          c_material_spec: cSpec,
          c_material_model: cModel,
        });
      }
    });
    const arr = Array.from(seen.values());
    this.setData({ _allModels: arr, filteredModels: arr });
  },

  showModelPicker() {
    if (this.data._allModels.length === 0) { Toast('暂无成品型号数据'); return; }
    this.setData({ showModelPopup: true, modelSearch: '', filteredModels: this.data._allModels });
  },
  hideModelPicker() { this.setData({ showModelPopup: false }); },
  onModelSearchInput(e) {
    const kw = (e.detail.value || e.detail || '').toLowerCase();
    this.setData({ modelSearch: kw });
    this.setData({ filteredModels: kw ? this.data._allModels.filter((it) => it.label.toLowerCase().includes(kw)) : this.data._allModels });
  },
  onModelSearchClear() { this.setData({ modelSearch: '', filteredModels: this.data._allModels }); },
  onModelSelect(e) {
    this.setData({ selectedModel: this.data.filteredModels[e.currentTarget.dataset.idx], showModelPopup: false });
  },

  // ======== 不良品多选 ========
  _loadDefectOptions() {
    util.request("admin/check.Check/list", { limit: 1000 }, "POST")
      .then((res) => {
        if (res.data.code === 200 && res.data.data) {
          const arr = (res.data.data.list || []).map((it) => ({
            id: String(it.id),
            name: it.check_name,
            code: it.check_code || '',
          }));
          this.setData({ _allDefects: arr, filteredDefects: arr });
        }
      })
      .catch(() => Toast.fail('获取不良品类型失败'));
  },

  showDefectPicker() {
    if (this.data._allDefects.length === 0) { Toast('暂无不良品类型'); return; }
    const ids = this.data.defectItems.map((d) => d.type);
    const map = {};
    ids.forEach((id) => { map[id] = true; });
    this.setData({
      showDefectPopup: true,
      defectSearch: '',
      filteredDefects: this.data._allDefects,
      selectedDefectIds: ids,
      selectedDefectMap: map,
    });
  },
  hideDefectPicker() { this.setData({ showDefectPopup: false }); },
  onDefectSearchInput(e) {
    const kw = (e.detail.value || e.detail || '').toLowerCase();
    this.setData({ defectSearch: kw });
    this.setData({ filteredDefects: kw ? this.data._allDefects.filter((it) => it.name.toLowerCase().includes(kw) || (it.code || '').toLowerCase().includes(kw)) : this.data._allDefects });
  },
  onDefectSearchClear() { this.setData({ defectSearch: '', filteredDefects: this.data._allDefects }); },

  onDefectToggle(e) {
    const id = e.currentTarget.dataset.id;
    const ids = [...this.data.selectedDefectIds];
    const map = { ...this.data.selectedDefectMap };
    const idx = ids.indexOf(id);
    if (idx >= 0) {
      ids.splice(idx, 1);
      delete map[id];
    } else {
      ids.push(id);
      map[id] = true;
    }
    this.setData({ selectedDefectIds: ids, selectedDefectMap: map });
  },

  confirmDefectSelect() {
    const oldItems = this.data.defectItems;
    const newItems = this.data.selectedDefectIds.map((id) => {
      const existing = oldItems.find((d) => d.type === id);
      if (existing) return existing;
      const def = this.data._allDefects.find((d) => d.id === id);
      return { type: id, typeText: def ? def.name : id, day_qty: '', night_qty: '' };
    });
    this.setData({ defectItems: newItems, showDefectPopup: false });
  },

  removeDefectItem(e) {
    const idx = e.currentTarget.dataset.index;
    const newItems = this.data.defectItems.filter((_, i) => i !== idx);
    this.setData({ defectItems: newItems });
  },

  onDefectDayInput(e) {
    const idx = e.currentTarget.dataset.index;
    this.setData({ [`defectItems[${idx}].day_qty`]: e.detail.value });
  },

  onDefectNightInput(e) {
    const idx = e.currentTarget.dataset.index;
    this.setData({ [`defectItems[${idx}].night_qty`]: e.detail.value });
  },

  // ======== 添加记录 ========
  addRecord() {
    const { selectedModel, defectItems, addedRecords } = this.data;
    if (!selectedModel) { Toast('请选择成品型号'); return; }
    if (defectItems.length === 0) { Toast('请选择不良品项目'); return; }

    let hasQty = false;
    for (const d of defectItems) {
      if ((Number(d.day_qty) || 0) > 0 || (Number(d.night_qty) || 0) > 0) { hasQty = true; break; }
    }
    if (!hasQty) { Toast('请至少填写一项不良品数量'); return; }

    const record = {
      c_material_name: selectedModel.c_material_name,
      c_material_spec: selectedModel.c_material_spec,
      c_material_model: selectedModel.c_material_model,
      defective_items: defectItems.map((d) => ({
        type: d.type,
        typeText: d.typeText,
        day_qty: d.day_qty || '0',
        night_qty: d.night_qty || '0',
      })),
    };

    const newList = [...addedRecords, record];
    this.setData({
      addedRecords: newList,
      selectedModel: null,
      defectItems: [],
    });
    this._saveDraft(newList);
    Toast.success('已添加');
  },

  onRemarkInput(e) {
    this.setData({ remark: e.detail.value });
  },

  removeRecord(e) {
    const idx = e.currentTarget.dataset.index;
    const newList = this.data.addedRecords.filter((_, i) => i !== idx);
    this.setData({ addedRecords: newList });
    this._saveDraft(newList);
  },

  // ======== 提交 ========
  onSubmitTap() {
    if (this.data.addedRecords.length === 0) { Toast('请至少添加一条质检记录'); return; }
    this.setData({ showConfirmDialog: true });
  },
  onConfirmDialogCancel() { this.setData({ showConfirmDialog: false }); },
  onConfirmDialogConfirm() {
    this.setData({ showConfirmDialog: false });
    this._doSubmit();
  },

  _doSubmit() {
    if (this._submitLock || this.data.submitting) return;
    this._submitLock = true;
    this.setData({ loading: true, submitting: true });
    wx.showLoading({ title: '提交中...', mask: true });

    const postData = {
      check_date: this.data.checkDate,
      process_name: this.data.processName,
      user_id: wx.getStorageSync('user_id') || '',
      remark: this.data.remark,
      records: this.data.addedRecords,
    };
    console.log('[qualityCheckAdd] 提交数据:', JSON.stringify(postData));

    util.request("admin/production.QualityCheckRecord/submitQualityCheck", postData, "POST")
      .then((res) => {
        if (res.data.code === 200) {
          this.setData({ loading: false, showSubmitSuccessDialog: true });
        } else {
          Toast.fail(res.data.msg || '提交失败');
          this.setData({ loading: false });
          this._submitLock = false;
        }
      })
      .catch(() => {
        Toast.fail('网络请求失败');
        this.setData({ loading: false });
        this._submitLock = false;
      })
      .finally(() => {
        wx.hideLoading();
        this.setData({ submitting: false });
      });
  },

  onSubmitSuccessConfirm() {
    this._submitLock = false;
    this._clearDraft();
    this.setData({ submitting: false, showSubmitSuccessDialog: false, addedRecords: [], defectItems: [], remark: '' });
    wx.navigateBack({ delta: 1 });
  },
  onSubmitSuccessDialogClose() {
    this._submitLock = false;
    this._clearDraft();
    this.setData({ showSubmitSuccessDialog: false, submitting: false, addedRecords: [], defectItems: [], remark: '' });
  },

  // ======== 草稿 ========
  _saveDraft(list) {
    const all = wx.getStorageSync('qc_draft') || {};
    if (!all[this.data.checkDate]) all[this.data.checkDate] = {};
    all[this.data.checkDate][this.data.processName] = list;
    wx.setStorageSync('qc_draft', all);
  },
  _loadDraft() {
    const all = wx.getStorageSync('qc_draft') || {};
    const list = (all[this.data.checkDate] && all[this.data.checkDate][this.data.processName]) || [];
    if (list.length > 0) this.setData({ addedRecords: list });
  },
  _clearDraft() {
    const all = wx.getStorageSync('qc_draft') || {};
    if (all[this.data.checkDate]) { delete all[this.data.checkDate][this.data.processName]; wx.setStorageSync('qc_draft', all); }
  },
});
