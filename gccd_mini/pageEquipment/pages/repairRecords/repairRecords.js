// pages/repairRecords/repairRecords.js
const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

Page({
    data: {
        statusBarHeight: 0,
        deviceCode: '',
        deviceName: '',
        repairList: [],
        loading: false,
        currentPage: 1,
        pageSize: 10,
        pages: 1,
        count: 0
    },

    onLoad(options) {
        console.log(options);
        // 获取设备信息
        this.setData({
            deviceCode: options.device_code || '',
            deviceName: options.device_name || ''
        });

        // 获取导航栏高度
        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });

        // 加载维修记录
        this.getRepairList();
    },

    // 获取维修记录列表
    getRepairList(page = 1, isLoadMore = false) {
        if (!this.data.deviceCode) {
            Toast.fail("设备编码不能为空");
            return;
        }

        this.setData({
            loading: true
        });

        util.request("admin/repair.Device/repairlist", {
                device_code: this.data.deviceCode,
                limit: this.data.pageSize,
                page: page
            })
            .then((res) => {
                if (res.data.code === 200) {
                    const newList = res.data.data.list.map(item => {
                        return {
                            ...item,
                            imgs: item.imgs ? item.imgs.map(img => img.replace(/\\/g, '/')) : [] // 替换反斜杠
                        };
                    });

                    const mergedList = isLoadMore ? [...this.data.repairList, ...newList] : newList;
                    this.setData({
                        repairList: mergedList,
                        count: res.data.data.count,
                        pages: res.data.data.pages,
                        currentPage: page,
                        loading: false
                    });
                    console.log(this.data.repairList);
                } else {
                    this.setData({
                        loading: false
                    });
                    Toast.fail(res.data.msg || "获取维修记录失败");
                }
            })
            .catch(() => {
                this.setData({
                    loading: false
                });
                Toast.fail("接口请求失败");
            });
    },

    // 图片预览功能
    previewImage(e) {
        const {
            imgs,
            index
        } = e.currentTarget.dataset; // 改为接收index
        wx.previewImage({
            current: imgs[index], // 使用数组中的具体图片URL
            urls: imgs
        });
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack();
    },

    // 下拉刷新
    onPullDownRefresh() {
        this.getRepairList(1).finally(() => {
            wx.stopPullDownRefresh();
        });
    },

    // 上拉加载更多
    onReachBottom() {
        if (this.data.currentPage < this.data.pages && !this.data.loading) {
            const nextPage = this.data.currentPage + 1;
            this.getRepairList(nextPage, true);
        }
    }
});