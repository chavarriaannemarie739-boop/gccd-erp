const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    activeTab: '0',
    keyword: '',
    list: [],
    page: 1,
    limit: 20,
    loading: false,
    noMore: false,
  },

  onLoad() {
    wx.getSystemInfo({
      success: (res) => this.setData({ statusBarHeight: res.statusBarHeight }),
    });
    this.fetchList(true);
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
    const keyword = e.detail.value || '';
    this.setData({ keyword });
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

  onKeywordClear() {
    if (this._searchTimer) {
      clearTimeout(this._searchTimer);
    }
    this.setData({ keyword: '' });
    this.fetchList(true);
  },

  loadMore() {
    if (!this.data.noMore && !this.data.loading) {
      this.fetchList(false);
    }
  },

  fetchList(reset) {
    const page = reset ? 1 : this.data.page + 1;
    this.setData({ loading: true });
    const payload = {
      status: parseInt(this.data.activeTab, 10),
      page,
      limit: this.data.limit,
    };
    const keyword = (this.data.keyword || '').trim();
    if (keyword !== '') {
      payload.keyword = keyword;
    }
    util
      .request('admin/production.DuangangMerge/list', payload)
      .then((res) => {
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
        this.setData({ loading: false });
        Toast.fail('网络错误');
      });
  },

  goReport(e) {
    if (this.data.activeTab !== '0') return;
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pageWorkOrder/pages/duangangAdd/duangangAdd?merge_group_id=${id}`,
    });
  },
});
