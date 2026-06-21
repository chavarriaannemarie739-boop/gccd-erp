Component({
  properties: {
    // 可以添加自定义属性
  },

  methods: {
    handleScan() {
      const that = this;
      wx.scanCode({
        onlyFromCamera: false,
        scanType: ['qrCode', 'barCode'],
        success(res) {
          that.triggerEvent('scanSuccess', {
            result: res.result,
          });
        },
        fail(err) {
          console.error('扫码失败:', err);
          if (err.errMsg.indexOf('cancel') === -1) {
            wx.showToast({
              title: '扫码失败',
              icon: 'none',
            });
          }
        },
      });
    },

    // 识别图片中的二维码（可选）
    recognizeQRCode(tempFilePath) {
      return new Promise((resolve, reject) => {
        wx.showLoading({
          title: '识别中...',
        });

        wx.getFileSystemManager().readFile({
          filePath: tempFilePath,
          encoding: 'binary',
          success(res) {
            wx.scanCode({
              onlyFromCamera: false,
              path: tempFilePath,
              success(res) {
                wx.hideLoading();
                resolve(res.result);
              },
              fail(err) {
                wx.hideLoading();
                console.error('图片识别失败:', err);
                reject(new Error('图片中未识别到二维码'));
              },
            });
          },
          fail(err) {
            wx.hideLoading();
            console.error('读取文件失败:', err);
            reject(new Error('读取图片失败'));
          },
        });
      });
    },
  },
});
