const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

Page({
    data: {
        statusBarHeight: 0,
        todayDate: '',
        todayWeekday: '',

        // 签到状态
        signedIn: false,
        record: null,

        // 工时
        workHours: 12,

        // 提交状态
        submitting: false,

        // 历史记录
        historyList: [],
        page: 1,
        limit: 20,
        loading: false,
        finished: false,

        // 本月汇总
        monthSummary: null,
    },

    // 格式化日期
    formatDate(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}年${m}月${d}日`;
    },

    // 获取星期
    getWeekday(date) {
        const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        return '星期' + weekdays[date.getDay()];
    },

    // 获取当前月份 Y-m 格式
    getCurrentMonth() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    },

    // 获取今日状态
    fetchTodayStatus() {
        return util.request("admin/member.SupervisorAttendance/today").then(res => {
            if (res.data.code === 200) {
                const data = res.data.data;
                const record = data.record || null;
                this.setData({
                    signedIn: data.signed_in,
                    record: record,
                    workHours: record ? record.work_hours : 12,
                });
            }
        });
    },

    // 获取本月汇总
    fetchMonthSummary() {
        util.request("admin/member.SupervisorAttendance/summary", {
            month: this.getCurrentMonth()
        }).then(res => {
            if (res.data.code === 200) {
                const data = res.data.data;
                if (data && data.month_days > 0) {
                    data.attendance_rate = Math.round(data.signed_days / data.month_days * 100) + '%';
                } else {
                    data.attendance_rate = '0%';
                }
                this.setData({ monthSummary: data });
            }
        });
    },

    // 获取历史记录
    fetchHistory(page = 1, append = false) {
        if (this.data.loading || (append && this.data.finished)) return;

        this.setData({ loading: true });

        util.request("admin/member.SupervisorAttendance/history", {
            page: page,
            limit: this.data.limit
        }).then(res => {
            if (res.data.code === 200) {
                const list = res.data.data.list || [];
                this.setData({
                    historyList: append ? [...this.data.historyList, ...list] : list,
                    finished: list.length < this.data.limit,
                    page: page
                });
            }
        }).finally(() => {
            this.setData({ loading: false });
        });
    },

    // 签到
    handleSignIn() {
        const hours = this.data.workHours;
        if (!hours || hours <= 0) {
            Toast.fail('请填写有效的工时');
            return;
        }

        this.setData({ submitting: true });

        util.request("admin/member.SupervisorAttendance/signIn", {
            work_hours: hours
        }).then(res => {
            if (res.data.code === 200) {
                Toast.success('签到成功');
                this.fetchTodayStatus();
                this.fetchHistory(1, false);
                this.fetchMonthSummary();
            } else {
                Toast.fail(res.data.msg || '签到失败');
            }
        }).finally(() => {
            this.setData({ submitting: false });
        });
    },

    // 减少工时
    decreaseHours() {
        let hours = this.data.workHours;
        if (hours <= 1) return;
        this.setData({ workHours: Math.round((hours - 0.5) * 10) / 10 });
    },

    // 增加工时
    increaseHours() {
        let hours = this.data.workHours;
        if (hours >= 24) return;
        this.setData({ workHours: Math.round((hours + 0.5) * 10) / 10 });
    },

    // 工时输入
    onHoursInput(e) {
        let val = parseFloat(e.detail.value) || 0;
        if (val < 0) val = 0;
        if (val > 24) val = 24;
        this.setData({ workHours: val });
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({ delta: 1 });
    },

    // 页面加载
    onLoad() {
        wx.getSystemInfo({
            success: (res) => {
                this.setData({ statusBarHeight: res.statusBarHeight });
            },
        });

        const now = new Date();
        this.setData({
            todayDate: this.formatDate(now),
            todayWeekday: this.getWeekday(now),
        });
    },

    onShow() {
        this.fetchTodayStatus();
        this.fetchHistory(1, false);
    },

    // 下拉刷新
    onPullDownRefresh() {
        Promise.all([
            this.fetchTodayStatus(),
            this.fetchHistory(1, false)
        ]).finally(() => {
            wx.stopPullDownRefresh();
        });
    },
});
