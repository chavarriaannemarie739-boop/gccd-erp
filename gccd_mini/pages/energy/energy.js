const util = require('../../utils/util.js');
const mediaCompress = require('../../utils/mediaCompress.js');
import Toast from '@vant/weapp/toast/toast';
import Dialog from '@vant/weapp/dialog/dialog';

Page({
  data: {
    // 导航栏相关内容
    statusBarHeight: 0,
    // 扫码报修
    device_code: '',
    reason: '',
    show: false,
    loading: false,
    // 列表数据
    list: [],
    // 分页相关
    currentPage: 1,
    pageSize: 10,
    pages: 1,
    count: 0,
    loadingMore: false,
    loading: false,
    fileList: [],
    uploadImgUrls: [], // 存储上传成功的图片URL

    // 按钮样式
    btnPosition: {
      top: 0,
      left: 100,
    },
    startX: 0,
    startY: 0,
    btnStartX: 0,
    btnStartY: 0,
    value: '',
    showPopu: false,

    // 报修表单相关
    showRepairForm: false,
    repairData: {
      device_code: '',
    },
  },

  // 获取能源列表
  getList(page = 1, isLoadMore = false) {
    this.setData({
      loading: true,
    });

    util
      .request('admin/production.ProductReport/powerlist', {
        limit: this.data.pageSize,
        page: page,
      })
      .then((res) => {
        if (res.data.code === 200) {
          const newList = isLoadMore
            ? [...this.data.list, ...res.data.data.list]
            : res.data.data.list;
          this.setData({
            list: newList,
            count: res.data.data.count,
            pages: res.data.data.pages,
            currentPage: page,
            loading: false,
            loadingMore: false,
          });
        } else {
          this.setData({
            loading: false,
            loadingMore: false,
          });
          Toast.fail(res.data.msg || '获取能源列表失败');
        }
      })
      .catch(() => {
        this.setData({
          loading: false,
          loadingMore: false,
        });
        Toast.fail('接口请求失败');
      });
  },

  // 加载更多
  loadMore() {
    if (this.data.currentPage >= this.data.pages || this.data.loadingMore) {
      return;
    }

    this.setData({
      loadingMore: true,
    });
    const nextPage = this.data.currentPage + 1;
    this.getList(nextPage, true);
  },

  // 导航到详情页（能源列表暂不需要详情页）
  navigateToDetail(e) {
    const id = e.currentTarget.dataset.id;
    // 如果需要详情页，可以取消下面注释
    // wx.navigateTo({
    //     url: `/pages/energyDetail/energyDetail?id=${id}`
    // });
  },

  handleScan() {
    const that = this;
    // 直接调用扫码接口，并显示相册入口
    wx.scanCode({
      onlyFromCamera: false, // 允许从相册选择
      scanType: ['qrCode', 'barCode'], // 支持二维码和条形码
      success(res) {
        that.processScanResult(res.result);
      },
      fail(err) {
        console.error('扫码失败:', err);
        // 只有用户明确取消时才不提示错误
        if (err.errMsg.indexOf('cancel') === -1) {
          wx.showToast({
            title: '扫码失败',
            icon: 'none',
          });
        }
      },
    });
  },

  // 识别图片中的二维码
  recognizeQRCode(tempFilePath) {
    const that = this;
    wx.showLoading({
      title: '识别中...',
    });

    // 使用微信的API识别二维码
    wx.getFileSystemManager().readFile({
      filePath: tempFilePath,
      encoding: 'binary',
      success(res) {
        // 这里使用wx.scanCode的扩展能力（部分版本支持）
        // 如果不支持，可能需要使用第三方库或后端服务识别
        wx.scanCode({
          onlyFromCamera: false, // 允许从相册选择
          path: tempFilePath,
          success(res) {
            wx.hideLoading();
            that.processScanResult(res.result);
          },
          fail(err) {
            wx.hideLoading();
            console.error('图片识别失败:', err);

            // 尝试使用另一种方式（如果支持）
            if (wx.scanQRCode) {
              wx.scanQRCode({
                filePath: tempFilePath,
                success(res) {
                  that.processScanResult(res.result);
                },
                fail() {
                  wx.showToast({
                    title: '图片中未识别到二维码',
                    icon: 'none',
                  });
                },
              });
            } else {
              wx.showToast({
                title: '图片中未识别到二维码',
                icon: 'none',
              });
            }
          },
        });
      },
      fail(err) {
        wx.hideLoading();
        console.error('读取文件失败:', err);
        wx.showToast({
          title: '读取图片失败',
          icon: 'none',
        });
      },
    });
  },

  // 处理扫码结果
  processScanResult(result) {
    console.log('扫码结果:', result);
    // 这里处理扫码结果，例如打开报修表单
    this.setData({
      device_code: result,
      show: true,
    });
  },

  // 显示报修表单
  show(e) {
    const device_code = e.currentTarget.dataset.code;
    this.setData({
      device_code,
      show: true,
    });
  },

  onClose() {
    this.setData({
      show: false,
      device_code: '',
      reason: '',
    });
  },

  onReasonChange(e) {
    this.setData({
      reason: e.detail,
    });
  },

  // 图片上传后的处理
  afterRead(event) {
    const { file } = event.detail;
    const adminToken = wx.getStorageSync('AdminToken');
    const files = Array.isArray(file) ? file : [file];

    Toast.loading({
      message: '处理中...',
      forbidClick: true,
      duration: 0,
    });

    const uploadTasks = files.map((item) =>
      mediaCompress
        .prepareLocalPathForUpload(item.url, {
          type: item.type,
          name: item.name,
        })
        .then(
          (filePath) =>
            new Promise((resolve, reject) => {
              wx.uploadFile({
                url: 'https://gccd.xnssoft.com/admin/wechat.WeChat/upload',
                filePath,
                name: 'files[]',
                formData: {
                  AdminToken: adminToken,
                },
                success(res) {
                  try {
                    const data = JSON.parse(res.data);
                    if (data.code === 200) {
                      resolve(data.msg); // 返回图片URL
                    } else {
                      reject(new Error(data.msg || '上传失败'));
                    }
                  } catch (e) {
                    reject(new Error('解析返回数据失败'));
                  }
                },
                fail() {
                  reject(new Error('上传接口请求失败'));
                },
              });
            }),
        ),
    );

    Promise.all(uploadTasks)
      .then((urls) => {
        // 确保 urls 是一维数组（即使 Promise.all 返回的是数组的数组）
        const flatUrls = urls.flat(); // 使用 flat() 展平数组
        this.setData({
          fileList: [
            ...this.data.fileList,
            ...files.map((item, i) => ({
              url: flatUrls[i],
              name: item.name || `图片${this.data.fileList.length + i + 1}`,
              deletable: true,
            })),
          ],
          uploadImgUrls: [...this.data.uploadImgUrls, ...flatUrls], // 合并为一维数组
        });
        Toast.success('上传成功');
      })
      .catch((err) => Toast.fail(err.message))
      .finally(() => Toast.clear());
  },

  // 删除图片
  deleteImage(event) {
    const { index } = event.detail;
    const newFileList = this.data.fileList.filter((_, i) => i !== index);
    const newUploadUrls = this.data.uploadImgUrls.filter((_, i) => i !== index);

    this.setData({
      fileList: newFileList,
      uploadImgUrls: newUploadUrls,
    });
  },

  // 提交报修（修改）
  submitRepair() {
    const { device_code, reason, uploadImgUrls } = this.data;
    if (!device_code) return Toast.fail('请先扫描设备二维码');
    if (!reason) return Toast.fail('请输入报修原因');

    this.setData({
      loading: true,
    });

    // 检查 uploadImgUrls 是否是扁平数组
    console.log('上传的图片URL数组:', uploadImgUrls); // 应为 ["url1", "url2"]

    util
      .request(
        'admin/repair.Device/report',
        {
          device_code,
          reason,
          img: uploadImgUrls, // 直接传递扁平数组
        },
        'POST',
      )
      .then((res) => {
        console.log(res.data);
        if (res.data.code === 200) {
          console.log(111);
          Toast.success('报修成功');
          this.setData({
            device_code: '',
            reason: '',
            show: false,
            fileList: [],
            uploadImgUrls: [], // 重置为空数组
          });
        } else {
          Toast.fail(res.data.data.msg || '报修失败');
        }
      })
      .catch(() => Toast.fail('接口请求失败'))
      .finally(() =>
        this.setData({
          loading: false,
        }),
      );
  },

  // 导航栏宽高获取
  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({
          statusBarHeight: res.statusBarHeight,
        });
      },
    });
    this.getList();
    this.initButtonPosition();
  },

  onShow() {
    this.getList();
  },

  // 下拉刷新
  onPullDownRefresh() {
    this.getList(1).finally(() => {
      wx.stopPullDownRefresh();
    });
  },

  // 上拉加载更多
  onReachBottom() {
    if (this.data.currentPage < this.data.pages && !this.data.loadingMore) {
      this.loadMore();
    }
  },

  // 拖动按钮逻辑
  touchStart(e) {
    this.setData({
      startX: e.touches[0].clientX,
      startY: e.touches[0].clientY,
      btnStartX: this.data.btnPosition.left,
      btnStartY: this.data.btnPosition.top,
    });
  },

  touchMove(e) {
    const { startX, startY, btnStartX, btnStartY } = this.data;
    const touchX = e.touches[0].clientX;
    const touchY = e.touches[0].clientY;

    this.setData({
      btnPosition: {
        left: btnStartX + (touchX - startX),
        top: btnStartY + (touchY - startY),
      },
    });
  },

  touchEnd() {
    const systemInfo = wx.getSystemInfoSync();
    const screenWidth = systemInfo.windowWidth;
    const screenHeight = systemInfo.windowHeight;
    const btnSize = 100; // 按钮宽高

    this.setData((prev) => {
      let newTop = prev.btnPosition.top;
      let newLeft = prev.btnPosition.left;

      // 边界检查
      if (newTop < 0) newTop = 0;
      if (newLeft < 0) newLeft = 0;
      if (newTop > screenHeight - btnSize) newTop = screenHeight - btnSize;
      if (newLeft > screenWidth - btnSize) newLeft = screenWidth - btnSize;

      return {
        btnPosition: {
          top: newTop,
          left: newLeft,
        },
      };
    });
  },

  // 初始化按钮位置
  initButtonPosition() {
    const systemInfo = wx.getSystemInfoSync();
    const navbarHeight = 44; // 导航栏固定高度
    const statusBarHeight = this.data.statusBarHeight;

    this.setData({
      btnPosition: {
        top: systemInfo.windowHeight - 150 - navbarHeight - statusBarHeight,
        left: systemInfo.windowWidth - 120,
      },
    });
  },

  // 跳转添加能耗页
  showAddMaterialForm() {
    wx.navigateTo({
      url: `/pageEnergy/pages/addEnergy/addEnergy`,
    });
  },

  // 扫码成功回调
  onScanSuccess(e) {
    const result = e.detail.result;
    this.setData({
      repairData: {
        device_code: result,
      },
      showRepairForm: true,
    });
  },
  // 打开报修表单（可用于其他触发方式）
  openRepairForm(device_code) {
    console.log(111);
    this.setData({
      repairData: {
        device_code,
      },
      showRepairForm: true,
    });
  },

  // 关闭报修表单
  closeRepairForm() {
    this.setData({
      showRepairForm: false,
    });
  },
});
