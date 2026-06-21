const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    processes_code: '',
    processes_name: '',
    device_code: '',
    device_name: '',
    keyword: '',
    list: [],
    page: 1,
    limit: 20,
    loading: false,
    noMore: false,
  },

  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => this.setData({ statusBarHeight: res.statusBarHeight }),
    });
    this.setData({
      processes_code: options.processes_code || '',
      processes_name: decodeURIComponent(options.processes_name || ''),
      device_code: decodeURIComponent(options.device_code || ''),
      device_name: decodeURIComponent(options.device_name || ''),
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
    wx.navigateBack();
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
      processes_code: this.data.processes_code,
      processes_name: this.data.processes_name,
      page,
      limit: this.data.limit,
    };
    if (this.data.device_code) {
      payload.device_code = this.data.device_code;
    }
    const keyword = (this.data.keyword || '').trim();
    if (keyword !== '') {
      payload.keyword = keyword;
    }
    util
      .request('admin/production.ProductTask/listForMini', payload)
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

  goAdd(e) {
    const id = e.currentTarget.dataset.id;
    if (!id) {
      Toast.fail('任务信息异常');
      return;
    }
    let url = `/pageWorkOrder/pages/workOrderAdd/workOrderAdd?id=${id}&processes_name=${encodeURIComponent(this.data.processes_name)}`;
    if (this.data.processes_code) {
      url += `&process_code=${encodeURIComponent(this.data.processes_code)}`;
    }
    if (this.data.device_code) {
      url += `&locked_device_code=${encodeURIComponent(this.data.device_code)}&locked_device_name=${encodeURIComponent(this.data.device_name)}`;
    }
    wx.navigateTo({ url });
  },
});
