const util = require('../../utils/util.js');
const workContextUtil = require('../../utils/workContext.js');
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    reportTitle: '生产报工',
    reportDesc: '加载中…',
    reportUrl: '',
    showRepairForm: false,
    repairDeviceCode: '',
    showPlanInfo: false,
    planInfo: null,
  },

  onLoad() {
    const windowInfo = wx.getWindowInfo ? wx.getWindowInfo() : wx.getSystemInfoSync();
    this.setData({
      statusBarHeight: windowInfo.statusBarHeight || 0,
    });
  },

  onShow() {
    this.loadWorkContext();
  },

  applyEntry(entry) {
    this.setData({
      reportTitle: entry.reportTitle,
      reportDesc: entry.reportDesc,
      reportUrl: entry.reportUrl,
    });
  },

  loadWorkContext() {
    const localEntry = workContextUtil.applyWorkContext(
      workContextUtil.resolveWorkContextFromStorage(),
    );
    this.applyEntry(localEntry);

    util
      .request('admin/production.ProductTask/workContext', {}, 'POST', { silentFail: true })
      .then((res) => {
        if (res.data.code !== 200) {
          return;
        }
        const entry = workContextUtil.applyWorkContext(res.data.data || {});
        this.applyEntry(entry);
      })
      .catch(() => {});
  },

  goReport() {
    const url = this.data.reportUrl || '/pages/workOrder/workOrder';
    if (url.indexOf('/pages/') === 0 && url.indexOf('workbench') === -1) {
      wx.navigateTo({ url, fail: () => wx.switchTab({ url }) });
    } else {
      wx.navigateTo({ url });
    }
  },

  onScanSuccess(e) {
    const payload = (e.detail && e.detail.result) || '';
    if (!payload) {
      return;
    }
    wx.showLoading({ title: '识别中…', mask: true });
    util
      .request('admin/production.ProductTask/flowCardScan', { payload }, 'POST')
      .then((res) => {
        wx.hideLoading();
        if (res.data.code !== 200) {
          Toast.fail(res.data.msg || '扫码失败');
          return;
        }
        this.handleScanResult(res.data.data || {});
      })
      .catch(() => {
        wx.hideLoading();
        Toast.fail('网络错误');
      });
  },

  handleScanResult(data) {
    if (data.scan_type === 'device') {
      this.setData({
        repairDeviceCode: data.device_code || '',
        showRepairForm: true,
      });
      return;
    }

    if (data.finished) {
      Toast('本计划报工已完成');
      return;
    }

    if (data.can_report && data.task_id) {
      const processName = data.processes_name || '';
      const processCode = data.process_code || '';
      let url = `/pageWorkOrder/pages/workOrderAdd/workOrderAdd?id=${data.task_id}&process_code=${encodeURIComponent(processCode)}&processes_name=${encodeURIComponent(processName)}`;
      if (processName === '穿孔' && data.device_code) {
        url += `&locked_device_code=${encodeURIComponent(data.device_code)}&locked_device_name=${encodeURIComponent(data.device_name || '')}`;
      }
      wx.navigateTo({ url });
      return;
    }

    this.setData({
      planInfo: data.plan_info || null,
      showPlanInfo: true,
    });
  },

  onRepairClose() {
    this.setData({ showRepairForm: false, repairDeviceCode: '' });
  },

  onRepairSuccess() {
    this.setData({ showRepairForm: false, repairDeviceCode: '' });
  },

  onPlanInfoClose() {
    this.setData({ showPlanInfo: false, planInfo: null });
  },
});
