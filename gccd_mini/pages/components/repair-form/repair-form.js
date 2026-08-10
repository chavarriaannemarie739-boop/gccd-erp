const util = require('../../../utils/util.js');
const mediaCompress = require('../../../utils/mediaCompress.js');
import Toast from '@vant/weapp/toast/toast';

Component({
  properties: {
    show: {
      type: Boolean,
      value: false,
    },
    title: {
      type: String,
      value: '设备报修',
    },
    device_code: {
      type: String,
      value: '',
    },
  },

  data: {
    reason: '',
    fileList: [],
    uploadImgUrls: [],
    loading: false,
    // 报修原因多选相关
    selectedReasons: [], // 选中的原因对象数组
    selectedReasonsText: '', // 选中的原因文本显示
    showReasonPopup: false, // 是否显示原因选择弹窗
    reasonColumns: [
      {
        values: [], // 原因名称数组
        defaultIndex: 0,
      },
    ],
    reasonList: [], // 完整的原因列表
    reasonListLoaded: false,
  },

  observers: {
    show(show) {
      if (show && !this.data.reasonListLoaded) {
        this.getReasonList();
      }
    },
  },

  methods: {
    // 获取报修原因列表（只显示大类别为"设备现场"的子项）
    getReasonList() {
      // 先获取大类列表，找到"设备现场"的ID
      util
        .request('admin/production.ProductCount/category')
        .then((res) => {
          if (res.data.code === 200 && res.data.data) {
            const categories = res.data.data;
            const shebeiCategory = categories.find((c) => c.repair_name === '设备现场');
            const parentId = shebeiCategory ? shebeiCategory.id : 0;

            // 用parent_id过滤，只取"设备现场"下的子项
            return util.request('admin/production.ProductCount/list', {
              limit: 100,
              page: 1,
              parent_id: parentId,
            });
          }
          return Promise.reject(new Error('获取大类失败'));
        })
        .then((res) => {
          if (res.data.code === 200) {
            const reasons = res.data.data.list || [];
            const pickerValues = reasons.map((item) => item.repair_name);
            this.setData({
              reasonList: reasons,
              reasonListLoaded: true,
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

    // 添加这个方法
    onRepairSuccess() {
      // 处理报修成功后的逻辑
      Toast.success('报修成功');
      this.setData({
        showRepairForm: false,
      });
      // 刷新数据
      this.getDeviceList();
      this.getTaskList();
    },

    // 显示原因选择弹窗
    showReasonPicker() {
      if (!this.data.reasonList || this.data.reasonList.length === 0) {
        Toast.fail('暂无可用报修原因');
        return;
      }
      this.setData({
        showReasonPopup: true,
      });
    },

    // 确认选择原因
    onReasonConfirm(event) {
      const { value, index } = event.detail;
      const selectedReason = this.data.reasonList[index];

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

    // 取消选择原因
    onReasonCancel() {
      this.setData({
        showReasonPopup: false,
      });
    },

    onClose() {
      this.triggerEvent('close');
      this.setData({
        reason: '',
        fileList: [],
        uploadImgUrls: [],
        selectedReasons: [],
        selectedReasonsText: '',
      });
    },

    onReasonChange(e) {
      this.setData({
        reason: e.detail,
      });
    },

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

    deleteImage(event) {
      const { index } = event.detail;
      const newFileList = this.data.fileList.filter((_, i) => i !== index);
      const newUploadUrls = this.data.uploadImgUrls.filter((_, i) => i !== index);

      this.setData({
        fileList: newFileList,
        uploadImgUrls: newUploadUrls,
      });
    },

    submitRepair() {
      const { device_code, reason, uploadImgUrls, selectedReasons } = this.data;

      console.log(selectedReasons);
      if (!device_code) return Toast.fail('请输入设备编码');
      if (selectedReasons.length === 0) return Toast.fail('请选择报修原因');

      // 提取原因ID数组（假设reasonList中的项有id字段）
      const reasonIds = selectedReasons.map((item) => item.repair_name); // 这里改为item.id而不是repair_name

      this.setData({
        loading: true,
      });

      util
        .request(
          'admin/repair.Device/report',
          {
            device_code,
            reason: reason || '', // 补充说明，可为空
            img: uploadImgUrls, // 直接使用上传后的URL数组
            repair_list: reasonIds, // 维修项ID数组
          },
          'POST',
        )
        .then((res) => {
          if (res.data.code === 200) {
            Toast.success('报修成功');
            this.onClose();
            this.triggerEvent('success');
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
  },
});
