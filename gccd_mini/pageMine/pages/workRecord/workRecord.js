// pageMine/pages/wordRecord/wordRecord.js
const util = require("../../../utils/util.js");

// [AI-2026-08] 报工记录圆钢筛选与展示统一为四字段。
/** 同一次日工提交（相同提交时间+提交人）合并为一条，人员姓名并列展示 */
const mergeDailyWorkRecords = (list) => {
    const result = [];
    const groupMap = new Map();

    list.forEach((item) => {
        if (Number(item.is_daily_work) !== 1) {
            result.push(item);
            return;
        }

        const groupKey = `${item.create_time || ''}_${item.create_uid || ''}`;
        const workerName = (item.daily_work_user_name || item.product_user || '').trim();
        const existing = groupMap.get(groupKey);

        if (existing) {
            if (workerName && !existing._dailyUserNames.includes(workerName)) {
                existing._dailyUserNames.push(workerName);
                existing.daily_work_user_name = existing._dailyUserNames.join('、');
                existing.product_user = existing.daily_work_user_name;
            }
            return;
        }

        const merged = {
            ...item,
            _dailyUserNames: workerName ? [workerName] : [],
            daily_work_user_name: workerName,
            product_user: workerName,
        };
        groupMap.set(groupKey, merged);
        result.push(merged);
    });

    return result;
};

Page({
    data: {
        statusBarHeight: 0,
        list: [], // 报工列表数据
        page: 1,  // 当前页码
        limit: 10, // 每页条数
        loading: false, // 加载状态
        finished: false, // 是否已加载全部

        // 检索条件
        y_material_name: '',
        y_material_manufacturer: '',
        y_material_spec: '',
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
            y_material_name: '',
            y_material_manufacturer: '',
            y_material_spec: '',
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
        if (this.data.loading || this.data.finished) {
            return Promise.resolve();
        }

        this.setData({ loading: true });

        return util.request("admin/production.ProductReport/list", {
            page: this.data.page,
            limit: this.data.limit,
            create_uid: wx.getStorageSync('user_id') || '',
            y_material_name: this.data.y_material_name,
            y_material_manufacturer: this.data.y_material_manufacturer,
            y_material_spec: this.data.y_material_spec,
            y_material_model: this.data.y_material_model,
            c_material_spec: this.data.c_material_spec,
            c_material_model: this.data.c_material_model,
            create_start_date: this.data.create_start_date,
            create_end_date: this.data.create_end_date
        }).then(res => {
            if (res.data.code === 200) {
                const newList = res.data.data.list || [];
                const mergedNewList = mergeDailyWorkRecords(newList);
                const nextList = this.data.page === 1
                    ? mergedNewList
                    : [...this.data.list, ...mergedNewList];
                this.setData({
                    list: nextList,
                    finished: newList.length < this.data.limit || this.data.page >= res.data.data.pages
                });
            }
        }).finally(() => {
            this.setData({ loading: false });
        });
    },

    // 页面加载
    onLoad() {
        const windowInfo = wx.getWindowInfo ? wx.getWindowInfo() : wx.getSystemInfoSync();
        this.setData({
            statusBarHeight: windowInfo.statusBarHeight || 0,
        });
        this.getReportList();
    },

    // 下拉刷新
    onPullDownRefresh() {
        this.setData({ page: 1, finished: false, list: [] }, () => {
            this.getReportList().finally(() => {
                wx.stopPullDownRefresh();
            });
        });
    },

    // 上拉加载更多
    onReachBottom() {
        if (!this.data.finished && !this.data.loading) {
            this.setData({ page: this.data.page + 1 }, () => {
                this.getReportList();
            });
        }
    }
});
