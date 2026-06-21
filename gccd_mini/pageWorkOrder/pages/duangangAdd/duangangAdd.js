const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    merge_group_id: 0,
    group: {},
    report_num: '',
    device_code: '',
    device_name: '',
    deviceList: [],
    showDevicePopup: false,
    class: 0,
    submitting: false,
  },

  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => this.setData({ statusBarHeight: res.statusBarHeight }),
    });
    const mergeGroupId = parseInt(options.merge_group_id || '0', 10);
    this.setData({ merge_group_id: mergeGroupId });
    this.loadGroup();
    this.loadDevices();
  },

  goBack() {
    wx.navigateBack();
  },

  loadGroup() {
    util
      .request('admin/production.DuangangMerge/info', { merge_group_id: this.data.merge_group_id })
      .then((res) => {
        if (res.data.code === 200) {
          this.setData({ group: res.data.data || {} });
        }
      });
  },

  loadDevices() {
    util
      .request('admin/repair.Device/listByProcess', { processes_name: '断钢', page: 0, limit: 0 })
      .then((res) => {
        if (res.data.code === 200) {
          const raw = res.data.data;
          const list = Array.isArray(raw) ? raw : (raw?.list || []);
          this.setData({
            deviceList: list,
          });
        }
      })
      .catch(() => {
        Toast.fail('获取设备列表失败');
      });
  },

  onReportInput(e) {
    this.setData({ report_num: e.detail.value });
  },

  showDevicePicker() {
    if (!this.data.deviceList.length) {
      Toast.fail('暂无设备');
      return;
    }
    this.setData({ showDevicePopup: true });
  },

  hideDevicePicker() {
    this.setData({ showDevicePopup: false });
  },

  onDeviceSelect(e) {
    const idx = parseInt(e.currentTarget.dataset.idx, 10);
    const dev = this.data.deviceList[idx];
    if (!dev) {
      return;
    }
    this.setData({
      device_code: dev.device_code,
      device_name: dev.device_name || dev.device_code,
      showDevicePopup: false,
    });
  },

  onClassTap(e) {
    this.setData({ class: parseInt(e.currentTarget.dataset.val, 10) });
  },

  submit() {
    if (this.data.submitting) return;
    const num = parseFloat(this.data.report_num);
    if (!num || num <= 0) {
      Toast.fail('请输入报工吨数');
      return;
    }
    if (!this.data.device_code) {
      Toast.fail('请选择设备');
      return;
    }
    this.setData({ submitting: true });
    util
      .request(
        'admin/production.DuangangMerge/report',
        {
          merge_group_id: this.data.merge_group_id,
          report_num: num,
          device_code: this.data.device_code,
          device_name: this.data.device_name,
          class: this.data.class,
          defective_num: [],
          is_replace: 0,
        },
        'POST',
      )
      .then((res) => {
        this.setData({ submitting: false });
        if (res.data.code === 200) {
          Toast.success('报工成功');
          setTimeout(() => wx.navigateBack(), 600);
        } else {
          Toast.fail(res.data.msg || '报工失败');
        }
      })
      .catch(() => {
        this.setData({ submitting: false });
        Toast.fail('网络错误');
      });
  },
});
