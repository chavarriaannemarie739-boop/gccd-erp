const util = require("../../../utils/util.js");
import Toast from "@vant/weapp/toast/toast";

const CI_SUB_TYPES = ["退回带油管", "成品夹灰"];
const BAN_SUB_TYPES = ["半成品夹灰", "半成品翘皮"];

const emptyLineRow = () => ({
  rowKey: `line-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
  mat: null,
  product: null,
  subType: "",
  qty: "",
});

const newRowKey = () =>
  `ci-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;

const emptyCiRow = () => ({
  rowKey: newRowKey(),
  specInput: "",
  qty: "",
});

const initialCiBlocks = () =>
  CI_SUB_TYPES.map((sub) => ({ sub, lines: [emptyCiRow()] }));

const initialBanBlocks = () =>
  BAN_SUB_TYPES.map((sub) => ({ sub, lines: [{ rowKey: `ban-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`, specInput: "", qty: "" }] }));

function processFlags(processName) {
  const isDuanGangStyle = processName === "圆钢";
  const isDuanGangManualProduct = processName === "断钢";
  const isCiChengPin = processName === "次成品";
  const isBanChengPin = processName === "半成品";
  const isSpecInputMode = ["穿孔", "球化", "矫直", "酸洗", "次成品", "半成品"].includes(processName);
  return { isDuanGangStyle, isDuanGangManualProduct, isCiChengPin, isBanChengPin, isSpecInputMode };
}

Page({
  data: {
    statusBarHeight: 0,
    processName: "",
    stocktakeDate: "",
    isDuanGangStyle: false,
    isDuanGangManualProduct: false,
    isSpecInputMode: false,
    isCiChengPin: false,
    isBanChengPin: false,
    sectionHint: "",
    _allMaterials: [],
    filteredMaterials: [],
    materialLoading: false,
    materialSearch: "",
    showMaterialPopup: false,
    pickerRowIndex: 0,
    _allModels: [],
    filteredModels: [],
    modelSearch: "",
    showModelPopup: false,
    lines: [],
    totalQty: "0.000",
    loading: false,
    submitting: false,
    showConfirmDialog: false,
    showSubmitSuccessDialog: false,
    confirmItemCount: 0,
    confirmTotalQty: "0.000",
    confirmDialogMessage: "",
    successDialogMessage: "",
    modelPopupTitle: "选择成品（档案）",
    ciBlocks: [],
    ciBlockIdx: 0,
    ciRowIdx: 0,
    banBlocks: [],
    banBlockIdx: 0,
    banRowIdx: 0,
  },

  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({ statusBarHeight: res.statusBarHeight });
      },
    });

    let processName = (options.process || "").trim();
    try {
      processName = decodeURIComponent(processName);
    } catch (e) {}
    processName = (processName || "").trim();
    const stocktakeDate = (options.date || "").trim();
    const autoFill = options.autoFill === '1';
    const { isDuanGangStyle, isDuanGangManualProduct, isSpecInputMode, isCiChengPin, isBanChengPin } = processFlags(processName);

    let sectionHint = "原料、成品与数量同一行，可添加多行";
    if (isCiChengPin) {
      sectionHint = "分两类填写：输入规格（如 51*5）与库存吨数";
    } else if (isBanChengPin) {
      sectionHint = "分别输入规格（如 51*5）与库存吨数";
    } else if (isDuanGangStyle) {
      sectionHint = "选圆钢、填吨数，可添加多行";
    } else if (isDuanGangManualProduct) {
      sectionHint = "选圆钢型号、输入断钢规格（如 50*1.2）、填吨数";
    } else if (isSpecInputMode) {
      sectionHint = "选圆钢型号、输入规格（如 51*5）、填吨数";
    }

    this.setData({
      processName,
      stocktakeDate,
      isDuanGangStyle,
      isDuanGangManualProduct,
      isSpecInputMode,
      isCiChengPin,
      isBanChengPin,
      sectionHint,
      modelPopupTitle: isCiChengPin ? "选择成品（规格/型号）" : "选择成品（档案）",
      successDialogMessage: `${processName || "当前"}工序盘点已成功提交。`,
    });

    if (isCiChengPin) {
      this._loadCiDraft(stocktakeDate, processName);
    } else if (isBanChengPin) {
      this._loadBanDraft(stocktakeDate, processName);
    } else {
      this._loadDraft(stocktakeDate, processName, autoFill);
    }
    this._loadMaterialOptions(processName);
  },

  navigateBack() {
    wx.navigateBack({ delta: 1 });
  },

  _loadCiDraft(dateKey, processName) {
    const all = wx.getStorageSync("stocktake_draft") || {};
    const raw = (all[dateKey] && all[dateKey][processName]) || null;
    if (raw && raw.v === 2 && Array.isArray(raw.ciBlocks)) {
      this.setData({ ciBlocks: this._restoreCiBlocks(raw.ciBlocks) });
    } else if (raw && Array.isArray(raw) && !raw.v) {
      this.setData({ ciBlocks: this._migrateFlatLinesToCiBlocks(raw) });
    } else {
      this.setData({ ciBlocks: initialCiBlocks() });
    }
    this._updateTotalCi();
  },

  _restoreCiBlocks(blocks) {
    if (!Array.isArray(blocks) || blocks.length === 0) return initialCiBlocks();
    return blocks.map((b) => {
      const sub = b.sub;
      if (!CI_SUB_TYPES.includes(sub)) return { sub, lines: [emptyCiRow()] };
      const lines = (b.lines || []).map((line) => ({
        rowKey: line.rowKey || newRowKey(),
        product: line.product || null,
        qty: line.qty != null ? String(line.qty) : "",
      }));
      return { sub, lines: lines.length > 0 ? lines : [emptyCiRow()] };
    });
  },

  _migrateFlatLinesToCiBlocks(oldLines) {
    const blocks = initialCiBlocks();
    (oldLines || []).forEach((line) => {
      const sub = (line.subType || "").trim();
      if (!CI_SUB_TYPES.includes(sub)) return;
      const block = blocks.find((x) => x.sub === sub);
      if (!block) return;
      const row = {
        rowKey: line.rowKey || newRowKey(),
        product: line.product || null,
        qty: line.qty != null ? String(line.qty) : "",
      };
      const touched =
        line.product || (line.qty != null && String(line.qty).trim() !== "");
      if (touched) {
        if (
          block.lines.length === 1 &&
          !block.lines[0].product &&
          !String(block.lines[0].qty || "").trim()
        ) {
          block.lines[0] = row;
        } else {
          block.lines.push(row);
        }
      }
    });
    return blocks;
  },

  _saveDraftCi() {
    if (!this.data.isCiChengPin) return;
    const all = wx.getStorageSync("stocktake_draft") || {};
    const dateKey = this.data.stocktakeDate;
    if (!all[dateKey]) all[dateKey] = {};
    all[dateKey][this.data.processName] = { v: 2, ciBlocks: this.data.ciBlocks };
    wx.setStorageSync("stocktake_draft", all);
  },

  _updateTotalCi() {
    let sum = 0;
    (this.data.ciBlocks || []).forEach((b) => {
      (b.lines || []).forEach((l) => {
        sum += parseFloat(l.qty) || 0;
      });
    });
    this.setData({ totalQty: sum.toFixed(3) });
  },

  _loadBanDraft(dateKey, processName) {
    const all = wx.getStorageSync("stocktake_draft") || {};
    const raw = (all[dateKey] && all[dateKey][processName]) || null;
    if (raw && raw.v === 3 && Array.isArray(raw.banBlocks)) {
      this.setData({ banBlocks: this._restoreBanBlocks(raw.banBlocks) });
    } else {
      this.setData({ banBlocks: initialBanBlocks() });
    }
    this._updateTotalBan();
  },

  _restoreBanBlocks(blocks) {
    if (!Array.isArray(blocks) || blocks.length === 0) return initialBanBlocks();
    return blocks.map((b) => {
      const sub = b.sub;
      if (!BAN_SUB_TYPES.includes(sub)) {
        return { sub, lines: [{ rowKey: `ban-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`, qty: "" }] };
      }
      const line = (b.lines && b.lines[0]) || {};
      return {
        sub,
        lines: [{
          rowKey: line.rowKey || `ban-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
          qty: line.qty != null ? String(line.qty) : "",
        }],
      };
    });
  },

  _saveDraftBan() {
    if (!this.data.isBanChengPin) return;
    const all = wx.getStorageSync("stocktake_draft") || {};
    const dateKey = this.data.stocktakeDate;
    if (!all[dateKey]) all[dateKey] = {};
    all[dateKey][this.data.processName] = { v: 3, banBlocks: this.data.banBlocks };
    wx.setStorageSync("stocktake_draft", all);
  },

  _updateTotalBan() {
    let sum = 0;
    (this.data.banBlocks || []).forEach((b) => {
      (b.lines || []).forEach((l) => {
        sum += parseFloat(l.qty) || 0;
      });
    });
    this.setData({ totalQty: sum.toFixed(3) });
  },

  _loadMaterialOptions(processName) {
    const { isDuanGangStyle, isSpecInputMode, isCiChengPin, isBanChengPin } = processFlags(processName);
    this.setData({ materialLoading: true });

    // 次成品/半成品不需要加载圆钢档案
    const skipRound = isCiChengPin || isBanChengPin;
    const roundReq = skipRound
      ? Promise.resolve({ data: { code: 200, data: { list: [] } } })
      : util.request(
          "admin/production.Material/list",
          { page: 1, limit: 2000, material_property: "圆钢" },
          "POST"
        );

    // 规格输入模式（穿孔+）：不加载成品档案，仅需圆钢档案
    const productReq = (isDuanGangStyle || isSpecInputMode)
      ? Promise.resolve({ data: { code: 200, data: { list: [] } } })
      : util.request(
          "admin/production.Material/list",
          { page: 1, limit: 2000, material_property: "成品" },
          "POST"
        );

    Promise.all([roundReq, productReq])
      .then(([roundRes, productRes]) => {
        if (!skipRound) {
          if (roundRes.data.code === 200 && roundRes.data.data) {
            this._buildMaterialOptions(roundRes.data.data.list || []);
            // 圆钢档案加载完成后，修复自动预填行的 material_id
            this._fixAutoFillMaterialIds();
          } else {
            Toast.fail(roundRes.data.msg || "获取圆钢档案失败");
          }
        }
        if (!isDuanGangStyle && !isSpecInputMode) {
          if (productRes.data.code === 200 && productRes.data.data) {
            this._buildModelOptions(productRes.data.data.list || []);
          } else {
            Toast.fail(productRes.data.msg || "获取成品档案失败");
          }
        }
      })
      .catch(() => {
        Toast.fail("获取物料档案失败，请检查网络");
      })
      .finally(() => {
        this.setData({ materialLoading: false });
      });
  },

  _buildMaterialOptions(list) {
    const seen = new Map();
    list.forEach((row) => {
      const yName = (row.material_name || "").trim();
      const ySpec = (row.material_spec || "").trim();
      const yModel = (row.material_model || "").trim();
      if (!yName && !ySpec && !yModel) return;
      const key = `${row.id ?? ""}|${yName}|${ySpec}|${yModel}`;
      if (!seen.has(key)) {
        seen.set(key, {
          uniqKey: `rg-${row.id ?? key}`,
          material_id: row.id,
          label: [yName, ySpec, yModel].filter(Boolean).join(" / "),
          y_material_name: yName,
          y_material_spec: ySpec,
          y_material_model: yModel,
        });
      }
    });
    const arr = Array.from(seen.values());
    this.setData({ _allMaterials: arr, filteredMaterials: arr });
  },

  _buildModelOptions(list) {
    const seen = new Map();
    list.forEach((row) => {
      const cSpec = (row.material_spec || "").trim();
      const cModel = (row.material_model || "").trim();
      if (!cSpec && !cModel) return;
      const key = `${row.id ?? ""}|${cSpec}|${cModel}`;
      if (!seen.has(key)) {
        seen.set(key, {
          uniqKey: `fp-${row.id ?? key}`,
          material_id: row.id,
          label: [cSpec, cModel].filter(Boolean).join(" / "),
          c_product_spec: cSpec,
          c_product_model: cModel,
        });
      }
    });
    const arr = Array.from(seen.values());
    this.setData({ _allModels: arr, filteredModels: arr });
  },

  /**
   * 修复自动预填行的 material_id（auto-fill 时 material_id 为 0）
   * 圆钢档案加载完成后调用，按名称+规格匹配补上正确的 ID
   */
  _fixAutoFillMaterialIds() {
    const lines = [...this.data.lines];
    const materials = this.data._allMaterials || [];
    let changed = false;
    lines.forEach((line) => {
      if (!line.mat) return;
      if (!line.mat.material_id && line.mat.y_material_name) {
        const found = materials.find(
          (m) =>
            m.y_material_name === line.mat.y_material_name &&
            m.y_material_spec === line.mat.y_material_spec &&
            m.y_material_model === line.mat.y_material_model
        );
        if (found && found.material_id) {
          line.mat.material_id = found.material_id;
          changed = true;
        }
      }
      if (line.mat.material_id && !line.mat.y_material_name) {
        const found = materials.find((m) => (m.material_id || 0) === line.mat.material_id);
        if (found) {
          line.mat = { ...found };
          changed = true;
        }
      }
    });
    if (changed) {
      this.setData({ lines });
      this._saveDraft(lines);
    }
  },

  showMaterialPicker(e) {
    if (this.data.isCiChengPin) return;
    const row = e.currentTarget.dataset.row;
    if (row === undefined || row === null) return;
    if (this.data.materialLoading) {
      Toast.loading({ message: "物料加载中...", forbidClick: true });
      return;
    }
    if (this.data._allMaterials.length === 0) {
      Toast("暂无圆钢档案数据");
      return;
    }
    this.setData({
      pickerRowIndex: row,
      showMaterialPopup: true,
      materialSearch: "",
      filteredMaterials: this.data._allMaterials,
    });
  },

  hideMaterialPicker() {
    this.setData({ showMaterialPopup: false });
  },

  onMaterialSearchInput(e) {
    const kw = (e.detail.value || e.detail || "").toLowerCase();
    this.setData({ materialSearch: kw });
    if (!kw) {
      this.setData({ filteredMaterials: this.data._allMaterials });
      return;
    }
    const filtered = this.data._allMaterials.filter((it) =>
      it.label.toLowerCase().includes(kw)
    );
    this.setData({ filteredMaterials: filtered });
  },

  onMaterialSearchClear() {
    this.setData({
      materialSearch: "",
      filteredMaterials: this.data._allMaterials,
    });
  },

  onMaterialSelect(e) {
    const idx = e.currentTarget.dataset.idx;
    const mat = this.data.filteredMaterials[idx];
    const rowIndex = this.data.pickerRowIndex;
    const lines = [...this.data.lines];
    if (!lines[rowIndex]) return;
    lines[rowIndex] = { ...lines[rowIndex], mat };
    this.setData({ lines, showMaterialPopup: false });
    this._updateTotal(lines);
    this._saveDraft(lines);
  },

  showModelPicker(e) {
    if (this.data.isCiChengPin) return;
    const row = e.currentTarget.dataset.row;
    if (row === undefined || row === null) return;
    if (this.data._allModels.length === 0) {
      Toast("暂无成品档案数据");
      return;
    }
    this.setData({
      pickerRowIndex: row,
      showModelPopup: true,
      modelSearch: "",
      filteredModels: this.data._allModels,
    });
  },

  showModelPickerCi(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const row = Number(e.currentTarget.dataset.row);
    if (this.data.materialLoading) {
      Toast.loading({ message: "成品档案加载中...", forbidClick: true });
      return;
    }
    if (this.data._allModels.length === 0) {
      Toast("暂无成品档案数据");
      return;
    }
    this.setData({
      ciBlockIdx: blockIdx,
      ciRowIdx: row,
      modelPopupTitle: "选择成品（规格+型号）",
      showModelPopup: true,
      modelSearch: "",
      filteredModels: this.data._allModels,
    });
  },

  hideModelPicker() {
    this.setData({ showModelPopup: false });
  },

  onModelSearchInput(e) {
    const kw = (e.detail.value || e.detail || "").toLowerCase();
    this.setData({ modelSearch: kw });
    if (!kw) {
      this.setData({ filteredModels: this.data._allModels });
      return;
    }
    const filtered = this.data._allModels.filter((it) => {
      const hay =
        `${it.c_product_spec || ""} ${it.c_product_model || ""} ${it.label || ""}`.toLowerCase();
      return hay.includes(kw);
    });
    this.setData({ filteredModels: filtered });
  },

  onModelSearchClear() {
    this.setData({
      modelSearch: "",
      filteredModels: this.data._allModels,
    });
  },

  onModelSelect(e) {
    const idx = e.currentTarget.dataset.idx;
    const model = this.data.filteredModels[idx];
    if (this.data.isCiChengPin) {
      const { ciBlockIdx, ciRowIdx } = this.data;
      const ciBlocks = JSON.parse(JSON.stringify(this.data.ciBlocks));
      if (!ciBlocks[ciBlockIdx] || !ciBlocks[ciBlockIdx].lines[ciRowIdx]) {
        this.setData({ showModelPopup: false });
        return;
      }
      ciBlocks[ciBlockIdx].lines[ciRowIdx] = {
        ...ciBlocks[ciBlockIdx].lines[ciRowIdx],
        product: model,
      };
      this.setData({ ciBlocks, showModelPopup: false });
      this._saveDraftCi();
      this._updateTotalCi();
      return;
    }
    const rowIndex = this.data.pickerRowIndex;
    const lines = [...this.data.lines];
    if (!lines[rowIndex]) return;
    lines[rowIndex] = { ...lines[rowIndex], product: model };
    this.setData({ lines, showModelPopup: false });
    this._saveDraft(lines);
  },

  onLineQtyInput(e) {
    if (this.data.isCiChengPin) return;
    const row = Number(e.currentTarget.dataset.row);
    const val = this._sanitizeTonInput(e.detail.value);
    const lines = [...this.data.lines];
    if (!lines[row]) return;
    lines[row] = { ...lines[row], qty: val };
    this.setData({ lines });
    this._updateTotal(lines);
    this._saveDraft(lines);
  },

  onLineManualProductInput(e) {
    if (this.data.isCiChengPin) return;
    const row = Number(e.currentTarget.dataset.row);
    const val = e.detail.value;
    const lines = [...this.data.lines];
    if (!lines[row]) return;
    lines[row] = { ...lines[row], manualProduct: val };
    this.setData({ lines });
    this._saveDraft(lines);
  },

  onLineSpecInput(e) {
    if (!this.data.isSpecInputMode) return;
    const row = Number(e.currentTarget.dataset.row);
    const val = e.detail.value;
    const lines = [...this.data.lines];
    if (!lines[row]) return;
    lines[row] = { ...lines[row], specInput: val };
    this.setData({ lines });
    this._saveDraft(lines);
  },

  onLineSpecInputCi(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const row = Number(e.currentTarget.dataset.row);
    const val = e.detail.value;
    const ciBlocks = JSON.parse(JSON.stringify(this.data.ciBlocks));
    if (!ciBlocks[blockIdx] || !ciBlocks[blockIdx].lines[row]) return;
    ciBlocks[blockIdx].lines[row] = { ...ciBlocks[blockIdx].lines[row], specInput: val };
    this.setData({ ciBlocks });
    this._saveDraftCi();
  },

  onLineQtyInputCi(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const row = Number(e.currentTarget.dataset.row);
    const val = this._sanitizeTonInput(e.detail.value);
    const ciBlocks = JSON.parse(JSON.stringify(this.data.ciBlocks));
    if (!ciBlocks[blockIdx] || !ciBlocks[blockIdx].lines[row]) return;
    ciBlocks[blockIdx].lines[row] = { ...ciBlocks[blockIdx].lines[row], qty: val };
    this.setData({ ciBlocks });
    this._updateTotalCi();
    this._saveDraftCi();
  },

  onLineSpecInputBan(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const row = Number(e.currentTarget.dataset.row);
    const val = e.detail.value;
    const banBlocks = JSON.parse(JSON.stringify(this.data.banBlocks));
    if (!banBlocks[blockIdx] || !banBlocks[blockIdx].lines[row]) return;
    banBlocks[blockIdx].lines[row] = { ...banBlocks[blockIdx].lines[row], specInput: val };
    this.setData({ banBlocks });
    this._saveDraftBan();
  },

  onLineQtyInputBan(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const row = Number(e.currentTarget.dataset.row);
    const val = this._sanitizeTonInput(e.detail.value);
    const banBlocks = JSON.parse(JSON.stringify(this.data.banBlocks));
    if (!banBlocks[blockIdx] || !banBlocks[blockIdx].lines[row]) return;
    banBlocks[blockIdx].lines[row] = { ...banBlocks[blockIdx].lines[row], qty: val };
    this.setData({ banBlocks });
    this._updateTotalBan();
    this._saveDraftBan();
  },

  addLine() {
    if (this.data.isCiChengPin) return;
    const lines = [...this.data.lines, emptyLineRow()];
    this.setData({ lines });
    this._saveDraft(lines);
  },

  addCiLine(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const ciBlocks = JSON.parse(JSON.stringify(this.data.ciBlocks));
    if (!ciBlocks[blockIdx]) return;
    ciBlocks[blockIdx].lines.push(emptyCiRow());
    this.setData({ ciBlocks });
    this._saveDraftCi();
  },

  removeLine(e) {
    if (this.data.isCiChengPin) return;
    const idx = Number(e.currentTarget.dataset.index);
    let lines = this.data.lines.filter((_, i) => i !== idx);
    if (lines.length === 0) lines = [emptyLineRow()];
    this.setData({ lines });
    this._updateTotal(lines);
    this._saveDraft(lines);
  },

  removeLineCi(e) {
    const blockIdx = Number(e.currentTarget.dataset.block);
    const idx = Number(e.currentTarget.dataset.index);
    const ciBlocks = JSON.parse(JSON.stringify(this.data.ciBlocks));
    if (!ciBlocks[blockIdx]) return;
    if (ciBlocks[blockIdx].lines.length <= 1) {
      ciBlocks[blockIdx].lines[0] = emptyCiRow();
    } else {
      ciBlocks[blockIdx].lines = ciBlocks[blockIdx].lines.filter((_, i) => i !== idx);
    }
    this.setData({ ciBlocks });
    this._updateTotalCi();
    this._saveDraftCi();
  },

  _sanitizeTonInput(raw) {
    if (!raw) return "";
    const cleaned = raw.replace(/[^\d.]/g, "");
    const parts = cleaned.split(".");
    if (parts.length <= 1) return cleaned;
    return parts[0] + "." + parts.slice(1).join("").slice(0, 3);
  },

  _lineToSubmitPayload(line, subOverride) {
    const { isDuanGangStyle, isSpecInputMode, isCiChengPin, isBanChengPin } = this.data;
    if (isCiChengPin) {
      const sub = (
        subOverride != null && String(subOverride).trim() !== ""
          ? String(subOverride).trim()
          : (line.subType || "")
      ).trim();
      const specInput = String(line.specInput || "").trim();
      const qtyStr = String(line.qty != null ? line.qty : "").trim();
      if (!sub || !specInput || qtyStr === "") return null;
      const qty = parseFloat(qtyStr);
      if (isNaN(qty) || qty < 0) return null;
      if (!CI_SUB_TYPES.includes(sub)) return null;
      return {
        y_material_name: sub,
        y_material_spec: "",
        c_material_spec: specInput,
        c_material_model: "",
        day_shift_qty: qty.toFixed(3),
        night_shift_qty: "0",
      };
    }
    if (isBanChengPin) {
      const sub = (line.subType || "").trim();
      const specInput = String(line.specInput || "").trim();
      const qtyStr = String(line.qty != null ? line.qty : "").trim();
      if (!sub || !specInput || qtyStr === "") return null;
      const qty = parseFloat(qtyStr);
      if (isNaN(qty) || qty < 0) return null;
      return {
        y_material_name: sub,
        y_material_spec: "",
        c_material_spec: specInput,
        c_material_model: "",
        day_shift_qty: qty.toFixed(3),
        night_shift_qty: "0",
      };
    }
    const mat = line.mat;
    if (!mat) return null;
    let cProductSpec = "";
    let cProductModel = "";
    let cModelSubmit = "";
    if (this.data.isDuanGangManualProduct) {
      cProductSpec = String(line.manualProduct || "").trim();
      cProductModel = "";
      cModelSubmit = cProductSpec;
    } else if (isSpecInputMode) {
      cProductSpec = String(line.specInput || "").trim();
      cProductModel = "";
      cModelSubmit = cProductSpec;
    } else {
      const product = line.product;
      cProductSpec = isDuanGangStyle ? "" : (product && product.c_product_spec) || "";
      cProductModel = isDuanGangStyle ? "" : (product && product.c_product_model) || "";
      cModelSubmit = isDuanGangStyle ? "" : [cProductSpec, cProductModel].filter(Boolean).join(" / ");
    }
    const qtyStr = String(line.qty != null ? line.qty : "").trim();
    if (qtyStr === "") return null;
    const qty = parseFloat(qtyStr);
    if (isNaN(qty) || qty < 0) return null;
    if (!isDuanGangStyle && !this.data.isDuanGangManualProduct && !isSpecInputMode && !line.product) return null;
    let yModel = mat.y_material_model || "";
    let foundMatId = 0;
    if ((!yModel || !mat.material_id) && mat.y_material_name && mat.y_material_spec) {
      const found = this.data._allMaterials && this.data._allMaterials.find(m => m.y_material_name === mat.y_material_name && m.y_material_spec === mat.y_material_spec && m.y_material_model === mat.y_material_model);
      if (found) {
        yModel = yModel || found.y_material_model || "";
        foundMatId = found.material_id || 0;
      }
    }

    return {
      material_id: mat.material_id || foundMatId,
      y_material_name: mat.y_material_name,
      y_material_spec: mat.y_material_spec,
      y_material_model: yModel,
      c_material_spec: isSpecInputMode ? cProductSpec : (mat.c_material_spec || cProductSpec),
      c_product_spec: cProductSpec,
      c_product_model: cProductModel,
      c_material_model: cModelSubmit,
      label: isDuanGangStyle ? mat.label : `${mat.label} → ${cModelSubmit}`,
      day_shift_qty: qty.toFixed(3),
      night_shift_qty: "0",
    };
  },

  _lineKey(line, subOverride) {
    const { isDuanGangStyle, isSpecInputMode, isCiChengPin } = this.data;
    if (isCiChengPin) {
      const sub = (
        subOverride != null && String(subOverride).trim() !== ""
          ? String(subOverride).trim()
          : (line.subType || "")
      ).trim();
      const s = String(line.specInput || "").trim();
      if (!sub || !s) return "";
      return `ci|${sub}|${s}`;
    }
    const mat = line.mat;
    if (!mat) return "";
    const a = `${mat.y_material_name}|${mat.y_material_spec}|${mat.y_material_model}`;
    if (isDuanGangStyle) return a;
    if (this.data.isDuanGangManualProduct) {
      const p = String(line.manualProduct || "").trim();
      if (!p) return "";
      return `${a}|${p}`;
    }
    if (isSpecInputMode) {
      const p = String(line.specInput || "").trim();
      if (!p) return "";
      return `${a}|${p}`;
    }
    const p = line.product;
    if (!p) return "";
    return `${a}|${p.c_product_spec}|${p.c_product_model}`;
  },

  _updateTotal(lines) {
    let sum = 0;
    lines.forEach((line) => {
      sum += parseFloat(line.qty) || 0;
    });
    this.setData({ totalQty: sum.toFixed(3) });
  },

  onSubmitTap() {
    const { lines, isDuanGangStyle, isSpecInputMode, isCiChengPin, isBanChengPin, ciBlocks, banBlocks } = this.data;
    const items = [];
    const seen = new Set();

    if (isBanChengPin) {
      for (const block of banBlocks || []) {
        const sub = block.sub;
        const blockLines = block.lines || [];
        for (let i = 0; i < blockLines.length; i++) {
          const line = blockLines[i];
          const qtyStr = String(line.qty != null ? line.qty : "").trim();
          const hasSpec = !!(line.specInput && String(line.specInput).trim());
          const touched = hasSpec || qtyStr !== "";
          if (!touched) continue;
          if (!hasSpec) {
            Toast(`「${sub}」请填写规格`);
            return;
          }
          if (qtyStr === "") {
            Toast(`「${sub}」请填写库存（吨）`);
            return;
          }
          const q = parseFloat(qtyStr);
          if (isNaN(q) || q < 0) {
            Toast(`「${sub}」数量必须是大于或等于 0 的数字`);
            return;
          }
          const payload = {
            y_material_name: sub,
            y_material_spec: "",
            c_material_spec: String(line.specInput || "").trim(),
            c_material_model: "",
            day_shift_qty: q.toFixed(3),
            night_shift_qty: "0",
          };
          const k = `ban|${sub}|${String(line.specInput || "").trim()}`;
          if (seen.has(k)) {
            Toast("存在重复行，请合并或调整");
            return;
          }
          seen.add(k);
          items.push(payload);
        }
      }
      if (items.length === 0) {
        Toast("请至少填写一行半成品数据（填规格和吨数）");
        return;
      }
      const sum = items.reduce(
        (s, it) => s + (parseFloat(it.day_shift_qty) || 0),
        0
      );
      const pn = this.data.processName || "";
      const confirmDialogMessage = `确认提交 ${pn} 工序的盘点数据？\n共 ${items.length} 条明细\n合计 ${sum.toFixed(3)} 吨`;
      this._pendingSubmitItems = items;
      this.setData({
        showConfirmDialog: true,
        confirmItemCount: items.length,
        confirmTotalQty: sum.toFixed(3),
        confirmDialogMessage,
      });
      return;
    }

    if (isCiChengPin) {
      for (const block of ciBlocks || []) {
        const sub = block.sub;
        const blockLines = block.lines || [];
        for (let i = 0; i < blockLines.length; i++) {
          const line = blockLines[i];
          const qtyStr = String(line.qty != null ? line.qty : "").trim();
          const hasSpec = !!(line.specInput && String(line.specInput).trim());
          const touched = hasSpec || qtyStr !== "";
          if (!touched) continue;
          if (!hasSpec) {
            Toast(`「${sub}」第 ${i + 1} 行：请输入规格`);
            return;
          }
          if (qtyStr === "") {
            Toast(`「${sub}」第 ${i + 1} 行：请填写库存（吨）`);
            return;
          }
          const q = parseFloat(qtyStr);
          if (isNaN(q) || q < 0) {
            Toast(`「${sub}」第 ${i + 1} 行：数量必须是大于或等于 0 的数字`);
            return;
          }
          const payload = this._lineToSubmitPayload(line, sub);
          if (!payload) continue;
          const k = this._lineKey(line, sub);
          if (seen.has(k)) {
            Toast("存在重复行（同类型+同规格），请合并或调整");
            return;
          }
          seen.add(k);
          items.push(payload);
        }
      }
      if (items.length === 0) {
        Toast("请至少填写一行次成品数据（填规格和吨数）");
        return;
      }
      const sum = items.reduce(
        (s, it) => s + (parseFloat(it.day_shift_qty) || 0),
        0
      );
      const pn = this.data.processName || "";
      const confirmDialogMessage = `确认提交 ${pn} 工序的盘点数据？\n共 ${items.length} 条明细\n合计 ${sum.toFixed(3)} 吨`;
      this._pendingSubmitItems = items;
      this.setData({
        showConfirmDialog: true,
        confirmItemCount: items.length,
        confirmTotalQty: sum.toFixed(3),
        confirmDialogMessage,
      });
      return;
    }

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      const qtyStr = String(line.qty != null ? line.qty : "").trim();
      const hasMat = !!line.mat;
      const hasProd = !!line.product;
      const hasManualProd = !!(line.manualProduct && String(line.manualProduct).trim());
      const hasSpecInput = !!(line.specInput && String(line.specInput).trim());
      const touched = hasMat || hasProd || hasManualProd || hasSpecInput || qtyStr !== "";
      if (!touched) continue;
      if (!hasMat) {
        Toast(`第 ${i + 1} 行请选择原材料`);
        return;
      }
      if (this.data.isDuanGangManualProduct) {
        if (!hasManualProd) {
          Toast(`第 ${i + 1} 行请手动输入成品规格`);
          return;
        }
      } else if (isSpecInputMode) {
        if (!hasSpecInput) {
          Toast(`第 ${i + 1} 行请输入规格（如 51*5）`);
          return;
        }
      } else if (!isDuanGangStyle && !hasProd) {
        Toast(`第 ${i + 1} 行请选择成品`);
        return;
      }
      if (qtyStr === "") {
        Toast(`第 ${i + 1} 行请填写数量（吨）`);
        return;
      }
      const q = parseFloat(qtyStr);
      if (isNaN(q) || q < 0) {
        Toast(`第 ${i + 1} 行：数量必须是大于或等于 0 的数字`);
        return;
      }
      const payload = this._lineToSubmitPayload(line);
      if (!payload) continue;
      const k = this._lineKey(line);
      if (seen.has(k)) {
        Toast("存在重复行，请合并或删除重复项");
        return;
      }
      seen.add(k);
      items.push(payload);
    }

    if (items.length === 0) {
      let msg = "请至少填写一行完整的盘点明细";
      if (isCiChengPin) msg = "请至少填写一行次成品库存数据";
      else if (isDuanGangStyle) msg = "请至少填写一行（圆钢、数量）";
      else msg = "请至少填写一行（原材料、成品、数量）";
      Toast(msg);
      return;
    }

    const sum = items.reduce((s, it) => s + (parseFloat(it.day_shift_qty) || 0), 0);
    const pn = this.data.processName || "";
    const confirmDialogMessage = `确认提交 ${pn} 工序的盘点数据？\n共 ${items.length} 条明细\n合计 ${sum.toFixed(3)} 吨`;
    this._pendingSubmitItems = items;
    this.setData({
      showConfirmDialog: true,
      confirmItemCount: items.length,
      confirmTotalQty: sum.toFixed(3),
      confirmDialogMessage,
    });
  },

  onConfirmDialogCancel() {
    this.setData({ showConfirmDialog: false, confirmDialogMessage: "" });
    this._pendingSubmitItems = null;
  },

  onConfirmDialogConfirm() {
    this.setData({ showConfirmDialog: false, confirmDialogMessage: "" });
    this._doSubmit(this._pendingSubmitItems || []);
    this._pendingSubmitItems = null;
  },

  _doSubmit(items) {
    if (!items || items.length === 0) return;
    if (this._submitLock || this.data.submitting) return;
    this._submitLock = true;
    this.setData({ loading: true, submitting: true });
    wx.showLoading({ title: "提交中...", mask: true });

    const postData = {
      stocktake_date: this.data.stocktakeDate,
      process_name: this.data.processName,
      user_id: wx.getStorageSync("user_id") || "",
      items: items.map((it) => ({
        material_id: it.material_id || 0,
        y_material_name: it.y_material_name,
        y_material_spec: it.y_material_spec,
        y_material_model: it.y_material_model || "",
        c_material_spec: it.c_material_spec || "",
        c_material_model: it.c_material_model || "",
        c_product_spec: it.c_product_spec || "",
        c_product_model: it.c_product_model || "",
        day_shift_qty: it.day_shift_qty || "0",
        night_shift_qty: it.night_shift_qty || "0",
      })),
      remark: "",
    };

    util
      .request("admin/production.Inventory/submitStocktake", postData, "POST")
      .then((res) => {
        if (res.data.code === 200) {
          this._clearDraft();
          this.setData({ loading: false, showSubmitSuccessDialog: true });
        } else {
          Toast.fail(res.data.msg || "提交失败");
          this.setData({ loading: false });
          this._submitLock = false;
        }
      })
      .catch(() => {
        Toast.fail("网络请求失败");
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
    if (this.data.isCiChengPin) {
      this.setData({
        submitting: false,
        showSubmitSuccessDialog: false,
        ciBlocks: initialCiBlocks(),
        totalQty: "0.000",
      });
    } else if (this.data.isBanChengPin) {
      this.setData({
        submitting: false,
        showSubmitSuccessDialog: false,
        banBlocks: initialBanBlocks(),
        totalQty: "0.000",
      });
    } else {
      this.setData({
        submitting: false,
        showSubmitSuccessDialog: false,
        lines: [emptyLineRow()],
        totalQty: "0.000",
      });
    }
    wx.navigateBack({ delta: 1 });
  },

  onSubmitSuccessDialogClose() {
    this._submitLock = false;
    this._clearDraft();
    if (this.data.isCiChengPin) {
      this.setData({
        showSubmitSuccessDialog: false,
        submitting: false,
        ciBlocks: initialCiBlocks(),
        totalQty: "0.000",
      });
    } else if (this.data.isBanChengPin) {
      this.setData({
        showSubmitSuccessDialog: false,
        submitting: false,
        banBlocks: initialBanBlocks(),
        totalQty: "0.000",
      });
    } else {
      this.setData({
        showSubmitSuccessDialog: false,
        submitting: false,
        lines: [emptyLineRow()],
        totalQty: "0.000",
      });
    }
  },

  _serializeLinesForDraft(lines) {
    return lines.map((line) => ({
      rowKey: line.rowKey,
      qty: line.qty,
      mat: line.mat,
      product: line.product,
      subType: line.subType,
    }));
  },

  _migrateDraftList(list, processName) {
    if (!list || list.length === 0) return [emptyLineRow()];
    const { isDuanGangStyle } = processFlags(processName);
    const first = list[0];
    if (first && first.mat !== undefined) {
      return list.map((line) => ({
        rowKey: line.rowKey || `restored-${Math.random().toString(36).slice(2)}`,
        mat: line.mat || null,
        product: line.product || null,
        subType: line.subType || "",
        qty: line.qty != null ? String(line.qty) : "",
      }));
    }
    return list.map((it, i) => {
      const d = parseFloat(it.day_shift_qty) || 0;
      const n = parseFloat(it.night_shift_qty) || 0;
      const sum = d + n;
      const qty = sum > 0 ? sum.toFixed(3) : it.qty || "";
      const mat =
        it.y_material_name || it.y_material_spec || it.c_material_spec
          ? {
              uniqKey: `rg-draft-${i}`,
              material_id: (it.mat && it.mat.material_id) || it.material_id || 0,
              label:
                it.label ||
                [it.y_material_name, it.y_material_spec, it.c_material_spec]
                  .filter(Boolean)
                  .join(" / "),
              y_material_name: it.y_material_name || "",
              y_material_spec: it.y_material_spec || "",
              y_material_model: it.y_material_model || "",
              c_material_spec: it.c_material_spec || "",
              c_material_model: it.c_material_model || "",
            }
          : null;
      const product =
        !isDuanGangStyle &&
        (it.c_product_spec || it.c_product_model || it.c_material_model)
          ? {
              uniqKey: `fp-draft-${i}`,
              label:
                [it.c_product_spec, it.c_product_model].filter(Boolean).join(" / ") ||
                it.c_material_model,
              c_product_spec: it.c_product_spec || "",
              c_product_model: it.c_product_model || "",
            }
          : null;
      return {
        rowKey: `mig-${i}-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        mat,
        product,
        subType: "",
        qty: qty || "",
      };
    });
  },

  _saveDraft(lines) {
    const all = wx.getStorageSync("stocktake_draft") || {};
    const dateKey = this.data.stocktakeDate;
    if (!all[dateKey]) all[dateKey] = {};
    all[dateKey][this.data.processName] = this._serializeLinesForDraft(lines);
    wx.setStorageSync("stocktake_draft", all);
  },

  _parseCalcMaterialFields(it) {
    let category = (it.material_name || "").trim();
    let spec = (it.y_material_spec || "").trim();
    let model = (it.y_material_model || "").trim();
    const legacy = category.match(/^(.+)[｜|](.+)$/);
    if (legacy) {
      if (!spec) spec = (legacy[1] || "").trim();
      if (!model) model = (legacy[2] || "").trim();
      if (category !== "溯源" && category !== "凯亚") {
        category = "溯源";
      }
    }
    if (!category && spec) {
      category = "溯源";
    }
    let materialId = parseInt(it.material_id, 10) || 0;
    return { category, spec, model, materialId };
  },

  _autoFillLineKey(it, flags) {
    const { isDuanGangStyle, isDuanGangManualProduct, isSpecInputMode } = flags;
    if (isDuanGangStyle) {
      const p = this._parseCalcMaterialFields(it);
      return `rg|${p.spec}|${p.model}`;
    }
    if (isDuanGangManualProduct || isSpecInputMode) {
      const p = this._parseCalcMaterialFields(it);
      return `proc|${p.category}|${p.spec}|${p.model}|${it.c_material_spec || ""}`;
    }
    const p = this._parseCalcMaterialFields(it);
    return `full|${p.category}|${p.spec}|${p.model}|${it.c_material_spec || ""}|${it.c_product_spec || ""}`;
  },

  _resolveAutoFillMat(it, index) {
    const materials = this.data._allMaterials || [];
    const { category, spec, model, materialId } = this._parseCalcMaterialFields(it);

    if (!spec && !model && !category) {
      return null;
    }

    const bySpecModel = materials.find(
      (m) =>
        (m.y_material_spec || "") === spec &&
        (m.y_material_model || "") === model &&
        (!category || (m.y_material_name || "") === category)
    );
    if (bySpecModel) {
      return bySpecModel;
    }

    if (materialId > 0) {
      const byId = materials.find((m) => (m.material_id || 0) === materialId);
      if (
        byId &&
        (byId.y_material_spec || "") === spec &&
        (byId.y_material_model || "") === model
      ) {
        return byId;
      }
    }

    const yName = category || "溯源";
    return {
      uniqKey: `rg-auto-${index}-${spec}-${model}`,
      material_id: materialId,
      label: [yName, spec, model].filter(Boolean).join(" / "),
      y_material_name: yName,
      y_material_spec: spec,
      y_material_model: model,
    };
  },

  _applyAutoFillLines(calcItems) {
    const { isDuanGangManualProduct, isSpecInputMode, isDuanGangStyle } = this.data;
    const flags = { isDuanGangStyle, isDuanGangManualProduct, isSpecInputMode };
    const seen = new Set();
    const lines = calcItems
      .filter((it) => {
        if (!isDuanGangStyle) return true;
        const p = this._parseCalcMaterialFields(it);
        return !!(p.spec && p.model);
      })
      .filter((it) => {
        const key = this._autoFillLineKey(it, flags);
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      })
      .map((it, i) => {
      const mat = this._resolveAutoFillMat(it, i);

      let extra = {};
      if (isDuanGangManualProduct) {
        // 断钢：spec 填入 manualProduct
        extra = { manualProduct: it.c_material_spec || '' };
      } else if (isSpecInputMode) {
        // 穿孔+：spec 填入 specInput
        extra = { specInput: it.c_material_spec || '' };
      } else if (!isDuanGangStyle && it.c_material_spec) {
        extra = {
          product: {
            uniqKey: `fp-auto-${i}`,
            label: it.c_material_spec,
            c_product_spec: it.c_material_spec || '',
          }
        };
      }

      return {
        rowKey: `auto-${i}-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        mat,
        subType: '',
        qty: it.predicted_qty != null ? String(it.predicted_qty) : '',
        ...extra,
      };
    });

    this.setData({ lines });
    this._updateTotal(lines);
    this._saveDraft(lines);
    // 自动反查物料ID
    this._fixAutoFillMaterialIds();
  },

  triggerManualAutoFill() {
    const calcData = wx.getStorageSync('stocktake_auto_calc') || {};
    const calcItems = calcData[this.data.processName] || [];
    if (calcItems.length === 0) {
      wx.showToast({ title: '暂无最新的推算数据，请先在列表页进行智能推算', icon: 'none' });
      return;
    }

    wx.showModal({
      title: '确认自动预填',
      content: '此操作将使用服务器最新的智能推算数据覆盖您当前编写的内容（包括手动填写的草稿），是否确认？',
      success: (res) => {
        if (res.confirm) {
          this._applyAutoFillLines(calcItems);
          wx.showToast({ title: `已成功预填 ${calcItems.length} 条数据`, icon: 'success' });
        }
      }
    });
  },

  _loadDraft(dateKey, processName, autoFill) {
    // 获取最新的推算缓存并做局部展示控制
    const calcData = wx.getStorageSync('stocktake_auto_calc') || {};
    const calcItems = calcData[processName];
    const hasCalcData = Array.isArray(calcItems) && calcItems.length > 0;
    this.setData({ hasCalcData });

    const all = wx.getStorageSync("stocktake_draft") || {};
    const list = (all[dateKey] && all[dateKey][processName]) || [];
    const hasExistingDraft = list.length > 0;

    // 当需要强制自动预填，或当前完全没有本地草稿且有推算缓存时，执行预填
    if (hasCalcData && (autoFill || !hasExistingDraft)) {
      this._applyAutoFillLines(calcItems);
      wx.showToast({ title: `已预填 ${calcItems.length} 条推算数据`, icon: 'none', duration: 2000 });
      return;
    }

    // 加载本地已有草稿
    const restored =
      list.length === 0 ? [emptyLineRow()] : this._migrateDraftList(list, processName);
    this.setData({ lines: restored });
    this._updateTotal(restored);
  },

  _clearDraft() {
    const all = wx.getStorageSync("stocktake_draft") || {};
    const dateKey = this.data.stocktakeDate;
    if (all[dateKey]) {
      delete all[dateKey][this.data.processName];
      wx.setStorageSync("stocktake_draft", all);
    }
    // 提交后清除该工序的智能推算缓存，避免下次打开页面时自动填入旧数据
    const calcData = wx.getStorageSync("stocktake_auto_calc") || {};
    if (calcData[this.data.processName]) {
      delete calcData[this.data.processName];
      wx.setStorageSync("stocktake_auto_calc", calcData);
    }
  },
});
