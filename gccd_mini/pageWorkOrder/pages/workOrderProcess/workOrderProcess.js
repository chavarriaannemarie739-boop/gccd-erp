const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

Page({
    data: {
        statusBarHeight: 0,
        loading: false,
        loadFailed: false, // 加载失败标志
        processList: [], // 工序列表数据
        routing_name: [], // 工序列表数据
        routing_code: '', // 当前工艺编码（可用于刷新）
        id: '', //传递给报工页面
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({
            delta: 1
        });
    },

    // 处理报工按钮点击
    handleReport(e) {
        const item = e.currentTarget.dataset.item;
        // console.log('提交报工:', item);

        wx.navigateTo({ 
            url: `/pageWorkOrder/pages/workOrderAdd/workOrderAdd?id=${this.data.id}&process_code=${item.process_code}&processes_name=${encodeURIComponent(item.processes_name || '')}`
        });

    },
    // 添加能耗
    handleEnergy(e) {
        const item = e.currentTarget.dataset.item;
        console.log('添加能耗:', item);

        wx.navigateTo({ 
            url: `/pageWorkOrder/pages/energyAdd/energyAdd?id=${this.data.id}&process_code=${item.process_code}&processes_name=${item.processes_name}`
        });

    },

    // 获取工序列表
    getProcessList() {
        this.setData({
            loading: true,
            loadFailed: false
        });

        util.request(`admin/production.Routing/getProcess?routing_code=${this.data.routing_code}`, {}, "POST")
            .then(res => {
                if (res.data.code === 200) {
                    this.setData({
                        processList: res.data.data.content || [],
                        routing_name: res.data.data.routing_name || [],
                        loading: false,
                        loadFailed: false
                    });
                } else {
                    Toast.fail(res.data.msg || '获取工序列表失败');
                    this.setData({
                        loading: false,
                        loadFailed: true
                    });
                }
            })
            .catch(err => {
                Toast.fail('网络不佳，请点击重试');
                console.error(err);
                this.setData({
                    loading: false,
                    loadFailed: true
                });
            });
    },

    // 重试
    retryLoad() {
        this.getProcessList();
    },

    // 生命周期函数
    onLoad(options) {
        console.log(options);
        this._isFirstShow = true;
        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });

        // 从options中获取routing_code参数
        if (options.routing_code) {
            this.setData({
                routing_code: options.routing_code,
                id: options.id
            }, () => {
                this.getProcessList();
            });
        }
    },

    onShow() {
        // 首次进入时 onLoad 已经调用过，跳过避免重复请求
        if (this._isFirstShow) {
            this._isFirstShow = false;
            return;
        }
        // 从报工页面返回时刷新数据
        if (this.data.routing_code) {
            this.getProcessList();
        }
    }
});