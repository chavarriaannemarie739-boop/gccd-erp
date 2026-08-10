const util = require('../../../utils/util.js');

// [AI-2026-08] 盘点反查优先 material_id，无 ID 时按圆钢四字段匹配。
Page({
  data: {
    statusBarHeight: 0,
    activeTab: 0,

    // ---- Tab1：今日填报 ----
    stocktakeDate: '',
    processes: [
      { key: '圆钢', index: 1, count: 0 },
      { key: '断钢', index: 2, count: 0 },
      { key: '穿孔', index: 3, count: 0 },
      { key: '球化', index: 4, count: 0 },
      { key: '矫直', index: 5, count: 0 },
      { key: '酸洗', index: 6, count: 0 },
      { key: '次成品', index: 7, count: 0 },
      { key: '半成品', index: 8, count: 0 },
    ],

    // ---- 智能推算 ----
    calcLoading: false,
    calcTime: '',

    // ---- Tab2：盘点记录 ----
    historyDays: [],
    historyEmpty: false,
    historyLoading: false,
  },

  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({ statusBarHeight: res.statusBarHeight });
      },
    });
    this.setData({ stocktakeDate: this._calcYesterdayDate() });
  },

  onShow() {
    this._refreshStatus();
    if (this.data.activeTab === 1) {
      this._loadHistory();
    }
  },

  navigateBack() {
    wx.navigateBack({ delta: 1 });
  },

  onTabChange(e) {
    const tab = e.detail.name;
    this.setData({ activeTab: tab });
    if (tab === 1) {
      this._loadHistory();
    }
  },

  goAdd(e) {
    const { key } = e.currentTarget.dataset;
    const qProcess = encodeURIComponent(key || '');
    const qDate = encodeURIComponent(this.data.stocktakeDate || '');
    wx.navigateTo({
      url: `/pageWorkOrder/pages/stocktakeAdd/stocktakeAdd?process=${qProcess}&date=${qDate}`,
    });
  },

  // 智能推算（calc_date 必须为「今天」：后端统计区间 = 期初次日 ~ calc_date 前一日；
  // 若误传盘点日「昨天」，则昨天盘点的品种会出现 期初次日 > 统计终点，流水全部为 0）
  onAutoCalc() {
    const self = this;
    const calcDate = self._calcTodayDate();
    const statEndDate = self._calcYesterdayDate();
    wx.showLoading({ title: '推算中...', mask: true });
    self.setData({ calcLoading: true });

    util.request('admin/production.Inventory/autoCalc', { calc_date: calcDate }, 'POST')
      .then(res => {
        wx.hideLoading();
        self.setData({ calcLoading: false });

        if (res.data.code !== 200 || !Array.isArray(res.data.data)) {
          wx.showToast({ title: res.data.msg || '推算失败', icon: 'none' });
          return;
        }

        const items = res.data.data;
        if (items.length === 0) {
          wx.showToast({ title: '无可推算的数据', icon: 'none' });
          return;
        }

        // 按工序分组
        const groupMap = {};
        items.forEach(it => {
          const pn = it.process_name || '未知';
          if (!groupMap[pn]) groupMap[pn] = [];
          groupMap[pn].push(it);
        });

        // 将推算结果存入缓存，供填报页自动预填
        wx.setStorageSync('stocktake_auto_calc', groupMap);

        // 构建弹窗内容
        let msg = '';
        const procNames = Object.keys(groupMap);
        procNames.forEach(pn => {
          msg += `\n【${pn}】\n`;
          groupMap[pn].forEach(it => {
            const specVal = it.c_material_spec || '';
            const label = [it.material_name, it.y_material_manufacturer, it.y_material_spec, it.y_material_model, specVal ? `(${specVal})` : ''].filter(Boolean).join(' / ');
            const newTag = it.is_new ? '🆕' : '';
            const qtyStr = it.is_negative ? `⚠ ${it.predicted_qty} 吨（异常负数）` : `${it.predicted_qty} 吨`;
            msg += `  ${newTag}${label}: ${qtyStr}\n`;
          });
        });

        const now = new Date();
        const timeStr = `${now.getHours()}:${String(now.getMinutes()).padStart(2, '0')}`;
        self.setData({ calcTime: timeStr });

        wx.showModal({
          title: '智能推算结果',
          content: `推算日 ${calcDate}，各品种以最后手动盘点为期初，流水统计至 ${statEndDate}：\n${msg}`,
          showCancel: true,
          cancelText: '关闭',
          confirmText: '去填报（自动预填）',
          success: (res) => {
            if (res.confirm) {
              // 跳转到第一个有结果的工序，带autoFill标记
              const firstProc = procNames[0];
              if (firstProc) {
                const qProcess = encodeURIComponent(firstProc);
                const qDate = encodeURIComponent(self.data.stocktakeDate);
                wx.navigateTo({
                  url: `/pageWorkOrder/pages/stocktakeAdd/stocktakeAdd?process=${qProcess}&date=${qDate}&autoFill=1`,
                });
              }
            }
          }
        });
      })
      .catch(err => {
        wx.hideLoading();
        self.setData({ calcLoading: false });
        // util.request 已经弹了错误Toast，这里不再重复弹
        console.error('autoCalc error:', err);
      });
  },

  // 展开/收起某天
  toggleDay(e) {
    const idx = e.currentTarget.dataset.idx;
    const key = `historyDays[${idx}].expanded`;
    this.setData({ [key]: !this.data.historyDays[idx].expanded });
  },

  // 展开/收起某天内的工序
  toggleProcess(e) {
    const { dayIdx, procIdx } = e.currentTarget.dataset;
    const key = `historyDays[${dayIdx}].groups[${procIdx}].expanded`;
    this.setData({
      [key]: !this.data.historyDays[dayIdx].groups[procIdx].expanded,
    });
  },

  // ---- 私有方法 ----

  _calcTodayDate() {
    return this._fmtDate(new Date());
  },

  _calcYesterdayDate() {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return this._fmtDate(d);
  },

  _fmtDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  },

  _refreshStatus() {
    const saved = wx.getStorageSync('stocktake_draft') || {};
    const dateKey = this.data.stocktakeDate;
    const todayDraft = saved[dateKey] || {};

    const processes = this.data.processes.map((p) => {
      const raw = todayDraft[p.key];
      let filled = 0;
      // 次成品：v === 2，ciBlocks结构
      if (raw && raw.v === 2 && Array.isArray(raw.ciBlocks)) {
        raw.ciBlocks.forEach((block) => {
          (block.lines || []).forEach((line) => {
            const q = parseFloat(line.qty) || 0;
            if (q > 0) filled++;
          });
        });
      }
      // 半成品：v === 3，banBlocks结构
      else if (raw && raw.v === 3 && Array.isArray(raw.banBlocks)) {
        raw.banBlocks.forEach((block) => {
          (block.lines || []).forEach((line) => {
            const q = parseFloat(line.qty) || 0;
            if (q > 0) filled++;
          });
        });
      }
      // 其他工序：旧版数组结构
      else if (Array.isArray(raw)) {
        raw.forEach((line) => {
          const q = parseFloat(line.qty) || 0;
          if (q > 0) filled++;
        });
      }
      return { ...p, count: filled };
    });
    this.setData({ processes });
  },

  // 从服务器接口加载历史盘点记录
  // 同时加载物料档案，用于反查数据库中缺失的圆钢型号
  _loadHistory() {
    this.setData({ historyLoading: true, historyEmpty: false });

    // 并行请求：盘点记录 + 圆钢物料档案
    const inventoryReq = util.request(
      'admin/production.Inventory/list',
      { page: 1, limit: 200 },
      'POST'
    );
    const materialReq = util.request(
      'admin/production.Material/list',
      { page: 1, limit: 2000, material_property: '圆钢' },
      'POST'
    );

    Promise.all([inventoryReq, materialReq])
      .then(([invRes, matRes]) => {
        const list = (invRes.data && invRes.data.data && invRes.data.data.list) || [];
        if (list.length === 0) {
          this.setData({ historyDays: [], historyEmpty: true });
          return;
        }

        // 圆钢反查优先 material_id；无 ID 时四字段必须全部一致，避免跨厂商串档。
        const matList = (matRes.data && matRes.data.data && matRes.data.data.list) || [];
        const matById = new Map();
        const matByFields = new Map();
        matList.forEach((m) => {
          const materialId = parseInt(m.id || m.material_id, 10) || 0;
          const name = (m.material_name || '').trim();
          const manufacturer = (m.manufacturer || '').trim();
          const spec = (m.material_spec || '').trim();
          const model = (m.material_model || '').trim();
          const archive = {
            material_id: materialId,
            material_name: name,
            manufacturer,
            material_spec: spec,
            material_model: model,
          };
          if (materialId > 0) {
            matById.set(materialId, archive);
          }
          if (name || manufacturer || spec || model) {
            matByFields.set(`${name}|${manufacturer}|${spec}|${model}`, archive);
          }
        });

        // 按日期分组
        const dateMap = {};
        list.forEach((record) => {
          const dateStr = record.inventory_date || record.create_time?.slice(0, 10) || '';
          if (!dateStr) return;
          if (!dateMap[dateStr]) dateMap[dateStr] = {};
          const pName = record.process_name || '未知工序';
          if (!dateMap[dateStr][pName]) dateMap[dateStr][pName] = [];
          dateMap[dateStr][pName].push(record);
        });

        const historyDays = Object.keys(dateMap)
          .sort()
          .reverse()
          .map((dateStr) => {
            const dayData = dateMap[dateStr];
            let totalQty = 0;
            let totalItems = 0;
            const filledProcesses = [];

            const groups = Object.keys(dayData).map((pName) => {
              const rawItems = dayData[pName];
              let groupQty = 0;
              const items = rawItems.map((it) => {
                const dayVal = parseFloat(it.day_shift_qty || it.day_shift_tonnage) || 0;
                const nightVal = parseFloat(it.night_shift_qty || it.night_shift_tonnage) || 0;
                const qty = dayVal + nightVal;
                groupQty += qty;

                const materialId = parseInt(it.material_id, 10) || 0;
                const name = (it.y_material_name || it.material_name || '').trim();
                const manufacturer = (it.y_material_manufacturer || '').trim();
                const materialSpec = (it.y_material_spec || '').trim();
                const materialModel = (it.y_material_model || '').trim();
                const archive = materialId > 0
                  ? matById.get(materialId)
                  : matByFields.get(`${name}|${manufacturer}|${materialSpec}|${materialModel}`);

                return {
                  ...it,
                  qty,
                  y_material_name: name || archive?.material_name || '',
                  y_material_manufacturer: manufacturer || archive?.manufacturer || '',
                  y_material_spec: materialSpec || archive?.material_spec || '',
                  y_material_model: materialModel || archive?.material_model || '',
                };
              });
              totalQty += groupQty;
              totalItems += items.length;
              filledProcesses.push(pName);
              return {
                name: pName,
                items,
                subtotalQty: groupQty.toFixed(3),
                expanded: false,
              };
            });

            return {
              date: dateStr,
              weekday: this._getWeekday(dateStr),
              processCount: filledProcesses.length,
              itemCount: totalItems,
              totalQty: totalQty.toFixed(3),
              groups,
              expanded: false,
            };
          });

        this.setData({ historyDays, historyEmpty: historyDays.length === 0 });
      })
      .catch(() => {
        wx.showToast({ title: '加载历史记录失败', icon: 'none' });
        this.setData({ historyDays: [], historyEmpty: true });
      })
      .finally(() => {
        this.setData({ historyLoading: false });
      });
  },

  _getWeekday(dateStr) {
    const days = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
    const d = new Date(dateStr.replace(/-/g, '/'));
    return days[d.getDay()] || '';
  },
});
