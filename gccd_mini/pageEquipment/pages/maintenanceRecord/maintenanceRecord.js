const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

Page({
    data: {
        statusBarHeight: 0,
        repairInfo: {}, // 改为存储单个维修记录对象
        loading: false,
        id: '', // 存储从上个页面传递的id
        repairDuration: '',
    },

    onLoad(options) {
        console.log('页面参数:', options);
        // 获取设备信息
        this.setData({
            id: options.id || ''
        });

        // 获取导航栏高度
        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });

        // 加载维修记录详情
        this.getRepairInfo();
    },

    // 获取维修记录详情
    getRepairInfo() {
        if (!this.data.id) {
            Toast.fail("维修记录ID不能为空");
            return;
        }

        this.setData({
            loading: true
        });

        util.request("admin/repair.Device/repairinfo", {
                id: this.data.id
            })
            .then((res) => {
                if (res.data.code === 200) {
                    // 处理图片路径
                    const repairInfo = res.data.data; 
                    if (repairInfo.imgs) {
                        repairInfo.imgs = repairInfo.imgs.map(img => img.replace(/\\/g, '/'));
                    }
                    if (repairInfo.detail) {
                        repairInfo.detail.forEach(item => {
                            if (item.imgs) {
                                item.imgs = item.imgs.map(img => img.replace(/\\/g, '/'));
                            }
                        });
                    }

                    this.setData({
                        repairInfo: repairInfo,
                        loading: false
                    }, () => {
                        // 数据设置完成后计算维修时长
                        this.calculateRepairDuration();
                    });
                    // console.log('维修记录详情:', this.data.repairInfo);
                } else {
                    this.setData({
                        loading: false
                    });
                    Toast.fail(res.data.msg || "获取维修记录详情失败");
                }
            })
            .catch(() => {
                this.setData({
                    loading: false
                });
                Toast.fail("接口请求失败");
            });
    },

    // 计算维修时长
    calculateRepairDuration() {
        const { repairInfo } = this.data;
        
        // 只有已完成的状态(status=2)且有报修时间和维修完成时间才计算
        if (repairInfo.status === 2 && repairInfo.create_time && repairInfo.detail[0].create_time) {
            const startTime = new Date(repairInfo.create_time).getTime();
            const endTime = new Date(repairInfo.detail[0].create_time).getTime();
            const duration = endTime - startTime;
            
            // 转换为更易读的格式
            const durationText = this.formatDuration(duration);
            
            this.setData({
                repairDuration: durationText
            });
        }
    },

    // 格式化时长
    formatDuration(ms) {
        const seconds = Math.floor(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (days > 0) {
            return `${days}天${hours % 24}小时${minutes % 60}分钟`;
        } else if (hours > 0) {
            return `${hours}小时${minutes % 60}分钟`;
        } else {
            return `${minutes}分钟`;
        }
    },

    // 图片预览功能
    previewImage(e) {
        const {
            imgs,
            index
        } = e.currentTarget.dataset;
        wx.previewImage({
            current: imgs[index],
            urls: imgs
        });
    },
    // 图片预览功能（维修图片）
    previewRepairImage(e) {
        const {
            imgs,
            index
        } = e.currentTarget.dataset;
        wx.previewImage({
            current: imgs[index],
            urls: imgs
        });
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack();
    },

    // 下拉刷新
    onPullDownRefresh() {
        this.getRepairInfo().finally(() => {
            wx.stopPullDownRefresh();
        });
    }
});