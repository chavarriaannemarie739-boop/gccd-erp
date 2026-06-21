// pageMine/pages/wordRecord/wordRecord.js
const util = require("../../../utils/util.js");

Page({
    data: {
        statusBarHeight: 0,
        list: [], // 报工列表数据
        page: 1,  // 当前页码
        limit: 10, // 每页条数
        loading: false, // 加载状态
        finished: false, // 是否已加载全部
        
        // 检索条件
        y_material_model: '',
        c_material_spec: '',
        c_material_model: '',
        create_start_date: '',
        create_end_date: ''
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({ delta: 1 });
    },

    bindStartDateChange(e) {
        this.setData({ create_start_date: e.detail.value });
    },

    bindEndDateChange(e) {
        this.setData({ create_end_date: e.detail.value });
    },

    onSearch() {
        this.setData({
            page: 1,
            finished: false,
            list: []
        }, () => {
            this.getReportList();
        });
    },

    onReset() {
        this.setData({
            y_material_model: '',
            c_material_spec: '',
            c_material_model: '',
            create_start_date: '',
            create_end_date: '',
            page: 1,
            finished: false,
            list: []
        }, () => {
            this.getReportList();
        });
    },

    // 获取报工列表
    getReportList() {
        if (this.data.loading || this.data.finished) return;

        this.setData({ loading: true });
        
        util.request("admin/production.ProductReport/list", {
            page: this.data.page,
            limit: this.data.limit,
            create_uid: wx.getStorageSync('user_id') || '',
            y_material_model: this.data.y_material_model,
            c_material_spec: this.data.c_material_spec,
            c_material_model: this.data.c_material_model,
            create_start_date: this.data.create_start_date,
            create_end_date: this.data.create_end_date
        }).then(res => {
            if (res.data.code === 200) {
                const newList = res.data.data.list || [];
                this.setData({
                    list: this.data.page === 1 ? newList : [...this.data.list, ...newList],
                    finished: newList.length < this.data.limit || this.data.page >= res.data.data.pages
                });
            }
        }).finally(() => {
            this.setData({ loading: false });
        });
    },

    // 页面加载
    onLoad() {
        wx.getSystemInfo({
            success: (res) => {
                this.setData({ statusBarHeight: res.statusBarHeight });
            },
        });
        this.getReportList();
    },

    // 下拉刷新
    onPullDownRefresh() {
        this.setData({ page: 1, finished: false }, () => {
            this.getReportList().finally(() => {
                wx.stopPullDownRefresh();
            });
        });
    },

    // 上拉加载更多
    onReachBottom() {
        if (!this.data.finished) {
            this.setData({ page: this.data.page + 1 }, () => {
                this.getReportList();
            });
        }
    }
});