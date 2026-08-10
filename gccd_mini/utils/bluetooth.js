/**
 * 蓝牙打印工具类
 * [AI-2026-06] 小程序 BLE 连接得力 DL-888TW 标签打印机
 */

const STORAGE_KEY_DEVICE = 'ble_printer_device';
const STORAGE_KEY_NAME = 'ble_printer_name';
const PRINTER_NAME_KEYWORDS = ['DL-888', 'Deli', 'deli', 'DELI', 'Printer', 'printer'];

let _adapter = false;
let _deviceId = '';
let _serviceId = '';
let _characteristicId = '';
let _connected = false;
let _mtu = 20;

/**
 * 初始化蓝牙适配器
 */
const initBluetooth = () => {
  return new Promise((resolve, reject) => {
    wx.openBluetoothAdapter({
      success: () => {
        _adapter = true;
        resolve();
      },
      fail: (err) => {
        if (err.errCode === 10001) {
          reject(new Error('请先开启手机蓝牙'));
        } else {
          reject(new Error('蓝牙初始化失败'));
        }
      },
    });
  });
};

/**
 * 关闭蓝牙适配器
 */
const closeBluetooth = () => {
  return new Promise((resolve) => {
    wx.closeBluetoothAdapter({ complete: resolve });
    _adapter = false;
    _connected = false;
  });
};

/**
 * 扫描蓝牙设备
 * @param {function} onFound - 回调，参数为设备列表
 */
const startScan = (onFound) => {
  const foundDevices = new Map();

  wx.onBluetoothDeviceFound((res) => {
    for (const device of res.devices) {
      const name = device.name || device.localName || '';
      if (!name) continue;
      if (foundDevices.has(device.deviceId)) continue;

      const isPrinter = PRINTER_NAME_KEYWORDS.some((kw) => name.includes(kw));
      foundDevices.set(device.deviceId, {
        deviceId: device.deviceId,
        name,
        RSSI: device.RSSI,
        isPrinter,
      });
      if (onFound) {
        onFound(Array.from(foundDevices.values()));
      }
    }
  });

  return new Promise((resolve, reject) => {
    wx.startBluetoothDevicesDiscovery({
      allowDuplicatesKey: false,
      success: resolve,
      fail: () => reject(new Error('搜索设备失败')),
    });
  });
};

/**
 * 停止扫描
 */
const stopScan = () => {
  return new Promise((resolve) => {
    wx.stopBluetoothDevicesDiscovery({ complete: resolve });
  });
};

/**
 * 发现可写特征值
 */
const discoverWriteCharacteristic = (deviceId) => {
  return new Promise((resolve, reject) => {
    wx.getBLEDeviceServices({
      deviceId,
      success: (res) => {
        const services = res.services || [];
        if (services.length === 0) {
          reject(new Error('未发现打印机服务'));
          return;
        }

        let found = false;
        let checked = 0;

        const checkDone = () => {
          checked++;
          if (checked >= services.length && !found) {
            reject(new Error('未找到可写特征值'));
          }
        };

        for (const svc of services) {
          wx.getBLEDeviceCharacteristics({
            deviceId,
            serviceId: svc.uuid,
            success: (cRes) => {
              if (found) return;
              for (const c of cRes.characteristics || []) {
                if (c.properties && (c.properties.write || c.properties.writeNoResponse)) {
                  _serviceId = svc.uuid;
                  _characteristicId = c.uuid;
                  found = true;
                  resolve({ serviceId: svc.uuid, characteristicId: c.uuid });
                  return;
                }
              }
              checkDone();
            },
            fail: checkDone,
          });
        }
      },
      fail: () => reject(new Error('获取服务失败')),
    });
  });
};

/**
 * 连接打印机
 * @param {string} deviceId
 * @param {string} [deviceName]
 */
const connectPrinter = (deviceId, deviceName) => {
  return new Promise((resolve, reject) => {
    wx.createBLEConnection({
      deviceId,
      timeout: 10000,
      success: () => {
        _deviceId = deviceId;
        _connected = true;
        wx.setStorageSync(STORAGE_KEY_DEVICE, deviceId);
        if (deviceName) {
          wx.setStorageSync(STORAGE_KEY_NAME, deviceName);
        }

        // 尝试协商更大 MTU（仅 Android 有效）
        if (wx.setBLEMTU) {
          wx.setBLEMTU({
            deviceId,
            mtu: 512,
            success: (r) => {
              _mtu = Math.min((r.mtu || 512) - 3, 509);
            },
            fail: () => {
              _mtu = 20;
            },
          });
        }

        // 延迟发现服务（部分打印机需等待）
        setTimeout(() => {
          discoverWriteCharacteristic(deviceId).then(resolve).catch(reject);
        }, 600);
      },
      fail: () => {
        reject(new Error('连接打印机失败'));
      },
    });
  });
};

/**
 * 断开连接
 */
const disconnect = () => {
  if (_deviceId) {
    wx.closeBLEConnection({ deviceId: _deviceId, complete: () => {} });
  }
  _connected = false;
  _deviceId = '';
  _serviceId = '';
  _characteristicId = '';
};

/**
 * 分包发送数据
 * @param {ArrayBuffer} buffer
 */
const sendData = (buffer) => {
  if (!_connected || !_deviceId || !_characteristicId) {
    return Promise.reject(new Error('打印机未连接'));
  }

  const chunkSize = Math.max(_mtu, 20);
  const data = new Uint8Array(buffer);
  let pos = 0;

  const sendChunk = () => {
    if (pos >= data.length) {
      return Promise.resolve();
    }
    const end = Math.min(pos + chunkSize, data.length);
    const chunk = data.slice(pos, end).buffer;
    pos = end;

    return new Promise((resolve, reject) => {
      wx.writeBLECharacteristicValue({
        deviceId: _deviceId,
        serviceId: _serviceId,
        characteristicId: _characteristicId,
        value: chunk,
        success: () => setTimeout(() => resolve(sendChunk()), 30),
        fail: () => reject(new Error('发送数据失败')),
      });
    });
  };

  return sendChunk();
};

/**
 * 当前是否已连接
 */
const isConnected = () => _connected;

/**
 * 获取已保存的设备信息
 */
const getSavedDevice = () => ({
  deviceId: wx.getStorageSync(STORAGE_KEY_DEVICE) || '',
  name: wx.getStorageSync(STORAGE_KEY_NAME) || '',
});

/**
 * 自动回连上次的打印机
 */
const autoReconnect = () => {
  const saved = getSavedDevice();
  if (!saved.deviceId) {
    return Promise.reject(new Error('无历史设备'));
  }
  return connectPrinter(saved.deviceId, saved.name);
};

/**
 * 清除已保存的设备
 */
const clearSavedDevice = () => {
  wx.removeStorageSync(STORAGE_KEY_DEVICE);
  wx.removeStorageSync(STORAGE_KEY_NAME);
};

module.exports = {
  initBluetooth,
  closeBluetooth,
  startScan,
  stopScan,
  connectPrinter,
  disconnect,
  sendData,
  isConnected,
  getSavedDevice,
  autoReconnect,
  clearSavedDevice,
};
