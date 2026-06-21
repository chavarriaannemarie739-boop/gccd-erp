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
    // 添加设备表单
    showDeviceForm: false,
    formLoading: false,
    deviceForm: {
      device_name: '',
      device_code: '',
      processes_code: '',
    },
    // 大类 ID（设备现场）
    targetParentId: 0,
    // 列表数据
    activeTab: 0, // 0-设备列表 1-任务列表
    list: [], // 任务列表
    deviceList: [], // 设备列表
    // 分页相关-任务列表
    currentPage: 1,
    pageSize: 10,
    pages: 1,
    count: 0,
    // 分页相关-设备列表
    currentDevicePage: 1,
    devicePages: 1,
    deviceCount: 0,
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
    fileList: [],
    uploadImgUrls: [], // 存储上传成功的图片URL
    showImagePopup: false,
    currentImages: [],
    showRepairRecordForm: false,
    repairLoading: false,
    repairForm: {
      device_code: '',
      repair_code: '',
      detail: '',
      user: '',
    },
    repairFileList: [],
    repairUploadImgUrls: [],
    // userList: [], // 维修人员列表
    currentRepairItem: null, // 当前维修的任务项

    showRepairDetail: false,
    currentDevice: '',
    // 维修原因多选相关（从报修表单移到这里）
    selectedReasons: [], // 选中的原因ID数组
    selectedReasonsText: '', // 选中的原因文本显示
    showReasonPopup: false, // 是否显示原因选择弹窗
    reasonColumns: [
      {
        values: [],
        defaultIndex: 0,
      },
    ],

    // 报修表单相关
    showRepairForm: false,
    repairData: {
      device_code: '',
    },
  },

  // 获取任务列表
  getTaskList(page = 1, isLoadMore = false) {
    this.setData({
      loading: true,
    });

    util
      .request('admin/repair.Device/repairlist', {
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
          Toast.fail(res.data.msg || '获取任务列表失败');
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

  // 获取设备列表
  getDeviceList(page = 1, isLoadMore = false) {
    this.setData({
      loading: true,
    });

    util
      .request('admin/repair.Device/list', {
        limit: this.data.pageSize,
        page: page,
      })
      .then((res) => {
        if (res.data.code === 200) {
          const newList = isLoadMore
            ? [...this.data.deviceList, ...res.data.data.list]
            : res.data.data.list;
          this.setData({
            deviceList: newList,
            deviceCount: res.data.data.count,
            devicePages: res.data.data.pages,
            currentDevicePage: page,
            loading: false,
            loadingMore: false,
          });
        } else {
          this.setData({
            loading: false,
            loadingMore: false,
          });
          Toast.fail(res.data.msg || '获取设备列表失败');
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

  // 加载更多任务
  loadMoreTasks() {
    if (this.data.currentPage >= this.data.pages || this.data.loadingMore) {
      return;
    }

    this.setData({
      loadingMore: true,
    });
    const nextPage = this.data.currentPage + 1;
    this.getTaskList(nextPage, true);
  },

  // 加载更多设备
  loadMoreDevices() {
    if (this.data.currentDevicePage >= this.data.devicePages || this.data.loadingMore) {
      return;
    }

    this.setData({
      loadingMore: true,
    });
    const nextPage = this.data.currentDevicePage + 1;
    this.getDeviceList(nextPage, true);
  },

  previewTaskImages(e) {
    const imgs = e.currentTarget.dataset.imgs || [];

    // 处理图片URL
    const validImages = imgs
      .map((img) => {
        if (!img) return null;

        // 统一处理反斜杠
        let fixedUrl = img.replace(/\\/g, '/');

        // 处理相对路径
        if (!fixedUrl.startsWith('http')) {
          fixedUrl = fixedUrl.startsWith('/') ? fixedUrl.slice(1) : fixedUrl;
          fixedUrl = `https://gccd.xnssoft.com/${fixedUrl}`;
        }
        return fixedUrl;
      })
      .filter((img) => img && img.trim() !== '');

    if (validImages.length === 0) {
      Toast.fail('没有可预览的图片');
      return;
    }

    // 直接预览第一张图片，用户可以滑动查看其他图片
    wx.previewImage({
      current: validImages[0], // 当前显示图片的http链接
      urls: validImages, // 需要预览的图片http链接列表
    });
  },

  // 显示图片弹窗
  // showImages(e) {
  //     const imgs = e.currentTarget.dataset.imgs || [];

  //     const validImages = imgs
  //         .map(img => {
  //             if (!img) return null;

  //             // 统一处理反斜杠
  //             let fixedUrl = img.replace(/\\/g, '/');

  //             // 处理相对路径
  //             if (!fixedUrl.startsWith('http')) {
  //                 // 移除开头的斜杠（如果有）
  //                 fixedUrl = fixedUrl.startsWith('/') ? fixedUrl.slice(1) : fixedUrl;
  //                 // 添加域名
  //                 fixedUrl = `https://gccd.xnssoft.com/${fixedUrl}`;
  //             }
  //             console.log(fixedUrl);
  //             return fixedUrl;
  //         })
  //         .filter(img => img && img.trim() !== '');

  //     this.setData({
  //         currentImages: validImages,
  //         showImagePopup: true
  //     });
  // },

  // 关闭图片弹窗
  // closeImagePopup() {
  //     this.setData({
  //         showImagePopup: false
  //     });
  // },

  // 预览大图
  // previewImage(e) {
  //     const currentUrl = e.currentTarget.dataset.src;
  //     if (currentUrl === '[图片加载失败]') return;

  //     // 过滤掉所有加载失败的图片
  //     const validUrls = this.data.currentImages.filter(url => url !== '[图片加载失败]');

  //     wx.previewImage({
  //         current: currentUrl,
  //         urls: validUrls
  //     });
  // },

  // 切换标签页
  onTabChange(e) {
    const activeTab = e.detail.name;
    this.setData({
      activeTab,
    });

    // 根据当前标签页加载对应数据
    if (activeTab === 0) {
      this.getDeviceList();
    } else if (activeTab === 1) {
      this.getTaskList();
    }
  },

  // 导航到详情页
  // navigateToDetail(e) {
  //     const id = e.currentTarget.dataset.id;
  //     console.log(e);
  //     wx.navigateTo({
  //         url: `/pageEquipment/pages/taskDetail/taskDetail?id=${id}`
  //     });
  // },

  // 导航到维修记录页
  navigateToRepairRecords(e) {
    console.log(e.currentTarget.dataset);
    const device_code = e.currentTarget.dataset.device_code;
    const device_name = e.currentTarget.dataset.device_name;
    // Toast.success(`查看设备 ${device_code} 的维修记录`);
    wx.navigateTo({
      url: `/pageEquipment/pages/repairRecords/repairRecords?device_code=${device_code}&device_name=${device_name}`,
    });
  },

  // 任务列表删除操作
  // handleDelete(e) {
  //     const id = e.currentTarget.dataset.id;
  //     Dialog.confirm({
  //             title: '确认删除',
  //             message: '确定要删除这条任务吗？',
  //         })
  //         .then(() => {
  //             this.deleteTask(id);
  //         })
  //         .catch(() => {
  //             Toast("已取消删除");
  //         });
  // },

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
          this.getTaskList(this.data.currentPage);
        } else {
          Toast.fail(res.data.msg || '删除失败');
        }
      })
      .catch(() => {
        Toast.fail('接口请求失败');
      });
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

  // 显示报修表单（从设备列表点击报修按钮）
  // show(e) {
  //     const device_code = e.currentTarget.dataset.code;
  //     this.setData({
  //         device_code,
  //         show: true
  //     });
  // },
  // 处理设备列表中的报修按钮点击
  handleDeviceRepair(e) {
    const device_code = e.currentTarget.dataset.code;
    this.openRepairForm(device_code); // 使用已存在的打开表单方法
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

  // 获取报修原因列表
  getReasonList() {
    util
      .request('admin/production.ProductCount/list', {
        limit: 100,
        page: 1,
      })
      .then((res) => {
        if (res.data.code === 200) {
          let reasons = res.data.data.list || [];
          // 设备报修：只显示所属大类为"设备现场"的维修项（使用 parent_id）
          const targetParentId = this.data.targetParentId || 0;
          reasons = reasons.filter((item) => item.parent_id == targetParentId);
          // 只提取名称数组
          const pickerValues = reasons.map((item) => item.repair_name);
          this.setData({
            reasonList: reasons, // 保留完整对象列表供提交使用
            reasonColumns: [
              {
                values: pickerValues,
                defaultIndex: 0,
              },
            ],
          });
        } else {
          Toast.fail(res.data.msg || '获取报修原因失败');
        }
      })
      .catch(() => {
        Toast.fail('获取报修原因接口请求失败');
      });
  },

  // 修改 showReasonPicker 方法，确保每次打开时从当前选择状态开始
  showReasonPicker() {
    if (!this.data.reasonList || this.data.reasonList.length === 0) {
      Toast.fail('暂无可用维修原因');
      return;
    }

    // 更新picker的当前值为已选择的项
    const reasonColumns = [
      {
        values: this.data.reasonColumns[0].values,
        defaultIndex: 0,
      },
    ];

    this.setData({
      reasonColumns,
      showReasonPopup: true,
    });
  },

  // 修改 onReasonConfirm 方法
  onReasonConfirm(event) {
    const { value, index } = event.detail;
    const selectedReason = this.data.reasonList[index]; // 通过索引找到完整对象

    // 切换选择状态（多选）
    let selectedReasons = [...this.data.selectedReasons];
    const reasonIndex = selectedReasons.findIndex((item) => item.id === selectedReason.id);

    if (reasonIndex === -1) {
      selectedReasons.push(selectedReason);
    } else {
      selectedReasons.splice(reasonIndex, 1);
    }

    // 更新显示文本
    const selectedReasonsText = selectedReasons.map((item) => item.repair_name).join('，');

    this.setData({
      selectedReasons,
      selectedReasonsText,
      showReasonPopup: false,
    });
  },

  // 修改 showReasonPicker 方法，确保每次打开时从当前选择状态开始
  showReasonPicker() {
    if (!this.data.reasonList || this.data.reasonList.length === 0) {
      Toast.fail('暂无可用报修原因');
      return;
    }

    // 更新picker的当前值为已选择的项
    const reasonColumns = [
      {
        values: this.data.reasonColumns[0].values,
        defaultIndex: 0,
      },
    ];

    this.setData({
      reasonColumns,
      showReasonPopup: true,
    });
  },

  // 在 onClose 方法中清空选择
  onClose() {
    this.setData({
      show: false,
      device_code: '',
      reason: '',
      selectedReasons: [], // 清空已选择的原因
      selectedReasonsText: '', // 清空显示文本
      fileList: [],
      uploadImgUrls: [],
    });
  },

  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({
          statusBarHeight: res.statusBarHeight,
          selectedReasons: [], // 初始化时清空
          selectedReasonsText: '', // 初始化时清空
        });
      },
    });
    this.getDeviceList();
    this.initButtonPosition();
    this.getReasonList();
  },

  // 取消选择原因
  onReasonCancel() {
    this.setData({
      showReasonPopup: false,
    });
  },

  // 显示格式化方法
  displayFormat(item) {
    return item.name; // 假设 reasonColumns 中的每个项都有 name 属性
  },

  // 提交报修（修改）
  submitRepair() {
    const { device_code, reason, uploadImgUrls } = this.data;

    if (!device_code) return Toast.fail('请先扫描设备二维码');
    if (!reason) return Toast.fail('请填写报修原因');

    this.setData({
      loading: true,
    });

    util
      .request(
        'admin/repair.Device/report',
        {
          device_code,
          reason, // 直接使用文本原因
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
          this.getDeviceList();
          this.getTaskList();
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

  // 设备表单相关方法
  showAddDeviceForm() {
    this.setData({
      showDeviceForm: true,
      deviceForm: {
        device_name: '',
        device_code: '',
        processes_code: '',
      },
    });
  },

  closeDeviceForm() {
    this.setData({
      showDeviceForm: false,
    });
  },

  onDeviceNameChange(e) {
    this.setData({
      'deviceForm.device_name': e.detail,
    });
  },

  onDeviceCodeChange(e) {
    this.setData({
      'deviceForm.device_code': e.detail,
    });
  },

  onProcessesCodeChange(e) {
    this.setData({
      'deviceForm.processes_code': e.detail,
    });
  },

  // 添加设备
  // submitDeviceForm() {
  //     const {
  //         device_name
  //     } = this.data.deviceForm;

  //     if (!device_name) {
  //         Toast.fail("请输入设备名称");
  //         return;
  //     }
  //     // if (!device_code) {
  //     //     Toast.fail("请输入设备编码");
  //     //     return;
  //     // }
  //     // if (!processes_code) {
  //     //     Toast.fail("请输入工序编码");
  //     //     return;
  //     // }

  //     this.setData({
  //         formLoading: true
  //     });

  //     // 使用form-data格式提交
  //     // const formData = new FormData();
  //     // formData.append('device_name', device_name);
  //     // formData.append('device_code', device_code);
  //     // formData.append('processes_code', processes_code);

  //     util.request("admin/repair.Device/add", {
  //             device_name: device_name
  //         }, "POST", {
  //             'Content-Type': 'multipart/form-data'
  //         })
  //         .then((res) => {
  //             if (res.data.code === 200) {
  //                 Toast.success("添加成功");
  //                 this.closeDeviceForm();
  //                 // 刷新设备列表
  //                 this.getDeviceList();
  //             } else {
  //                 Toast.fail(res.data.msg || "添加失败");
  //             }
  //         })
  //         .catch(() => {
  //             Toast.fail("接口请求失败");
  //         })
  //         .finally(() => {
  //             this.setData({
  //                 formLoading: false
  //             });
  //         });
  // },

  // 获取维修人员列表
  // getUserList() {
  //     util.request("admin/system.User/productlist", {
  //             limit: 1000,
  //             page: 1,
  //             processes_name: '酸洗'
  //         }, "POST")
  //         .then((res) => {
  //             if (res.data.code === 200) {
  //                 this.setData({
  //                     userList: res.data.data.list || []
  //                 }, () => {
  //                     console.log(this.data.userList);
  //                 });
  //             } else {
  //                 Toast.fail(res.data.msg || "获取维修人员列表失败");
  //             }
  //         })
  //         .catch(() => {
  //             Toast.fail("获取维修人员列表接口请求失败");
  //         });
  // },

  // 显示维修表单
  showRepairForm(e) {
    // console.log(e.currentTarget.dataset.item);
    const item = e.currentTarget.dataset.item;
    this.setData({
      currentRepairItem: item,
      repairForm: {
        device_code: item.device_code || '',
        repair_code: item.repair_code || '',
        repair_list: item.repair_list || '',
        detail: '',
        user: '',
      },
      repairFileList: [],
      repairUploadImgUrls: [],
      showRepairRecordForm: true,
    });

    // // 如果还没有获取维修人员列表，则获取
    // if (this.data.userList.length === 0) {
    //     this.getUserList();
    // }
  },
  // 显示维修表单
  showYesRepairForm(e) {
    console.log(e.currentTarget.dataset.item);
    const id = e.currentTarget.dataset.item.id;
    util
      .request(
        'admin/repair.Device/editStatus',
        {
          id,
          status: 2,
        },
        'POST',
      )
      .then((res) => {
        if (res.data.code === 200) {
          Toast.success('确认维修成功');
          this.getTaskList();
        } else {
          Toast.fail(res.data.msg || '确认维修失败');
        }
      })
      .catch(() => {
        Toast.fail('接口请求失败');
      });
  },

  // 关闭维修表单
  closeRepairForm() {
    this.setData({
      showRepairRecordForm: false,
    });
  },

  // 维修详情变化
  onDetailChange(e) {
    this.setData({
      'repairForm.detail': e.detail,
    });
  },

  // 维修人员变化
  onUserChange(e) {
    this.setData({
      'repairForm.user': e.detail,
    });
  },

  // onUserChange(e) {
  //     console.log(e.detail.value);
  //     const index = e.detail.value;
  //     const selectedUser = this.data.userList[index];
  //     this.setData({
  //         'repairForm.user': selectedUser.nickname
  //     });
  // },

  // 维修图片上传
  afterRepairRead(event) {
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
          repairFileList: [
            ...this.data.repairFileList,
            ...files.map((item, i) => ({
              url: flatUrls[i],
              name: item.name || `图片${this.data.repairFileList.length + i + 1}`,
              deletable: true,
            })),
          ],
          repairUploadImgUrls: [...this.data.repairUploadImgUrls, ...flatUrls],
        });
        Toast.success('上传成功');
      })
      .catch((err) => Toast.fail(err.message))
      .finally(() => Toast.clear());
  },

  // 删除维修图片
  deleteRepairImage(event) {
    const { index } = event.detail;
    const newFileList = this.data.repairFileList.filter((_, i) => i !== index);
    const newUploadUrls = this.data.repairUploadImgUrls.filter((_, i) => i !== index);

    this.setData({
      repairFileList: newFileList,
      repairUploadImgUrls: newUploadUrls,
    });
  },

  // 提交维修记录
  submitRepairRecord() {
    const {
      repairForm,
      repairUploadImgUrls,
      // selectedReasons
    } = this.data;

    if (!repairForm.detail) return Toast.fail('请输入维修详情');
    if (!repairForm.user) return Toast.fail('请选择维修人员');
    // if (selectedReasons.length === 0) return Toast.fail("请选择维修原因");

    this.setData({
      repairLoading: true,
    });

    // 提取原因ID数组或名称数组，根据后端要求调整
    // const reasonIds = selectedReasons.map(item => item.repair_name);

    util
      .request(
        'admin/repair.Device/addrecords',
        {
          device_code: repairForm.device_code,
          repair_code: repairForm.repair_code,
          detail: repairForm.detail,
          user: repairForm.user,
          repair_list: repairForm.repair_list, // 添加维修原因
          img: repairUploadImgUrls,
        },
        'POST',
      )
      .then((res) => {
        if (res.data.code === 200) {
          Toast.success('维修记录提交成功');
          this.closeRepairForm1();
          // 刷新任务列表
          this.getTaskList(this.data.currentPage);
          // 刷新设备列表
          this.getDeviceList(this.data.currentDevicePage);
        } else {
          Toast.fail(res.data.msg || '维修记录提交失败');
        }
      })
      .catch(() => Toast.fail('接口请求失败'))
      .finally(() =>
        this.setData({
          repairLoading: false,
        }),
      );
  },

  // 显示维修记录详情
  showRepairDetail(e) {
    console.log(e.currentTarget.dataset);
    wx.navigateTo({
      url: `/pageEquipment/pages/maintenanceRecord/maintenanceRecord?id=${e.currentTarget.dataset.item.id}`,
    });
    // this.setData({
    //     deviceName: e.currentTarget.dataset.device_name,
    //     currentDevice: e.currentTarget.dataset.detail,
    //     showRepairDetail: true
    // });
  },

  // 关闭维修记录详情
  closeRepairDetail() {
    this.setData({
      showRepairDetail: false,
    });
  },

  // 预览维修图片
  previewRepairImage(e) {
    const currentUrl = e.currentTarget.dataset.detail.src;
    const urls = [];

    wx.previewImage({
      current: currentUrl,
      urls: urls,
    });
  },

  // 导航栏宽高获取
  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({
          statusBarHeight: res.statusBarHeight,
          selectedReasons: [], // 初始化时清空
          selectedReasonsText: '', // 初始化时清空
        });
      },
    });
    this.getDeviceList();
    this.initButtonPosition();
    // 先获取大类列表，再获取报修原因
    this.getCategoryOptions().then(() => {
      this.getReasonList();
    });
  },

  // 获取大类列表（设备现场）
  getCategoryOptions() {
    return util.request('admin/production.ProductCount/category').then((res) => {
      if (res.data.code === 200 && res.data.data) {
        const list = res.data.data; // category 返回的是直接数组
        // 找到"设备现场"大类 ID
        const shebeiCategory = list.find((c) => c.repair_name === '设备现场');
        this.setData({
          targetParentId: shebeiCategory ? shebeiCategory.id : 0,
        });
      }
    });
  },

  onShow() {
    this.getDeviceList();
  },

  // 下拉刷新
  onPullDownRefresh() {
    if (this.data.activeTab === 0) {
      this.getDeviceList(1).finally(() => {
        wx.stopPullDownRefresh();
      });
    } else {
      this.getTaskList(1).finally(() => {
        wx.stopPullDownRefresh();
      });
    }
  },

  // 上拉加载更多
  onReachBottom() {
    if (this.data.activeTab === 0) {
      if (this.data.currentDevicePage < this.data.devicePages && !this.data.loadingMore) {
        this.loadMoreDevices();
      }
    } else {
      if (this.data.currentPage < this.data.pages && !this.data.loadingMore) {
        this.loadMoreTasks();
      }
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
  // 关闭维修表单
  closeRepairForm1() {
    this.setData({
      showRepairRecordForm: false,
    });
  },
});
