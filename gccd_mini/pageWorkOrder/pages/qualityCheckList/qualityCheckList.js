const util = require('../../../utils/util.js');

Page({
  data: {
    statusBarHeight: 0,
    activeTab: 0,

    // Tab1：今日质检
    checkDate: '',
    processes: [
      { key: '穿孔', index: 1, count: 0 },
      { key: '矫直', index: 2, count: 0 },
      { key: '酸洗', index: 3, count: 0 },
    ],

    // Tab2：质检记录
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
    this.setData({ checkDate: this._calcYesterdayDate() });
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
    if (tab === 1) this._loadHistory();
  },

  goAdd(e) {
    const { key } = e.currentTarget.dataset;
    wx.navigateTo({
      url: `/pageWorkOrder/pages/qualityCheckAdd/qualityCheckAdd?process=${key}&date=${this.data.checkDate}`,
    });
  },

  toggleDay(e) {
    const idx = e.currentTarget.dataset.idx;
    const key = `historyDays[${idx}].expanded`;
    this.setData({ [key]: !this.data.historyDays[idx].expanded });
  },

  toggleProcess(e) {
    const { dayIdx, procIdx } = e.currentTarget.dataset;
    const key = `historyDays[${dayIdx}].groups[${procIdx}].expanded`;
    this.setData({ [key]: !this.data.historyDays[dayIdx].groups[procIdx].expanded });
  },

  _calcYesterdayDate() {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  },

  _refreshStatus() {
    const saved = wx.getStorageSync('qc_draft') || {};
    const dateKey = this.data.checkDate;
    const todayDraft = saved[dateKey] || {};

    const processes = this.data.processes.map((p) => {
      const records = todayDraft[p.key] || [];
      return { ...p, count: records.length };
    });
    this.setData({ processes });
  },

  // 从服务器接口加载历史质检记录
  _loadHistory() {
    this.setData({ historyLoading: true, historyEmpty: false });
    util.request(
      'admin/production.QualityCheckRecord/list',
      { page: 1, limit: 100 },
      'POST'
    )
      .then((res) => {
        const list = (res.data && res.data.data && res.data.data.list) || [];
        if (list.length === 0) {
          this.setData({ historyDays: [], historyEmpty: true });
          return;
        }

        // 按日期分组
        const dateMap = {};
        list.forEach((record) => {
          const dateStr = record.check_date || '';
          if (!dateMap[dateStr]) dateMap[dateStr] = [];
          dateMap[dateStr].push(record);
        });

        const historyDays = Object.keys(dateMap)
          .sort()
          .reverse()
          .map((dateStr) => {
            const dayRecords = dateMap[dateStr];
            let totalDefects = 0;
            let totalItems = 0;

            const groups = dayRecords.map((record) => {
              const items = record.items || [];
              let groupDefects = 0;
              items.forEach((item) => {
                (item.defects || []).forEach((d) => {
                  groupDefects += (Number(d.day_qty) || 0) + (Number(d.night_qty) || 0);
                });
              });
              totalDefects += groupDefects;
              totalItems += items.length;
              return {
                name: record.process_name || '',
                items: items.map((item) => ({
                  c_material_name: item.c_material_name || '',
                  c_material_spec: item.c_material_spec || '',
                  c_material_model: item.c_material_model || '',
                  defective_items: (item.defects || []).map((d) => ({
                    typeText: d.defect_text || '',
                    day_qty: d.day_qty || '0',
                    night_qty: d.night_qty || '0',
                  })),
                })),
                defectCount: groupDefects,
                expanded: false,
                remark: record.remark || '',
              };
            });

            return {
              date: dateStr,
              weekday: this._getWeekday(dateStr),
              processCount: groups.length,
              itemCount: totalItems,
              totalDefects,
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
