const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

Page({
    data: {
        statusBarHeight: 0,
        loading: false,
        submitting: false,
        showSubmitSuccessDialog: false,
        id: '', // 从上个页面传递过来的task_id
        relation_code: '', // 从接口获取的关联单号
        routing_code: '', // 从接口获取的工艺编码
        routing_name: '', // 从接口获取的工艺名称
        process_code: '', // 从接口获取的工序编码
        processes_name: '', // 工序名称
        power_num: '', // 电力消耗
        gas_num: '', // 气力消耗
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({
            delta: 1
        });
    },

    // 普通输入框变化
    onInputChange(e) {
        const {
            field
        } = e.currentTarget.dataset;
        this.setData({
            [field]: e.detail
        });
    },

    // 验证表单
    validateForm() {
        const {
            power_num,
            gas_num
        } = this.data;

        if (!power_num || isNaN(power_num) || power_num <= 0) {
            Toast.fail('请输入有效的电力消耗');
            return false;
        }

        if (!gas_num || isNaN(gas_num) || gas_num <= 0) {
            Toast.fail('请输入有效的气力消耗');
            return false;
        }

        return true;
    },

    onSubmitSuccessConfirm() {
        this._submitLock = false;
        this.setData({
            submitting: false,
            showSubmitSuccessDialog: false
        });
        wx.navigateBack({
            delta: 1
        });
    },

    onSubmitSuccessDialogClose() {
        this._submitLock = false;
        this.setData({
            showSubmitSuccessDialog: false,
            submitting: false
        });
    },

    // 提交表单
    async submitForm() {
        if (this._submitLock || this.data.submitting) {
            return;
        }
        if (!this.validateForm()) {
            return;
        }

        this._submitLock = true;
        this.setData({
            loading: true,
            submitting: true
        });
        wx.showLoading({
            title: '提交中...',
            mask: true
        });

        try {
            const postData = {
                relation_code: this.data.relation_code,
                routing_code: this.data.routing_code,
                processes_code: this.data.process_code,
                power_num: this.data.power_num,
                gas_num: this.data.gas_num
            };

            const res = await util.request("admin/production.ProductReport/reportpower", postData, "POST");

            if (res.data.code === 200) {
                this.setData({
                    loading: false,
                    showSubmitSuccessDialog: true
                });
            } else {
                this._submitLock = false;
                Toast.fail(res.data.msg || '添加失败');
                this.setData({
                    loading: false,
                    submitting: false
                });
            }
        } catch (err) {
            console.error(err);
            this._submitLock = false;
            Toast.fail('接口请求失败，若网络不稳定可能已提交成功，请到列表核对后再试');
            this.setData({
                loading: false,
                submitting: false
            });
        } finally {
            wx.hideLoading();
        }
    },

    // 加载任务详情
    loadTaskDetail() {
        if (!this.data.id) {
            Toast.fail('任务ID不能为空');
            return Promise.reject('任务ID不能为空');
        }

        return util.request("admin/production.ProductTask/info", {
                id: this.data.id
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const taskInfo = res.data.data;
                    this.setData({
                        relation_code: taskInfo.task_code || '',
                        routing_code: taskInfo.routing_code || '',
                        routing_name: taskInfo.routing_name || '',
                        process_code: taskInfo.process_code || '',
                        processes_name: taskInfo.processes_name || '',
                    });
                } else {
                    Toast.fail('获取任务详情失败');
                    return Promise.reject(res.data.msg || '获取任务详情失败');
                }
            })
            .catch(err => {
                Toast.fail('接口请求失败');
                console.error(err);
                return Promise.reject(err);
            });
    },

    // 生命周期函数
    onLoad(options) {
        console.log(options);
        if (options.id && options.process_code && options.processes_name) {
            this.setData({
                id: options.id,
                process_code: options.process_code,
                processes_name: options.processes_name,
                routing_code: options.routing_code || '',
                routing_name: options.routing_name || ''
            }, () => {
                this.loadTaskDetail();
            });
        }

        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });
    }
});