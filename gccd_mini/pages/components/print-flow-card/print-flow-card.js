/**
 * 打印流转卡组件 JS
 * [AI-2026-06] 适配得力 DL-888TW 蓝牙标签打印
 */

const ble = require('../../../utils/bluetooth.js');
const tspl = require('../../../utils/tsplBuilder.js');
const util = require('../../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

Component({
  properties: {
    show: {
      type: Boolean,
      value: false,
      observer(newVal) {
        if (newVal) {
          this.onShowPopup();
        } else {
          this.onHidePopup();
        }
      }
    },
    planId: {
      type: Number,
      value: 0,
      observer(newVal) {
        if (newVal && this.properties.show) {
          this.loadOptions();
        }
      }
    },
    // 可选传入默认的 materialId
    materialId: {
      type: Number,
      value: 0
    }
  },

  data: {
    printerConnected: false,
    printerName: '',
    connecting: false,
    scanning: false,
    showDeviceList: false,
    devices: [],

    options: null,
    needPickDevice: false,
    deviceOptions: [],
    
    selectedMaterialId: 0,
    selectedDeviceCode: '',
    labelData: null,
    printing: false,
    copies: 1
  },

  lifetimes: {
    attached() {
      // 检查蓝牙当前连接状态
      this.updateConnectionStatus();
    },
    detached() {
      // 避免退出页面时蓝牙没关闭导致占用
      ble.stopScan();
    }
  },

  methods: {
    // 更新连接状态
    updateConnectionStatus() {
      const connected = ble.isConnected();
      const saved = ble.getSavedDevice();
      this.setData({
        printerConnected: connected,
        printerName: connected ? saved.name : ''
      });
    },

    // 弹出层展示
    onShowPopup() {
      this.updateConnectionStatus();
      if (!ble.isConnected()) {
        // 尝试自动重连上次保存的设备
        this.setData({ connecting: true });
        ble.autoReconnect()
          .then(() => {
            this.setData({ connecting: false });
            this.updateConnectionStatus();
            Toast({
              context: this,
              selector: '#pfc-toast',
              type: 'success',
              message: '打印机已就绪',
              duration: 1500
            });
          })
          .catch(() => {
            this.setData({ connecting: false });
            // 如果自动重连失败，不报错，让用户手动点连接
          });
      }

      if (this.properties.planId) {
        this.loadOptions();
      }
    },

    // 弹出层隐藏
    onHidePopup() {
      ble.stopScan();
      this.setData({
        scanning: false,
        showDeviceList: false,
        devices: []
      });
    },

    // 关闭弹出层
    onClose() {
      this.triggerEvent('close');
    },

    // 加载打印配置项
    loadOptions() {
      const planId = this.properties.planId;
      if (!planId) return;

      this.setData({
        labelData: null,
        options: null,
        selectedMaterialId: this.properties.materialId || 0,
        selectedDeviceCode: '',
        copies: 1
      });

      util.request('admin/production.Production/flowCardPrintOptions', { id: planId }, 'POST')
        .then((res) => {
          if (res.data.code === 200) {
            const data = res.data.data || {};
            this.setData({
              options: data,
              needPickDevice: !!data.need_pick_device,
              deviceOptions: data.devices || []
            });

            // 自动匹配 materialId
            if (data.materials && data.materials.length > 0) {
              // 如果没有传入默认的 materialId，或者默认的值不在可选列表里，选第一个
              const exists = data.materials.some(m => m.material_id === this.data.selectedMaterialId);
              if (!this.data.selectedMaterialId || !exists) {
                this.setData({
                  selectedMaterialId: data.materials[0].material_id
                });
              }
            }

            // 自动匹配 deviceCode
            if (data.devices && data.devices.length > 0) {
              if (data.devices.length === 1) {
                this.setData({
                  selectedDeviceCode: data.devices[0].device_code
                });
              }
            }

            this.loadLabelData();
          } else {
            Toast({
              context: this,
              selector: '#pfc-toast',
              type: 'fail',
              message: res.data.msg || '加载打印选项失败'
            });
          }
        })
        .catch((err) => {
          console.error(err);
          Toast({
            context: this,
            selector: '#pfc-toast',
            type: 'fail',
            message: '网络异常'
          });
        });
    },

    // 选择穿孔设备
    onPickDevice(e) {
      const code = e.currentTarget.dataset.code;
      this.setData({
        selectedDeviceCode: code
      });
      this.loadLabelData();
    },

    // 加载标签预览数据
    loadLabelData() {
      const planId = this.properties.planId;
      const materialId = this.data.selectedMaterialId;
      const deviceCode = this.data.selectedDeviceCode;

      if (!planId) return;
      if (this.data.options && this.data.options.need_pick_material && !materialId) return;
      if (this.data.needPickDevice && !deviceCode) return;

      util.request('admin/production.Production/flowCardLabel', {
        id: planId,
        material_id: materialId,
        device_code: deviceCode
      }, 'POST')
        .then((res) => {
          if (res.data.code === 200) {
            const labelData = res.data.data || {};
            // [AI-2026-08] 预览与服务端/客户端兜底 TSPL 共用圆钢四字段格式
            const cSpec = (labelData.c_material_spec || '').trim();
            const cModel = (labelData.c_material_model || '').trim();
            const finished = (cSpec && cModel) ? `${cSpec}×${cModel}` : `${cSpec}${cModel}`;
            const previewLines = [];
            let line1 = labelData.production_plan_name || '';
            if (labelData.device_name) {
              line1 = `${line1} ${labelData.device_name}`.trim();
            }
            if (line1) previewLines.push(line1);
            const roundSteelLines = tspl.buildRoundSteelLines(
              labelData,
              labelData.plan_num || 0
            );
            previewLines.push(...roundSteelLines);
            if (finished) {
              const body = finished.replace(/×/g, 'x');
              previewLines.push(`品:${body}`);
            }
            labelData.previewLines = previewLines;

            this.setData({
              labelData: labelData
            });
          } else {
            Toast({
              context: this,
              selector: '#pfc-toast',
              type: 'fail',
              message: res.data.msg || '获取标签数据失败'
            });
          }
        })
        .catch((err) => {
          console.error(err);
        });
    },

    // 点击打印机状态栏（连接/断开/重新搜索）
    onPrinterTap() {
      if (this.data.printerConnected) {
        // 已连接时，允许断开并重新扫描
        ble.disconnect();
        this.updateConnectionStatus();
        this.startScanPrinters();
      } else {
        this.startScanPrinters();
      }
    },

    // 开始扫描打印机
    startScanPrinters() {
      this.setData({
        scanning: true,
        showDeviceList: true,
        devices: []
      });

      ble.initBluetooth()
        .then(() => {
          return ble.startScan((devices) => {
            this.setData({ devices });
          });
        })
        .catch((err) => {
          this.setData({ scanning: false });
          Toast({
            context: this,
            selector: '#pfc-toast',
            type: 'fail',
            message: err.message || '蓝牙打开失败'
          });
        });
    },

    // 选中打印机设备进行连接
    onDeviceSelect(e) {
      const device = e.currentTarget.dataset.device;
      if (!device) return;

      ble.stopScan();
      this.setData({
        scanning: false,
        connecting: true
      });

      ble.connectPrinter(device.deviceId, device.name)
        .then(() => {
          this.setData({
            connecting: false,
            showDeviceList: false
          });
          this.updateConnectionStatus();
          Toast({
            context: this,
            selector: '#pfc-toast',
            type: 'success',
            message: '连接成功'
          });
        })
        .catch((err) => {
          this.setData({ connecting: false });
          Toast({
            context: this,
            selector: '#pfc-toast',
            type: 'fail',
            message: err.message || '连接失败'
          });
        });
    },

    // 份数改变
    onCopiesChange(e) {
      this.setData({
        copies: e.detail
      });
    },

    // 一键打印
    onPrint() {
      if (!this.data.printerConnected) {
        Toast({
          context: this,
          selector: '#pfc-toast',
          type: 'fail',
          message: '打印机未连接'
        });
        return;
      }
      if (!this.data.labelData) {
        Toast({
          context: this,
          selector: '#pfc-toast',
          type: 'fail',
          message: '未加载标签数据'
        });
        return;
      }

      this.setData({ printing: true });

      const copies = this.data.copies || 1;
      // 构建 TSPL 指令 ArrayBuffer (传入份数，生成或修改为 PRINT N)
      const buffer = tspl.buildFlowCardLabel(this.data.labelData, copies);

      ble.sendData(buffer)
        .then(() => {
          this.setData({ printing: false });
          Toast({
            context: this,
            selector: '#pfc-toast',
            type: 'success',
            message: '发送成功'
          });
        })
        .catch((err) => {
          this.setData({ printing: false });
          Toast({
            context: this,
            selector: '#pfc-toast',
            type: 'fail',
            message: err.message || '打印失败'
          });
        });
    }
  }
});
