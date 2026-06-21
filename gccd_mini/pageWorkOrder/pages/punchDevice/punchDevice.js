const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    devices: [],
    processes_code: '',
    processes_name: '穿孔',
  },

  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => this.setData({ statusBarHeight: res.statusBarHeight }),
    });
    this.setData({
      processes_code: options.processes_code || wx.getStorageSync('work_processes_code') || '',
    });
    this.loadDevices();
  },

  goBack() {
    wx.navigateBack({ fail: () => wx.switchTab({ url: '/pages/workbench/workbench' }) });
  },

  loadDevices() {
    util
      .request('admin/repair.Device/listByProcess', { processes_name: '穿孔', page: 0, limit: 0 })
      .then((res) => {
        if (res.data.code === 200) {
          const raw = res.data.data;
          const list = Array.isArray(raw) ? raw : (raw?.list || []);
          this.setData({ devices: list });
        } else {
          Toast.fail(res.data.msg || '加载设备失败');
        }
      });
  },

  selectDevice(e) {
    const code = e.currentTarget.dataset.code;
    const name = e.currentTarget.dataset.name || code;
    wx.setStorageSync('last_punch_device_code', code);
    wx.setStorageSync('last_punch_device_name', name);
    wx.navigateTo({
      url: `/pageWorkOrder/pages/processTaskList/processTaskList?processes_code=${this.data.processes_code}&processes_name=穿孔&device_code=${encodeURIComponent(code)}&device_name=${encodeURIComponent(name)}`,
    });
  },
});
