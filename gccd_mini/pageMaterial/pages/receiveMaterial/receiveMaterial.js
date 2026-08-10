const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

// [AI-2026-08] 圆钢领料按档案 ID 选择，四字段用于展示与筛选。
Page({
    data: {
        statusBarHeight: 0,
        loading: false,
        submitting: false,
        showSubmitSuccessDialog: false,
        loadingMore: false,
        hasMore: true,
        searchValue: '',
        searchParams: {
            material_name: '',
            manufacturer: '',
            material_spec: '',
            material_model: ''
        },
        materialList: [], // 当前显示的物料列表
        originalMaterialList: [], // 所有物料列表（用于搜索过滤）
        selectedMaterials: [], // 选中的物料ID数组
        selectedMaterialsInfo: [], // 选中的物料详情及数量
        show: false,
        // 分页参数
        currentPage: 1,
        pageSize: 50, // 增加每页条数以确保能够触发触底加载
        totalPages: 1,
        totalCount: 0,
        warehouseType: '' // 圆钢库 或 配件库
    },

    showPopup() {
        if (!this.data.selectedMaterialsInfo.length > 0) {
            Toast('请选择至少一种物料');
            return;
        }
        this.setData({
            show: true
        });
    },

    onClose() {
        this.setData({
            show: false
        });
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({
            delta: 1
        });
    },

    // 生命周期函数
    onLoad(options) {
        wx.getSystemInfo({
            success: (res) => {
                this.setData({
                    statusBarHeight: res.statusBarHeight
                });
            },
        });
        
        // 接收类型参数
        if (options.type) {
            this.setData({
                warehouseType: options.type
            });
            wx.setNavigationBarTitle({
                title: options.type === '圆钢库' ? '圆钢领料申请' : '配件领料申请'
            });
        }
        
        // 获取物料列表
        this.getMaterialList();
    },

    // 获取物料列表
    getMaterialList(page = 1, isLoadMore = false) {
        if (this.data.loading || (isLoadMore && !this.data.hasMore)) return;

        this.setData({
            loading: !isLoadMore,
            loadingMore: isLoadMore
        });

        const params = {
            limit: this.data.pageSize,
            page: page,
            ...this.data.searchParams,
            warehouse: this.data.warehouseType // 传递仓库类型
        };

        util.request("admin/stock.InStock/mateiallist", params, "POST")
            .then(res => {
                if (res.data.code === 200) {
                    const newList = res.data.data.list || [];
                    const materialList = isLoadMore ? [...this.data.materialList, ...newList] :
                        newList;

                    // 如果是搜索，不保存 originalMaterialList
                    const hasSearch = Object.values(this.data.searchParams).some(value => value);
                    if (!hasSearch) {
                        this.setData({
                            originalMaterialList: newList
                        });
                    }

                    this.setData({
                        materialList,
                        currentPage: page,
                        totalPages: res.data.data.pages || 1,
                        totalCount: res.data.data.count || 0,
                        hasMore: page < (res.data.data.pages || 1),
                        loading: false,
                        loadingMore: false
                    });
                } else {
                    Toast.fail(res.data.msg || '获取物料列表失败');
                    this.setData({
                        loading: false,
                        loadingMore: false
                    });
                }
            })
            .catch(err => {
                Toast.fail('接口请求失败');
                console.error(err);
                this.setData({
                    loading: false,
                    loadingMore: false
                });
            });
    },

    // 添加下拉刷新
    onPullDownRefresh() {
        this.getMaterialList(1).finally(() => {
            wx.stopPullDownRefresh();
        });
    },

    // 添加上拉加载更多
    onReachBottom() {
        if (this.data.currentPage < this.data.totalPages && this.data.hasMore) {
            this.getMaterialList(this.data.currentPage + 1, true);
        }
    },

    // 圆钢四字段筛选
    onSearchChange(e) {
        const field = e.currentTarget.dataset.field;
        const value = e.detail;
        this.setData({
            [`searchParams.${field}`]: value,
            currentPage: 1 // 重置为第一页
        }, () => {
            this.getMaterialList(1); // 重新获取第一页数据
        });
    },

    // 选择物料
    onMaterialSelect(e) {
        const selectedMaterials = e.detail;
        const newSelectedInfo = [];

        // 更新选中的物料信息
        selectedMaterials.forEach(material_id => {
            const material = this.data.materialList.find(item => item.material_id == material_id);
            if (material) {
                // 检查是否已经存在于selectedMaterialsInfo中
                const existing = this.data.selectedMaterialsInfo.find(item => item.material_id == material_id);
                newSelectedInfo.push({
                    material_id: material_id,
                    material_name: material.material_name,
                    manufacturer: material.manufacturer,
                    material_spec: material.material_spec,
                    material_model: material.material_model,
                    material_num: existing ? existing.material_num : ''
                });
            }
        });

        this.setData({
            selectedMaterials,
            selectedMaterialsInfo: newSelectedInfo
        });
    },

    // 修改领料数量
    onQuantityChange(e) {
        const materialId = e.currentTarget.dataset.id;
        const value = e.detail;

        const updatedInfo = this.data.selectedMaterialsInfo.map(item => {
            if (item.material_id == materialId) {
                return {
                    ...item,
                    material_num: value
                };
            }
            return item;
        });

        this.setData({
            selectedMaterialsInfo: updatedInfo
        });
    },

    // 移除物料
    removeMaterial(e) {
        const materialId = e.currentTarget.dataset.id;

        const newSelected = this.data.selectedMaterials.filter(id => id != materialId);
        const newSelectedInfo = this.data.selectedMaterialsInfo.filter(item => item.material_id != materialId);

        this.setData({
            selectedMaterials: newSelected,
            selectedMaterialsInfo: newSelectedInfo
        });
    },

    onSubmitSuccessConfirm() {
        this._submitLock = false;
        this.setData({
            submitting: false,
            showSubmitSuccessDialog: false,
            selectedMaterials: [],
            selectedMaterialsInfo: []
        });
        wx.navigateBack();
    },

    onSubmitSuccessDialogClose() {
        this._submitLock = false;
        this.setData({
            showSubmitSuccessDialog: false,
            submitting: false
        });
    },

    // 提交领料
    submitReceiving() {
        if (this._submitLock || this.data.submitting) {
            return;
        }
        // 验证数据
        const invalidItem = this.data.selectedMaterialsInfo.find(item => {
            return !item.material_num || item.material_num <= 0;
        });

        if (invalidItem) {
            Toast.fail('请填写所有物料的领料数量');
            return;
        }

        // 准备请求数据
        const content = this.data.selectedMaterialsInfo.map(item => ({
            material_id: item.material_id,
            material_num: parseFloat(item.material_num)
        }));

        this._submitLock = true;
        this.setData({
            loading: true,
            submitting: true
        });
        wx.showLoading({
            title: '提交中...',
            mask: true
        });

        util.request("admin/receiving.Receiving/add", {
                content
            }, "POST")
            .then(res => {
                if (res.data.code === 200) {
                    this.setData({
                        loading: false,
                        showSubmitSuccessDialog: true
                    });
                } else {
                    this._submitLock = false;
                    Toast.fail(res.data.msg || '领料失败');
                    this.setData({
                        loading: false,
                        submitting: false
                    });
                }
            })
            .catch(err => {
                this._submitLock = false;
                Toast.fail('接口请求失败，若网络不稳定可能已提交成功，请到列表核对后再试');
                console.error(err);
                this.setData({
                    loading: false,
                    submitting: false
                });
            })
            .finally(() => {
                wx.hideLoading();
            });
    }
});