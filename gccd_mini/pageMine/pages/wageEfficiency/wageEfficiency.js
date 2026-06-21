import * as echarts from '../../components/ec-canvas/echarts';
const util = require("../../../utils/util.js");

function initDefectChart(canvas, width, height, dpr) {
    const chart = echarts.init(canvas, null, {
        width: width,
        height: height,
        devicePixelRatio: dpr
    });
    canvas.setChart(chart);
    return chart;
}

const DEVICE_TABLE_PREVIEW = 5

const formatDeviceRow = (item) => {
    const st = item.status
    const isNormal = st === 0 || st === '0'
    return {
        id: item.id,
        device_name: item.device_name || '—',
        device_code: item.device_code,
        status: st,
        statusText: isNormal ? '正常' : '维修中',
    }
}

const FILTER_LABELS = {
    total: '全部',
    normal: '正常',
    work: '运行中',
    no_start: '未启动（维修中）',
}

const filterDevicesByStatusKey = (list, key) => {
    const rows = list || []
    if (key === 'total') return rows
    if (key === 'normal' || key === 'work') {
        return rows.filter((d) => d.status === 0 || d.status === '0')
    }
    if (key === 'no_start') {
        return rows.filter((d) => d.status !== 0 && d.status !== '0')
    }
    return rows
}

Page({
    data: {
        statusBarHeight: 0,
        deviceStatus: {
            device_count: 0,
            normal: 0,
            work: 0,
            no_start: 0,
            question: 0
        },
        deviceTableList: [],
        deviceTablePreview: [],
        deviceTableShowMore: false,
        deviceTableExpanded: false,
        deviceTableLoading: false,
        deviceTableListAll: [],
        deviceStatusFilter: 'total',
        deviceStatusFilterLabel: '全部',
        deviceTableSectionSub: '',
        deviceTableTotal: 0,

        // 昨日产量
        yesterdayLabel: '',
        yesterdayOutputs: { total: 0, items: [] },

        // 生产计划
        plans: [],

        // 质量
        defectPie: [],
        defectHasData: false,
        defectMonthMeta: { report_defective_sum: 0, qc_defective_sum: 0 },
        defectTotalMonth: 0,
        defectEc: { onInit: initDefectChart },

        // 能耗
        energy: {
            yesterday_label: '',
            month_label: '',
            yesterday: { gas_consume: 0, power_consume: 0 },
            month: { gas_consume: 0, power_consume: 0 }
        }
    },

    navigateBack() {
        wx.navigateBack({ delta: 1 });
    },

    /* ==================== 数据加载 ==================== */

    loadDashboard() {
        util.request("admin/wechat.WeChat/dashboardBoard").then(res => {
            if (res.data.code === 200) {
                const d = res.data.data;
                const energy = d.energy || {};
                const defectPie = d.defect_pie_month || [];
                const defectMeta = d.defect_month_meta || {};
                const hasDefect = defectPie.some(item => (item.value || 0) > 0);

                this.setData({
                    yesterdayLabel: d.energy ? d.energy.yesterday_label : '',
                    yesterdayOutputs: d.yesterday_outputs || { total: 0, items: [] },
                    plans: (d.plans || []).slice(0, 8),
                    defectPie: defectPie,
                    defectHasData: hasDefect,
                    defectMonthMeta: {
                        report_defective_sum: defectMeta.report_defective_sum || 0,
                        qc_defective_sum: defectMeta.qc_defective_sum || 0
                    },
                    defectTotalMonth: (defectMeta.report_defective_sum || 0) + (defectMeta.qc_defective_sum || 0),
                    energy: {
                        yesterday_label: energy.yesterday_label || '',
                        month_label: energy.month_label || '',
                        yesterday: energy.yesterday || { gas_consume: 0, power_consume: 0 },
                        month: energy.month || { gas_consume: 0, power_consume: 0 }
                    }
                }, () => {
                    if (hasDefect) {
                        this.initDefectChart();
                    }
                });
            }
        });
    },

    loadDeviceStatus() {
        util.request("admin/wechat.WeChat/index").then(res => {
            if (res.data.code === 200) {
                const data = res.data.data;
                this.setData({
                    deviceStatus: data.device_status
                });
            }
        });
    },

    loadDeviceTable() {
        this.setData({ deviceTableLoading: true });
        util.request(
            'admin/repair.Device/list',
            { page: 1, limit: 500 },
            'POST'
        )
            .then((res) => {
                const raw = (res.data.data && res.data.data.list) || [];
                const deviceTableListAll = raw.map(formatDeviceRow);
                this.setData(
                    { deviceTableListAll, deviceTableLoading: false },
                    () => this.applyDeviceStatusFilter()
                );
            })
            .catch(() => {
                this.setData({ deviceTableLoading: false });
            });
    },

    /* ==================== 设备明细 ==================== */

    applyDeviceStatusFilter() {
        const all = this.data.deviceTableListAll || [];
        const key = this.data.deviceStatusFilter || 'total';
        const filtered = filterDevicesByStatusKey(all, key);
        const deviceTablePreview = filtered.slice(0, DEVICE_TABLE_PREVIEW);
        const deviceTableShowMore = filtered.length > DEVICE_TABLE_PREVIEW;
        const deviceStatusFilterLabel = FILTER_LABELS[key] || FILTER_LABELS.total;
        this.setData(
            {
                deviceTableList: filtered,
                deviceTablePreview,
                deviceTableShowMore,
                deviceTableTotal: filtered.length,
                deviceTableExpanded: false,
                deviceStatusFilterLabel,
            },
            () => this.refreshDeviceTableSectionSub()
        );
    },

    refreshDeviceTableSectionSub() {
        const key = this.data.deviceStatusFilter || 'total';
        const label = this.data.deviceStatusFilterLabel || FILTER_LABELS.total;
        const n = this.data.deviceTableTotal;
        const showMore = this.data.deviceTableShowMore;
        const expanded = this.data.deviceTableExpanded;
        let sub = `共 ${n} 台`;
        if (key !== 'total') sub += ` · 筛选：${label}`;
        if (showMore) sub += expanded ? ' · 已展开' : ' · 点击展开全部';
        this.setData({ deviceTableSectionSub: sub });
    },

    onDeviceStatusTap(e) {
        const key = e.currentTarget.dataset.key;
        if (!key) return;
        if (key === this.data.deviceStatusFilter) return;
        this.setData({ deviceStatusFilter: key }, () => this.applyDeviceStatusFilter());
    },

    toggleDeviceTableExpand() {
        if (!this.data.deviceTableShowMore) return;
        this.setData({ deviceTableExpanded: !this.data.deviceTableExpanded }, () =>
            this.refreshDeviceTableSectionSub()
        );
    },

    /* ==================== 图表 ==================== */

    initDefectChart() {
        const component = this.selectComponent('#defectChart');
        if (!component) return;
        component.init((canvas, width, height, dpr) => {
            const chart = initDefectChart(canvas, width, height, dpr);
            const pieData = this.data.defectPie || [];
            const total = pieData.reduce((sum, item) => sum + (item.value || 0), 0);

            chart.setOption({
                tooltip: {
                    trigger: 'item',
                    formatter: function (p) {
                        const pct = total > 0 ? ((p.value / total) * 100).toFixed(1) : 0;
                        return p.name + '：' + p.value + ' 个 (' + pct + '%)';
                    }
                },
                legend: { bottom: 0 },
                series: [{
                    name: '不良品来源',
                    type: 'pie',
                    radius: ['40%', '70%'],
                    center: ['50%', '45%'],
                    avoidLabelOverlap: false,
                    itemStyle: { borderRadius: 6, borderColor: '#fff', borderWidth: 2 },
                    label: { show: true, formatter: '{b}\n{d}%' },
                    data: pieData.map(item => ({
                        name: item.name,
                        value: item.value
                    }))
                }]
            });
            return chart;
        });
    },

    /* ==================== 生命周期 ==================== */

    onLoad() {
        wx.getSystemInfo({
            success: (res) => {
                this.setData({ statusBarHeight: res.statusBarHeight });
            },
        });
        this.loadDashboard();
        this.loadDeviceStatus();
        this.loadDeviceTable();
    }
});
