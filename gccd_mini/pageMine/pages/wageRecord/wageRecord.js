const util = require("../../../utils/util.js");

Page({
    data: {
        statusBarHeight: 0,
        list: [], // 工资列表数据
        page: 1, // 当前页码
        limit: 10, // 每页条数
        loading: false, // 加载状态
        finished: false, // 是否已加载全部
        // 日历
        spotMap: {
            y2022m5d9: 'deep-spot',
            y2022m5d10: 'spot',
            y2022m6d10: 'spot',
            y2022m7d10: 'spot',
            y2022m8d10: 'spot',
            y2022m10d1: 'spot',
            y2023m5d10: 'spot',
        },
        // 需要改变日期时所使用的字段
        // changeTime: '',
        // 存储已经获取过的日期
        dateListMap: [],
    },

    // 获取日期数据，通常用来请求后台接口获取数据
    getDateList({
        detail
    }) {
        // 检查是否已经获取过该月的数据
        if (this.filterGetList(detail)) {
            // 获取数据
            console.log(detail, '获取数据');
        }
    },
    // 过滤重复月份请求的方法
    filterGetList({
        setYear,
        setMonth
    }) {
        const dateListMap = new Set(this.data.dateListMap);
        const key = `y${setYear}m${setMonth}`;
        if (dateListMap.has(key)) {
            return false;
        }
        dateListMap.add(key);
        this.setData({
            dateListMap: [...dateListMap],
        });
        return true;
    },
    // 日期改变的回调
    selectDay({
        detail
    }) {
        console.log(detail, 'selectDay detail');
    },
    // 展开收起时的回调
    openChange({
        detail
    }) {
        console.log(detail, 'openChange detail');
    },
    // changetime() {
    //     this.setData({
    //         changeTime: '2022/1/1',
    //     });
    // },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({
            delta: 1
        });
    },

    // 获取工资列表
    getWageList() {
        if (this.data.loading || this.data.finished) return;

        this.setData({
            loading: true
        });

        const user_id = wx.getStorageSync('user_id') || '';
        util.request("admin/wage.UserWage/list", {
            page: this.data.page,
            limit: this.data.limit,
            user_id: user_id
        }).then(res => {
            if (res.data.code === 200) {
                const newList = res.data.data.list || [];
                this.setData({
                    list: this.data.page === 1 ? newList : [...this.data.list, ...newList],
                    finished: newList.length < this.data.limit || this.data.page >= res.data.data.pages
                });
            }
        }).finally(() => {
            this.setData({
                loading: false
            });
        });
    },

    // 页面加载
    onLoad() {
        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });
        this.getWageList();
    },

    // 下拉刷新
    onPullDownRefresh() {
        this.setData({
            page: 1,
            finished: false
        }, () => {
            this.getWageList().finally(() => {
                wx.stopPullDownRefresh();
            });
        });
    },

    // 上拉加载更多
    onReachBottom() {
        if (!this.data.finished) {
            this.setData({
                page: this.data.page + 1
            }, () => {
                this.getWageList();
            });
        }
    }
});