const util = require('../../utils/util.js');
const mediaCompress = require('../../utils/mediaCompress.js');
import Toast from '@vant/weapp/toast/toast';
import Dialog from '@vant/weapp/dialog/dialog';

Page({
  data: {
    // 导航栏相关内容
    statusBarHeight: 0,
    subTabSafeBottom: 0,
    // 扫码报修
    device_code: '',
    reason: '',
    show: false,
    loading: false,
    // 分页相关 — 进行中
    list: [],
    page: 1,
    limit: 10,
    count: 0,
    pages: 0,
    loadingMore: false,
    noMore: false,
    refreshing: false,
    // 分页相关 — 已完成
    finishedList: [],
    finishedPage: 1,
    finishedCount: 0,
    finishedPages: 0,
    finishedLoadingMore: false,
    finishedNoMore: false,
    finishedRefreshing: false,
    // Tab 切换
    activeTab: 0,
    // 按钮样式
    btnPosition: {
      top: 0,
      left: 100,
    },
    startX: 0,
    startY: 0,
    btnStartX: 0,
    btnStartY: 0,
    fileList: [],
    uploadImgUrls: [],

    // 报修表单相关
    showRepairForm: false,
    repairData: {
      device_code: '',
    },
    // 检索条件
    filterYSpec: '',
    filterCSpec: '',
    filterCModel: '',
    // 完整数据（未过滤）
    fullList: [],
    fullFinishedList: [],
  },

  // 检索输入
  onFilterInput(e) {
    const field = e.currentTarget.dataset.field;
    this.setData({ [field]: e.detail.value });
    this.applyFilter();
  },

  // 重置检索
  onFilterReset() {
    this.setData({ filterYSpec: '', filterCSpec: '', filterCModel: '' });
    this.applyFilter();
  },

  // 应用过滤
  applyFilter() {
    const ys = (this.data.filterYSpec || '').trim().toLowerCase();
    const cs = (this.data.filterCSpec || '').trim().toLowerCase();
    const cm = (this.data.filterCModel || '').trim().toLowerCase();

    const match = (item) => {
      const yCombined = (
        (item.y_material_spec || '') + (item.y_material_model || '')
      ).toLowerCase();
      if (ys && !yCombined.includes(ys)) return false;
      if (cs && !(item.c_material_spec || '').toLowerCase().includes(cs)) return false;
      if (cm && !(item.c_material_model || '').toLowerCase().includes(cm)) return false;
      return true;
    };

    this.setData({
      list: this.data.fullList.filter(match),
      finishedList: this.data.fullFinishedList.filter(match),
    });
  },

  // 切换标签
  switchTab(e) {
    const activeTab = e.detail.name;
    this.setData({ activeTab });

    if (activeTab === 0) {
      if (this.data.list.length === 0) {
        this.getList();
      }
    } else if (activeTab === 1) {
      if (this.data.finishedList.length === 0) {
        this.getFinishedList();
      }
    }
  },

  // 判断工单是否已完成：report_num >= task_num
  isTaskFinished(item) {
    const reportNum = parseFloat(item.report_num) || 0;
    const taskNum = parseFloat(item.task_num) || 0;
    return taskNum > 0 && reportNum >= taskNum;
  },

  // 解析 report 字段
  buildProcessReport(item) {
    let reportObj = item.report || {};
    if (typeof reportObj === 'string') {
      try {
        reportObj = JSON.parse(reportObj);
      } catch (e) {
        reportObj = {};
      }
    }
    return { _raw: reportObj };
  },

  // 按工艺路线实际工序动态生成报工展示列
  loadProcessNames(items, listKey) {
    const routingCodes = [...new Set(items.map((i) => i.routing_code).filter(Boolean))];
    if (routingCodes.length === 0) return;

    if (!this._processNameCache) this._processNameCache = {};

    const tasks = routingCodes.map((code) => {
      if (this._processNameCache[code]) return Promise.resolve();
      return util
        .request(`admin/production.Routing/getProcess?routing_code=${code}`, {}, 'POST')
        .then((res) => {
          if (res.data.code === 200) {
            const nameMap = {};
            const content = res.data.data.content || [];
            content.forEach((p) => {
              nameMap[p.process_code] = p.processes_name;
            });
            this._processNameCache[code] = nameMap;
            if (!this._processContentCache) this._processContentCache = {};
            this._processContentCache[code] = content;
          }
        })
        .catch(() => {});
    });

    Promise.all(tasks).then(() => {
      const updatedList = this.data[listKey].map((item) => {
        const nameMap = this._processNameCache[item.routing_code] || {};
        const content =
          (this._processContentCache && this._processContentCache[item.routing_code]) || [];
        const rawReport = item._rawReport || {};

        // 按工艺路线实际工序生成列，按 sort 排序
        const sortedCodes = content
          .slice()
          .sort((a, b) => (a.sort || 0) - (b.sort || 0))
          .map((c) => c.process_code);

        const processReports = sortedCodes.map((code) => {
          const name = nameMap[code] || code;
          const val = rawReport[code] ? rawReport[code].report_num || 0 : 0;
          return { name, report_num: val };
        });

        return { ...item, processReports };
      });
      this.setData({ [listKey]: updatedList });
      this.applyFilter();
    });
  },

  // 获取进行中列表
  getList(page = 1, isLoadMore = false) {
    if (!isLoadMore) {
      this.setData({ loading: true });
    } else {
      this.setData({ loadingMore: true });
    }

    util
      .request('admin/production.ProductTask/list', {
        limit: this.data.limit,
        page: page,
      })
      .then((res) => {
        if (res.data.code === 200) {
          const allItems = res.data.data.list || [];
          // 前端过滤 + 组装工序报工数据
          const processingItems = allItems
            .filter((item) => !this.isTaskFinished(item))
            .map((item) => {
              const pr = this.buildProcessReport(item);
              return { ...item, processReports: pr.list, _rawReport: pr._raw };
            });

          const newFullList =
            page === 1 ? processingItems : [...this.data.fullList, ...processingItems];
          const noMore = allItems.length < this.data.limit || page >= res.data.data.pages;

          this.setData({
            fullList: newFullList,
            count: res.data.data.count,
            pages: res.data.data.pages,
            page: page,
            noMore: noMore,
            loading: false,
            loadingMore: false,
            refreshing: false,
          });

          // 应用过滤
          this.applyFilter();
          // 异步加载工序名称
          this.loadProcessNames(processingItems, 'fullList');
        } else {
          this.setData({
            loading: false,
            loadingMore: false,
            refreshing: false,
          });
          Toast.fail(res.data.msg || '获取数据失败');
        }
      })
      .catch(() => {
        this.setData({
          loading: false,
          loadingMore: false,
          refreshing: false,
        });
        Toast.fail('接口请求失败');
      });
  },

  // 获取已完成列表
  getFinishedList(page = 1, isLoadMore = false) {
    if (!isLoadMore) {
      this.setData({ loading: true });
    } else {
      this.setData({ finishedLoadingMore: true });
    }

    util
      .request('admin/production.ProductTask/list', {
        limit: this.data.limit,
        page: page,
      })
      .then((res) => {
        if (res.data.code === 200) {
          const allItems = res.data.data.list || [];
          const finishedItems = allItems
            .filter((item) => this.isTaskFinished(item))
            .map((item) => {
              const pr = this.buildProcessReport(item);
              return { ...item, processReports: pr.list, _rawReport: pr._raw };
            });

          const newFullList =
            page === 1 ? finishedItems : [...this.data.fullFinishedList, ...finishedItems];
          const noMore = allItems.length < this.data.limit || page >= res.data.data.pages;

          this.setData({
            fullFinishedList: newFullList,
            finishedCount: res.data.data.count,
            finishedPages: res.data.data.pages,
            finishedPage: page,
            finishedNoMore: noMore,
            finishedLoadingMore: false,
            finishedRefreshing: false,
            loading: false,
          });

          // 应用过滤
          this.applyFilter();
          this.loadProcessNames(finishedItems, 'fullFinishedList');
        } else {
          this.setData({
            loading: false,
            finishedLoadingMore: false,
            finishedRefreshing: false,
          });
          Toast.fail(res.data.msg || '获取数据失败');
        }
      })
      .catch(() => {
        this.setData({
          loading: false,
          finishedLoadingMore: false,
          finishedRefreshing: false,
        });
        Toast.fail('接口请求失败');
      });
  },

  // 导航到详情页
  navigateToDetail(e) {
    const id = e.currentTarget.dataset.id;
    const processes_code = e.currentTarget.dataset.processes_code;
    wx.navigateTo({
      url: `/pageWorkOrder/pages/workOrderInfo/workOrderInfo?id=${id}&processes_code=${processes_code}`,
    });
  },
  // 导航到报工页面
  workOrderSubmit(e) {
    const item = this.data.fullList.find((i) => i.id === e.currentTarget.dataset.id);
    console.log(
      '报工点击 - 工单数据:',
      item
        ? { id: item.id, routing_code: item.routing_code, type: typeof item.routing_code }
        : 'not found',
    );
    const routing_code = item ? item.routing_code : e.currentTarget.dataset.routing_code;
    const id = e.currentTarget.dataset.id;
    if (!routing_code && routing_code !== 0) {
      Toast.fail('该工单未设置工艺信息，请先在后台配置');
      return;
    }
    wx.navigateTo({
      url: `/pageWorkOrder/pages/workOrderProcess/workOrderProcess?routing_code=${routing_code}&id=${id}`,
    });
  },

  // 处理删除操作
  handleDelete(e) {
    const id = e.currentTarget.dataset.id;
    Dialog.confirm({
      title: '确认删除',
      message: '确定要删除这条任务吗？',
    })
      .then(() => {
        this.deleteTask(id);
      })
      .catch(() => {
        Toast('用户取消删除');
      });
  },

  // 删除任务
  deleteTask(ids) {
    util
      .request(
        'admin/production.ProductTask/dele',
        {
          ids,
        },
        'POST',
      )
      .then((res) => {
        if (res.data.code === 200) {
          Toast.success('删除成功');
          if (this.data.activeTab === 0) {
            this.getList(this.data.page);
          } else {
            this.getFinishedList(this.data.finishedPage);
          }
        } else {
          Toast.fail(res.data.msg || '删除失败');
        }
      })
      .catch(() => {
        Toast.fail('接口请求失败');
      });
  },

  // 扫码报修
  handleScan() {
    const that = this;
    wx.scanCode({
      onlyFromCamera: false,
      scanType: ['qrCode', 'barCode'],
      success(res) {
        that.processScanResult(res.result);
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

  // 识别图片中的二维码
  recognizeQRCode(tempFilePath) {
    const that = this;
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
            that.processScanResult(res.result);
          },
          fail(err) {
            wx.hideLoading();
            console.error('图片识别失败:', err);

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
    this.setData({
      device_code: result,
      show: true,
    });
  },

  // 关闭弹窗
  onClose() {
    this.setData({
      show: false,
    });
  },

  // 报修原因输入变化
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
                      resolve(data.msg);
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
        const flatUrls = urls.flat();
        this.setData({
          fileList: [
            ...this.data.fileList,
            ...files.map((item, i) => ({
              url: flatUrls[i],
              name: item.name || `图片${this.data.fileList.length + i + 1}`,
              deletable: true,
            })),
          ],
          uploadImgUrls: [...this.data.uploadImgUrls, ...flatUrls],
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

  // 提交报修
  submitRepair() {
    const { device_code, reason, uploadImgUrls } = this.data;
    if (!device_code) return Toast.fail('请先扫描设备二维码');
    if (!reason) return Toast.fail('请输入报修原因');

    this.setData({
      loading: true,
    });

    util
      .request(
        'admin/repair.Device/report',
        {
          device_code,
          reason,
          img: uploadImgUrls,
        },
        'POST',
      )
      .then((res) => {
        if (res.data.code === 200) {
          Toast.success('报修成功');
          this.setData({
            device_code: '',
            reason: '',
            show: false,
            fileList: [],
            uploadImgUrls: [],
          });
        } else {
          Toast.fail(res.data.msg || '报修失败');
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
        const bottom = (res.safeAreaInsets && res.safeAreaInsets.bottom) || 0;
        this.setData({
          statusBarHeight: res.statusBarHeight,
          subTabSafeBottom: bottom,
        });
      },
    });

    this.getList();
    this.initButtonPosition();
  },

  onSubTabBarTap(e) {
    const path = e.currentTarget.dataset.path;
    if (!path) return;
    if (path === '/pages/workbench/workbench') {
      wx.reLaunch({ url: path });
      return;
    }
    wx.switchTab({ url: path });
  },

  onScrollRefresher() {
    if (this.data.activeTab === 0) {
      if (this.data.refreshing) return;
      this.setData({ refreshing: true });
      this.getList(1);
    } else {
      if (this.data.finishedRefreshing) return;
      this.setData({ finishedRefreshing: true });
      this.getFinishedList(1);
    }
  },

  onWorkOrderScrollToLower() {
    if (this.data.activeTab === 0) {
      if (this.data.loadingMore || this.data.noMore || this.data.refreshing) return;
      const nextPage = this.data.page + 1;
      if (nextPage <= this.data.pages) {
        this.getList(nextPage, true);
      }
    } else {
      if (this.data.finishedLoadingMore || this.data.finishedNoMore || this.data.finishedRefreshing)
        return;
      const nextPage = this.data.finishedPage + 1;
      if (nextPage <= this.data.finishedPages) {
        this.getFinishedList(nextPage, true);
      }
    }
  },

  onShow() {
    if (this.data.activeTab === 0) {
      this.getList();
    } else {
      this.getFinishedList();
    }
  },

  // 下拉刷新
  onPullDownRefresh() {
    if (this.data.activeTab === 0) {
      if (this.data.refreshing) return;
      this.setData({ refreshing: true });
      this.getList(1);
    } else {
      if (this.data.finishedRefreshing) return;
      this.setData({ finishedRefreshing: true });
      this.getFinishedList(1);
    }
    wx.stopPullDownRefresh();
  },

  onReachBottom() {
    this.onWorkOrderScrollToLower();
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
    const btnSize = 100;

    this.setData((prev) => {
      let newTop = prev.btnPosition.top;
      let newLeft = prev.btnPosition.left;

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
    const navbarHeight = 44;
    const statusBarHeight = this.data.statusBarHeight;

    this.setData({
      btnPosition: {
        top: systemInfo.windowHeight - 150 - navbarHeight - statusBarHeight,
        left: systemInfo.windowWidth - 220,
      },
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
  // 打开报修表单
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
