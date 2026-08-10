const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

// [AI-2026-08] 断钢任务按圆钢四字段筛选。
Page({
  data: {
    statusBarHeight: 0,
    activeTab: '0',
    y_material_name: '',
    y_material_manufacturer: '',
    y_material_spec: '',
    y_material_model: '',
    list: [],
    page: 1,
    limit: 20,
    loading: false,
    noMore: false,
  },

  onLoad() {
    // [AI-2026-08] 只在 onShow 拉数，避免 onLoad+onShow 连打两次接口
    wx.getSystemInfo({
      success: (res) => this.setData({ statusBarHeight: res.statusBarHeight }),
    });
  },

  onShow() {
    this.fetchList(true);
  },

  onUnload() {
    if (this._searchTimer) {
      clearTimeout(this._searchTimer);
    }
  },

  goBack() {
    wx.navigateBack({ fail: () => wx.switchTab({ url: '/pages/workbench/workbench' }) });
  },

  onTabChange(e) {
    this.setData({ activeTab: e.detail.name, page: 1, noMore: false });
    this.fetchList(true);
  },

  onKeywordInput(e) {
    const field = e.currentTarget.dataset.field;
    const value = e.detail.value || '';
    this.setData({ [field]: value });
    if (this._searchTimer) {
      clearTimeout(this._searchTimer);
    }
    this._searchTimer = setTimeout(() => {
      this.fetchList(true);
    }, 350);
  },

  onKeywordConfirm() {
    if (this._searchTimer) {
      clearTimeout(this._searchTimer);
    }
    this.fetchList(true);
  },

  onFilterReset() {
    if (this._searchTimer) {
      clearTimeout(this._searchTimer);
    }
    this.setData({
      y_material_name: '',
      y_material_manufacturer: '',
      y_material_spec: '',
      y_material_model: '',
    });
    this.fetchList(true);
  },

  loadMore() {
    if (!this.data.noMore && !this.data.loading) {
      this.fetchList(false);
    }
  },

  fetchList(reset) {
    const page = reset ? 1 : this.data.page + 1;
    const seq = (this._fetchSeq || 0) + 1;
    this._fetchSeq = seq;
    this.setData({ loading: true });
    const payload = {
      status: parseInt(this.data.activeTab, 10),
      y_material_name: this.data.y_material_name,
      y_material_manufacturer: this.data.y_material_manufacturer,
      y_material_spec: this.data.y_material_spec,
      y_material_model: this.data.y_material_model,
      page,
      limit: this.data.limit,
    };
    util
      .request('admin/production.DuangangMerge/list', payload)
      .then((res) => {
        if (seq !== this._fetchSeq) {
          return;
        }
        if (res.data.code !== 200) {
          Toast.fail(res.data.msg || '加载失败');
          this.setData({ loading: false });
          return;
        }
        const incoming = res.data.data.list || [];
        const list = reset ? incoming : this.data.list.concat(incoming);
        this.setData({
          list,
          page,
          loading: false,
          noMore: incoming.length < this.data.limit,
        });
      })
      .catch(() => {
        if (seq !== this._fetchSeq) {
          return;
        }
        this.setData({ loading: false });
        Toast.fail('网络错误');
      });
  },

  // [AI-2026-08] 达标完成(≥97%)后未满计划仍可补报
  goReport(e) {
    const item = this.data.list.find((row) => String(row.id) === String(e.currentTarget.dataset.id));
    const remain = item ? Number(item.remain_total || 0) : 0;
    if (remain <= 0.0001) {
      return;
    }
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pageWorkOrder/pages/duangangAdd/duangangAdd?merge_group_id=${id}`,
    });
  },
});
