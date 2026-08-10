const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

// [AI-2026-08] 工单详情纠正圆钢四字段标签与绑定。
Page({
    data: {
        statusBarHeight: 0,
        loading: false,
        id: '', // 工单ID
        processes_name: '', // 任务编号
        task_code: '', // 任务编号
        relation_code: '', // 关联编号
        routing_code: '', // 工艺编码
        material_id: '', // 物料ID
        task_num: '', // 任务数量
        report_num: '', // 报工数量
        processes_code: '', // 工序编号
        status: 0, // 工单状态
        statusText: '', // 状态文本
        create_time: '', // 创建时间
        materialName: '', // 物料名称
        production_plan_name: '', // 计划名称
        c_material_name:'',
        c_material_spec:'',
        c_material_model:'',
        y_material_name:'',
        y_material_manufacturer:'',
        y_material_spec:'',
        y_material_model:'',
        // 按钮样式
        btnPosition: {
            top: 0,
            left: 100
        },
        startX: 0,
        startY: 0,
        btnStartX: 0,
        btnStartY: 0,
        ids: '',
        processes_code: ''
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({
            delta: 1
        });
    },

    goWorkOrderAdd(e) {
        // console.log(e);
        const id = e.currentTarget.dataset.id;
        const material_id = e.currentTarget.dataset.material_id;

        wx.navigateTo({
            url: `/pageWorkOrder/pages/workOrderAdd/workOrderAdd?id=${id}&material_id=${material_id}`
        });
    },

    // 获取工单详情
    getTaskDetail(id, processes_code) {
        this.setData({
            loading: true
        });

        util.request(`admin/production.ProductTask/info`, {
                id: id,
                // processes_code: processes_code
            }, "POST")
            .then(res => {
                if (res.data.code === 200) {
                    const data = res.data.data;
                    this.getMaterialName(data.material_id);

                    // 格式化状态文本
                    let statusText = '';
                    switch (data.status) {
                        case 0:
                            statusText = '未生产';
                            break;
                        case 1:
                            statusText = '生产中';
                            break;
                        case 2:
                            statusText = '生产完成';
                            break;
                        default:
                            statusText = '未知状态';
                    }

                    this.setData({
                        id: data.id,
                        processes_name: data.processes_name,
                        task_code: data.task_code,
                        process_code: data.process_code,
                        relation_code: data.relation_code,
                        routing_code: data.routing_code,
                        routing_name: data.routing_name,
                        material_id: data.material_id,
                        task_num: data.task_num,
                        report_num: data.report_num,
                        defective_num: data.defective_num,
                        processes_code: data.processes_code || '',
                        status: data.status,
                        statusText: statusText,
                        create_time: data.create_time,
                        production_plan_name: data.production_plan_name,
                        y_material_name: data.y_material_name || '',
                        y_material_manufacturer: data.y_material_manufacturer || '',
                        y_material_spec: data.y_material_spec || '',
                        y_material_model: data.y_material_model || '',
                        c_material_name: data.c_material_name,
                        c_material_spec: data.c_material_spec,
                        c_material_model: data.c_material_model,
                        loading: false
                    });
                } else {
                    Toast.fail(res.data.msg || '获取详情失败');
                    this.setData({
                        loading: false
                    });
                }
            })
            .catch(err => {
                Toast.fail('接口请求失败');
                console.error(err);
                this.setData({
                    loading: false
                });
            });
    },

    // 获取物料名称
    getMaterialName(materialId) {
        util.request("admin/production.Material/info", {
                id: materialId
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const materialInfo = res.data.data;
                    const materialName = `${materialInfo.material_name || ''}（ ${materialInfo.material_spec || ''}）`.trim();
                    this.setData({
                        materialName,
                        y_material_name: this.data.y_material_name || materialInfo.material_name || '',
                        y_material_manufacturer: this.data.y_material_manufacturer || materialInfo.manufacturer || '',
                        y_material_spec: this.data.y_material_spec || materialInfo.material_spec || '',
                        y_material_model: this.data.y_material_model || materialInfo.material_model || ''
                    });
                }
            })
            .catch(err => {
                console.error('获取物料名称失败', err);
            });
    },

    // 生命周期函数
    onLoad(options) {
        console.log(options);
        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });
        this.initButtonPosition();
        // 从options中获取ID参数
        if (options.id) {
            this.getTaskDetail(options.id, options.processes_code);
        }
        this.setData({
            ids: options.id,
            processes_code: options.processes_code
        })
    },
    onShow() {
        this.getTaskDetail(this.data.ids, this.data.processes_code);
    },

    // 拖动按钮逻辑
    touchStart(e) {
        this.setData({
            startX: e.touches[0].clientX,
            startY: e.touches[0].clientY,
            btnStartX: this.data.btnPosition.left,
            btnStartY: this.data.btnPosition.top
        });
    },

    touchMove(e) {
        const {
            startX,
            startY,
            btnStartX,
            btnStartY
        } = this.data;
        const touchX = e.touches[0].clientX;
        const touchY = e.touches[0].clientY;

        this.setData({
            btnPosition: {
                left: btnStartX + (touchX - startX),
                top: btnStartY + (touchY - startY)
            }
        });
    },

    touchEnd() {
        const systemInfo = wx.getSystemInfoSync();
        const screenWidth = systemInfo.windowWidth;
        const screenHeight = systemInfo.windowHeight;
        const btnSize = 100; // 按钮宽高

        this.setData(prev => {
            let newTop = prev.btnPosition.top;
            let newLeft = prev.btnPosition.left;

            // 边界检查
            if (newTop < 0) newTop = 0;
            if (newLeft < 0) newLeft = 0;
            if (newTop > screenHeight - btnSize) newTop = screenHeight - btnSize;
            if (newLeft > screenWidth - btnSize) newLeft = screenWidth - btnSize;

            return {
                btnPosition: {
                    top: newTop,
                    left: newLeft
                }
            };
        });
    },

    // 初始化按钮位置
    initButtonPosition() {
        const systemInfo = wx.getSystemInfoSync();
        const navbarHeight = 44; // 导航栏固定高度
        const statusBarHeight = this.data.statusBarHeight;

        this.setData({
            btnPosition: {
                top: systemInfo.windowHeight - 150 - navbarHeight - statusBarHeight,
                left: systemInfo.windowWidth - 120
            }
        });
    },
});