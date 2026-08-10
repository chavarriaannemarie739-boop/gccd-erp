const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

// [AI-2026-08] 穿孔及后续：圆钢四字段 + 成品规格/型号。
Page({
  data: {
    statusBarHeight: 0,
    processes_code: '',
    processes_name: '',
    device_code: '',
    device_name: '',
    y_material_name: '',
    y_material_manufacturer: '',
    y_material_spec: '',
    y_material_model: '',
    c_material_spec: '',
    c_material_model: '',
    list: [],
    page: 1,
    limit: 20,
    loading: false,
    noMore: false,
    // 蓝牙打印相关
    showPrintPopup: false,
    printPlanId: 0,
    printMaterialId: 0,
  },

  onLoad(options) {
    // 只在 onShow 拉数，避免连打两次
    wx.getSystemInfo({
      success: (res) => this.setData({ statusBarHeight: res.statusBarHeight }),
    });
    this.setData({
      processes_code: options.processes_code || '',
      processes_name: decodeURIComponent(options.processes_name || ''),
      device_code: decodeURIComponent(options.device_code || ''),
      device_name: decodeURIComponent(options.device_name || ''),
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
    wx.navigateBack();
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
      c_material_spec: '',
      c_material_model: '',
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
      processes_code: this.data.processes_code,
      processes_name: this.data.processes_name,
      y_material_name: this.data.y_material_name,
      y_material_manufacturer: this.data.y_material_manufacturer,
      y_material_spec: this.data.y_material_spec,
      y_material_model: this.data.y_material_model,
      c_material_spec: this.data.c_material_spec,
      c_material_model: this.data.c_material_model,
      page,
      limit: this.data.limit,
    };
    if (this.data.device_code) {
      payload.device_code = this.data.device_code;
    }
    util
      .request('admin/production.ProductTask/listForMini', payload)
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

  // [AI-2026-06] 打开打印弹出层
  onPrintTap(e) {
    const planId = e.currentTarget.dataset.planId;
    const materialId = e.currentTarget.dataset.materialId;

    if (!planId) {
      wx.showToast({
        title: '工单未关联生产计划，无法打印',
        icon: 'none',
        duration: 2000
      });
      return;
    }

    this.setData({
      showPrintPopup: true,
      printPlanId: Number(planId),
      printMaterialId: materialId ? Number(materialId) : 0,
    });
  },

  // [AI-2026-06] 关闭打印弹出层
  closePrintPopup() {
    this.setData({
      showPrintPopup: false,
      printPlanId: 0,
      printMaterialId: 0,
    });
  },
});
