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
    // 物料表单
    showMaterialForm: false,
    formLoading: false,
    formTitle: '添加物料',
    materialForm: {
      id: null,
      material_name: '',
      material_spec: '',
    },
    // 获取列表数据
    list: [],
    // 分页相关
    currentPage: 1,
    pageSize: 5,
    pages: 1,
    count: 0,
    loadingMore: false,
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
    // 筛选参数
    searchParams: {
      material_name: '',
      material_thickness: '',
      material_model: '',
      manufacturer: '',
      supplier: '',
      // create_start_date: '',
      // create_end_date: ''
    },
    // 日期选择器
    showDatePicker: false,
    dateType: '', // 'start' 或 'end'
    currentDate: new Date().getTime(),
    fileList: [],
    uploadImgUrls: [], // 存储上传成功的图片URL
    // 标签栏
    activeTab: 0, // 默认显示物料列表

    // 领料记录数据
    receivingList: [],
    receivingSearchValue: '',
    receivingCurrentPage: 1,
    receivingPageSize: 10,
    receivingPages: 1,
    receivingCount: 0,
    receivingLoading: false,
    receivingLoadingMore: false,
    searchTimer: null,

    // 报修表单相关
    showRepairForm: false,
    repairData: {
      device_code: '',
    },
  },

  // 切换标签
  switchTab(e) {
    console.log(e.detail.name);
    const activeTab = e.detail.name;
    this.setData({
      activeTab,
    });

    // 根据当前标签页加载对应数据
    if (activeTab === 0) {
      this.getList();
    } else if (activeTab === 1) {
      this.getReceivingList();
    }
  },

  // 获取领料记录列表
  getReceivingList(page = 1, isLoadMore = false) {
    this.setData({
      receivingLoading: true,
    });

    const params = {
      limit: this.data.receivingPageSize,
      page: page,
      keyword: this.data.receivingSearchValue,
    };

    util
      .request('admin/receiving.Receiving/list', params)
      .then((res) => {
        if (res.data.code === 200) {
          // 处理状态显示
          const list = res.data.data.list.map((item) => {
            let status_text = '未知状态';
            let status_class = '';

            if (item.status === 0) {
              status_text = '待审核';
              status_class = 'status-pending';
            } else if (item.status === 1) {
              status_text = '已通过';
              status_class = 'status-approved';
            } else if (item.status === 2) {
              status_text = '已拒绝';
              status_class = 'status-rejected';
            }

            return {
              ...item,
              status_text,
              status_class,
            };
          });

          const newList = isLoadMore ? [...this.data.receivingList, ...list] : list;

          this.setData({
            receivingList: newList,
            receivingCount: res.data.data.count,
            receivingPages: res.data.data.pages,
            receivingCurrentPage: page,
            receivingLoading: false,
            receivingLoadingMore: false,
          });
        } else {
          Toast.fail(res.data.msg || '获取领料记录失败');
          this.setData({
            receivingLoading: false,
            receivingLoadingMore: false,
          });
        }
      })
      .catch(() => {
        Toast.fail('接口请求失败');
        this.setData({
          receivingLoading: false,
          receivingLoadingMore: false,
        });
      });
  },

  // 领料记录搜索
  onReceivingSearchChange(e) {
    this.setData({
      receivingSearchValue: e.detail,
    });
  },

  onReceivingSearch() {
    this.getReceivingList(1);
  },

  // 下拉刷新
  onPullDownRefresh() {
    if (this.data.activeTab === 0) {
      this.getList(1).finally(() => {
        wx.stopPullDownRefresh();
      });
    } else {
      this.getReceivingList(1).finally(() => {
        wx.stopPullDownRefresh();
      });
    }
  },

  // 上拉加载更多
  onReachBottom() {
    if (this.data.activeTab === 0) {
      if (this.data.currentPage < this.data.pages && !this.data.loadingMore) {
        this.loadMore();
      }
    } else {
      if (
        this.data.receivingCurrentPage < this.data.receivingPages &&
        !this.data.receivingLoadingMore
      ) {
        this.loadMoreReceiving();
      }
    }
  },
  // 获取列表
  getList(page = 1, isLoadMore = false) {
    // 如果正在加载中，则忽略新的请求（除了加载更多）
    if (this.data.loading && !isLoadMore) return;

    this.setData({
      loading: !isLoadMore,
      loadingMore: isLoadMore,
    });

    const params = {
      limit: this.data.pageSize,
      page: page,
      ...this.data.searchParams,
    };

    // 移除空值参数
    Object.keys(params).forEach((key) => {
      if (params[key] === '' || params[key] === undefined) {
        delete params[key];
      }
    });

    util
      .request('admin/production.Material/list', params)
      .then((res) => {
        if (res.data.code === 200) {
          const newList = isLoadMore
            ? [...this.data.list, ...res.data.data.list]
            : res.data.data.list;

          this.setData(
            {
              list: newList,
              count: res.data.data.count,
              pages: res.data.data.pages,
              currentPage: page,
              loading: false,
              loadingMore: false,
            },
            () => {
              console.log(this.data.list);
            },
          );
        } else {
          Toast.fail(res.data.msg || '获取列表失败');
          this.setData({
            loading: false,
            loadingMore: false,
          });
        }
      })
      .catch(() => {
        Toast.fail('接口请求失败');
        this.setData({
          loading: false,
          loadingMore: false,
        });
      });
  },

  onUnload() {
    if (this.data.searchTimer) {
      clearTimeout(this.data.searchTimer);
    }
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

  // 添加领料记录加载更多方法
  loadMoreReceiving() {
    if (
      this.data.receivingCurrentPage >= this.data.receivingPages ||
      this.data.receivingLoadingMore
    ) {
      return;
    }

    this.setData({
      receivingLoadingMore: true,
    });
    const nextPage = this.data.receivingCurrentPage + 1;
    this.getReceivingList(nextPage, true);
  },

  // 查询问题
  onClosePopu() {
    this.setData({
      showPopu: false,
    });
  },
  // 搜索框变化
  onSearchChange(e) {
    const value = e.detail;

    // 1. 先更新搜索值（但不立即触发搜索）
    this.setData({
      'searchParams.material_name': value,
    });

    // 2. 清除之前的定时器
    if (this.data.searchTimer) {
      clearTimeout(this.data.searchTimer);
    }

    // 3. 设置新的定时器（防抖300ms）
    this.setData({
      searchTimer: setTimeout(() => {
        this.getList(); // 防抖结束后执行搜索
      }, 300),
    });
  },

  // 触发搜索
  onSearch() {
    this.getList(1);
  },

  // 筛选条件变化
  onFilterChange(e) {
    const field = e.currentTarget.dataset.field;
    this.setData({
      [`searchParams.${field}`]: e.detail,
    });
  },

  // // 显示日期选择器
  // onShowDatePicker(e) {
  //     const type = e.currentTarget.dataset.type;
  //     this.setData({
  //         showDatePicker: true,
  //         dateType: type
  //     });
  // },

  // // 日期确认
  // onDateConfirm(e) {
  //     const date = new Date(e.detail);
  //     const formattedDate = `${date.getFullYear()}-${(date.getMonth() + 1).toString().padStart(2, '0')}-${date.getDate().toString().padStart(2, '0')}`;

  //     if (this.data.dateType === 'start') {
  //         this.setData({
  //             'searchParams.create_start_date': formattedDate,
  //             showDatePicker: false
  //         });
  //     } else {
  //         this.setData({
  //             'searchParams.create_end_date': formattedDate,
  //             showDatePicker: false
  //         });
  //     }
  // },

  // // 取消日期选择
  // onDateCancel() {
  //     this.setData({
  //         showDatePicker: false
  //     });
  // },

  // 重置筛选条件
  onResetFilter() {
    this.setData(
      {
        searchParams: {
          material_name: '',
          material_thickness: '',
          material_model: '',
          manufacturer: '',
          supplier: '',
          // create_start_date: '',
          // create_end_date: ''
        },
      },
      () => {
        this.getList(1);
        this.onClosePopu();
      },
    );
  },
  // 提交筛选
  onSubmitFilter() {
    this.getList(1);
    this.onClosePopu();
  },
  onClick() {
    this.setData({
      showPopu: true,
    });
  },

  // 显示添加物料表单 (废弃旧版单一入口)
  showAddMaterialForm() {
    wx.navigateTo({
      url: `/pageMaterial/pages/receiveMaterial/receiveMaterial`,
    });
  },

  // 新增: 圆钢领用申请入口
  showRoundSteelRequest() {
    wx.navigateTo({
      url: `/pageMaterial/pages/receiveMaterial/receiveMaterial?type=圆钢库`,
    });
  },

  // 新增: 配件领用申请入口
  showAccessoriesRequest() {
    wx.navigateTo({
      url: `/pageMaterial/pages/receiveMaterial/receiveMaterial?type=配件库`,
    });
  },

  // // 显示编辑物料表单
  // handleEditMaterial(e) {
  //     const item = e.currentTarget.dataset.item;
  //     this.setData({
  //         showMaterialForm: true,
  //         formTitle: "编辑物料",
  //         materialForm: {
  //             id: item.id,
  //             material_name: item.material_name,
  //             material_spec: item.material_spec
  //         }
  //     });
  // },

  // 关闭物料表单
  closeMaterialForm() {
    this.setData({
      showMaterialForm: false,
    });
  },

  // 物料名称变化
  onMaterialNameChange(e) {
    this.setData({
      'materialForm.material_name': e.detail,
    });
  },

  // 物料型号变化
  onMaterialSpecChange(e) {
    this.setData({
      'materialForm.material_spec': e.detail,
    });
  },

  // 提交物料表单
  submitMaterialForm() {
    const { id, material_name, material_spec } = this.data.materialForm;

    if (!material_name) {
      Toast.fail('请输入物料名称');
      return;
    }
    if (!material_spec) {
      Toast.fail('请输入物料型号');
      return;
    }

    this.setData({
      formLoading: true,
    });

    const url = id ? 'admin/production.Material/edit' : 'admin/production.Material/add';
    const data = id
      ? {
          id,
          material_name,
          material_spec,
        }
      : {
          material_name,
          material_spec,
        };

    util
      .request(url, data, 'POST')
      .then((res) => {
        if (res.data.code === 200) {
          Toast.success(id ? '修改成功' : '添加成功');
          this.closeMaterialForm();
          // 刷新第一页数据
          this.getList(1);
        } else {
          Toast.fail(res.data.msg || (id ? '修改失败' : '添加失败'));
        }
      })
      .catch(() => {
        Toast.fail('接口请求失败');
      })
      .finally(() => {
        this.setData({
          formLoading: false,
        });
      });
  },

  // // 处理删除物料
  // handleDeleteMaterial(e) {
  //     const id = e.currentTarget.dataset.id;
  //     Dialog.confirm({
  //             title: '确认删除',
  //             message: '确定要删除这条物料吗？',
  //         })
  //         .then(() => {
  //             this.deleteMaterial(id);
  //         })
  //         .catch(() => {
  //             Toast("已取消删除");
  //         });
  // },

  // // 删除物料
  // deleteMaterial(id) {
  //     util.request(
  //             "admin/production.Material/dele", {
  //                 ids: [id]
  //             },
  //             "POST"
  //         )
  //         .then((res) => {
  //             if (res.data.code === 200) {
  //                 Toast.success("删除成功");
  //                 // 刷新当前页数据，如果当前页没有数据了，则加载上一页
  //                 if (this.data.list.length === 1 && this.data.currentPage > 1) {
  //                     this.getList(this.data.currentPage - 1);
  //                 } else {
  //                     this.getList(this.data.currentPage);
  //                 }
  //             } else {
  //                 Toast.fail(res.data.msg || "删除失败");
  //             }
  //         })
  //         .catch(() => {
  //             Toast.fail("接口请求失败");
  //         });
  // },

  // // 导航到详情页
  // navigateToDetail(e) {
  //     const id = e.currentTarget.dataset.id;
  //     wx.navigateTo({
  //         url: `/pageWorkOrder/pages/workOrderDetail/workOrderDetail?id=${id}`
  //     });
  // },

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

  onClose() {
    this.setData({
      show: false,
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
                    console.log(data, 111);
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
        console.log(urls);
        // 确保 urls 是一维数组（即使 Promise.all 返回的是数组的数组）
        const flatUrls = urls.flat(); // 使用 flat() 展平数组
        console.log(flatUrls);
        this.setData(
          {
            fileList: [
              ...this.data.fileList,
              ...files.map((item, i) => ({
                url: flatUrls[i],
                name: item.name || `图片${this.data.fileList.length + i + 1}`,
                deletable: true,
              })),
            ],
            uploadImgUrls: [...this.data.uploadImgUrls, ...flatUrls], // 合并为一维数组
          },
          () => {
            console.log(this.data.fileList, this.data.uploadImgUrls);
          },
        );
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
        if (res.data.code === 200) {
          Toast.success('报修成功');
          this.setData({
            device_code: '',
            reason: '',
            show: false,
            fileList: [],
            uploadImgUrls: [], // 重置为空数组
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
        this.setData({
          statusBarHeight: res.statusBarHeight,
        });
      },
    });
    this.getList();
    this.initButtonPosition();

    // 添加强制更新
    setTimeout(() => {
      this.setData({
        list: this.data.list,
      });
    }, 100);
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
