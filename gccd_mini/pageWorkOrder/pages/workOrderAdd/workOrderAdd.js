const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';

// [AI-2026-08] 报工页圆钢字段绑定统一为名称、厂商、材质、型号。
Page({
    data: {
        statusBarHeight: 0,
        loading: false,
        /** 提交防重复：请求开始到用户确认成功弹窗为止为 true */
        submitting: false,
        showSubmitSuccessDialog: false,
        id: '', // 从上个页面传递过来的task_id
        relation_code: '', // 从接口获取的关联单号
        routing_code: '', // 从接口获取的工艺编码
        routing_name: '', // 从接口获取的工艺名称
        process_code: '', // 从接口获取的工序编码
        processes_name: '', // 工序名称
        y_material_name: '',
        y_material_manufacturer: '',
        y_material_spec: '',
        y_material_model: '',
        report_num: '', // 报工数量
        remark: '', // 备注

        // 不同工序特有字段
        device_id: '', // 设备ID
        device_name: '', // 设备名称
        device_code: '', // 设备编码
        deviceLocked: false, // 穿孔：上一页已选设备则锁定
        product_user: [], // 生产人员ID数组
        product_user_display: '', // 生产人员显示文本
        card_user: [], // 开车人员ID数组
        card_user_display: '', // 开车人员显示文本
        selectedProductUsers: [], // 新增：存储选中的生产人员对象
        selectedCardUsers: [], // 新增：存储选中的开车人员对象
        selectedNotFullAttendUsers: [], // 存储不满勤人员对象
        notFullAttendDisplay: '', // 不满勤人员显示文本
        notFullAttendItems: [], // 不满勤明细（人员+上班时长）

        // 弹窗控制
        showDefectivePopup: false,
        showDevicePopup: false,
        showUserPopup: false,
        currentUserField: '', // 当前选择的人员字段（product_user或card_user）
        tempUserField: '', // 临时存储当前操作的人员字段

        // 选项数据
        defectiveOptions: [], // 不良品选项
        defectiveItems: [], // 不良品明细列表
        defective_display: '',
        deviceOptions: [], // 设备选项
        userOptions: [], // 所有人员选项
        filteredUserOptions: [], // 过滤后的人员选项
        selectedUserIds: [], // 临时存储选择的人员ID

        // 界面控制
        showCommonFields: true, // 是否显示通用字段

        // 人员选择相关
        userPage: 1,
        userLoading: false,
        userNoMore: false,
        userSearchValue: '',
        userPickerTitle: '选择人员',
        selectedUserNames: [],

        // 设备选择相关
        devicePage: 1,
        deviceLoading: false,
        deviceNoMore: false,
        deviceSearchValue: '',
        deviceOptions: [], // 改为数组，支持分页
        selectedDevice: null, // 存储当前选中的设备对象

        invalidInput: false, // 标记输入是否无效

        yarn_count: '', // 新增支数字段
        invalidYarnCount: false, // 支数输入验证
        showYarnCountField: false, // 是否显示支数字段

        // 新增字段
        is_replace: '0', // 是否代理班长(0不是1是)，默认0
        showWagePopup: false, // 型号选项弹窗
        wageOptions: [], // 型号选项列表
        wage_display: '', // 显示的型号选项
        selectedWage: null, // 选中的型号选项
        class: 0, // 班次：0白班，1夜班，默认白
    },

    // 返回上一页
    navigateBack() {
        wx.navigateBack({
            delta: 1
        });
    },

    // 支数输入变化（带防抖）
    onYarnCountInput(e) {
        const value = e.detail;
        const isValid = /^\d*\.?\d*$/.test(value) || value === '';

        this.setData({
            yarn_count: isValid ? value : this.data.yarn_count,
            invalidYarnCount: !isValid
        });

        if (!isValid) {
            wx.showToast({
                title: '请输入有效的支数',
                icon: 'none',
                duration: 2000,
            });
            return;
        }

        // 清除之前的计时器
        // if (this.data.yarnCountTimer) {
        //     clearTimeout(this.data.yarnCountTimer);
        // }

        // // 设置新的计时器（500ms延迟）
        // this.setData({
        //     yarnCountTimer: setTimeout(() => {
        //         this.calculateWeightByYarnCount(value);
        //     }, 500)
        // });
    },

    // 根据支数计算吨数
    calculateWeightByYarnCount() {
       let yarnCount = this.data.yarn_count
        // 如果支数为空或无效，或者与上次计算的支数相同，则不请求
        if (!yarnCount || this.data.invalidYarnCount || yarnCount === this.data.lastYarnCount) {
            return;
        }

        // 如果正在计算，则取消本次计算
        if (this.data.calculating) {
            return;
        }

        this.setData({
            calculating: true,
            lastYarnCount: yarnCount
        });

        // 显示加载中
        wx.showLoading({
            title: '计算中...',
            mask: true
        });

        // 调用接口计算吨数
        util.request("admin/production.ProductReport/count", {
                count: yarnCount,
                task_code: this.data.relation_code
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const calculatedWeight = res.data.data; // 假设接口返回的是计算后的吨数
                    this.setData({
                        report_num: calculatedWeight,
                        invalidInput: false // 清除可能的错误状态
                    });
                } else {
                    Toast.fail(res.data.msg || '计算吨数失败');
                }
            })
            .catch(err => {
                console.error('计算吨数失败:', err);
                Toast.fail('计算吨数失败');
            })
            .finally(() => {
                this.setData({
                    calculating: false
                });
                wx.hideLoading();
            });
    },

    // 普通输入框变化
    onInputChange(e) {
        const {
            field
        } = e.currentTarget.dataset;
        const value = e.detail;

        // 验证是否为有效数字
        const isValid = /^\d*\.?\d*$/.test(value) || value === '';

        if (field === 'report_num') {
            this.setData({
                report_num: isValid ? value : this.data.report_num,
                invalidInput: !isValid
            });
            if (!isValid) {
                wx.showToast({
                    title: '请输入有效的数字（如 10 或 10.5）',
                    icon: 'none',
                    duration: 2000,
                });
            }
        } else if (field === 'yarn_count') {
            this.setData({
                yarn_count: isValid ? value : this.data.yarn_count,
                invalidYarnCount: !isValid
            });
            if (!isValid) {
                wx.showToast({
                    title: '请输入有效的支数',
                    icon: 'none',
                    duration: 2000,
                });
            }
        } else {
            this.setData({
                [field]: value
            });
        }
    },

    // 显示不良品选择器
    showDefectivePicker() {
        this.getDefectiveOptions();
    },

    // 获取不良品选项
    getDefectiveOptions() {
        util.request("admin/check.Check/list", {
                //check_code: this.data.process_code,
                limit: 1000,
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const options = res.data.data.list.map(item => ({
                        text: item.check_name,
                        value: item.id
                    }));
                    this.setData({
                        defectiveOptions: options,
                        showDefectivePopup: true
                    });
                } else {
                    Toast.fail('获取不良品类型失败');
                }
            })
            .catch(err => {
                console.error(err);
                Toast.fail('接口请求失败');
            });
    },

    // 隐藏不良品选择器
    hideDefectivePicker() {
        this.setData({
            showDefectivePopup: false
        });
    },

    // 选择不良品确认
    onDefectiveConfirm(e) {
        const {
            value,
            text
        } = e.detail.value;
        if (this.data.defectiveItems.some(item => item.type === value)) {
            Toast.fail('该不良品类型已添加');
            return;
        }

        this.addDefectiveItem(value, text);
        this.hideDefectivePicker();
    },

    // 添加不良品项
    addDefectiveItem(type, typeText) {
        const newItems = [...this.data.defectiveItems, {
            type,
            typeText,
            quantity: 0
        }];
        this.setData({
            defectiveItems: newItems,
            defective_display: `已选择${newItems.length}种不良品`
        });
    },

    // 移除不良品项
    removeDefectiveItem(e) {
        const index = e.currentTarget.dataset.index;
        const newItems = [...this.data.defectiveItems];
        newItems.splice(index, 1);
        this.setData({
            defectiveItems: newItems,
            defective_display: newItems.length > 0 ? `已选择${newItems.length}种不良品` : ''
        });
    },

    // 不良品数量变化
    onDefectiveQuantityChange(e) {
        const index = e.currentTarget.dataset.index;
        const value = parseInt(e.detail.value) || 0;
        const newItems = [...this.data.defectiveItems];
        newItems[index].quantity = value;
        this.setData({
            defectiveItems: newItems
        });
    },

    // 显示设备选择器
    showDevicePicker() {
        this.getDeviceOptions();
    },

    // 获取设备选项（根据工序名称筛选）
    getDeviceOptions() {
        const {
            processes_name
        } = this.data;
        let params = {
            page: 1,
            limit: 1000
        };

        // 根据工序名称添加筛选条件
        if (processes_name === '断钢') {
            params.device_name = '断钢';
        } else if (processes_name === '穿孔') {
            params.device_name = '穿孔';
        }

        util.request("admin/repair.Device/list", params, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    this.setData({
                        deviceOptions: res.data.data.list || [],
                        showDevicePopup: true
                    });
                } else {
                    Toast.fail('获取设备列表失败');
                }
            })
            .catch(err => {
                console.error(err);
                Toast.fail('接口请求失败');
            });
    },

    // 隐藏设备选择器
    hideDevicePicker() {
        this.setData({
            showDevicePopup: false
        });
    },

    // 选择设备确认
    onDeviceConfirm(e) {
        const {
            device_name,
            id,device_code
        } = e.detail.value;
        this.setData({
            device_id: id,
            device_name: device_name,
            device_code: device_code
        });
        this.hideDevicePicker();
    },

    // 显示生产人员选择器（酸洗和穿孔工序）
    showProductUserPicker() {
        this.showUserPicker('product_user');
    },

    // 显示开车人员选择器（仅穿孔工序）
    showCardUserPicker() {
        this.showUserPicker('card_user');
    },

    // 显示不满勤人员选择器（穿孔、球化、酸洗）
    showNotFullAttendUserPicker() {
        this.showUserPicker('not_full_attend_user');
    },

    // 根据关键字过滤人员列表
    filterUsersByKeyword(keyword) {
        const kw = (keyword || '').trim().toLowerCase();
        const allUsers = this.data.userOptions;
        if (!kw) {
            return allUsers;
        }
        return allUsers.filter((user) => {
            const name = (user.nickname || '').toLowerCase();
            const dept = (user.dept_names || '').toLowerCase();
            return name.includes(kw) || dept.includes(kw);
        });
    },

    // 同步已选人员姓名标签
    syncSelectedUserNames(selectedIds) {
        return this.data.userOptions
            .filter((user) => selectedIds.includes(user.user_id))
            .map((user) => user.nickname || '未知');
    },

    getUserPickerTitle(field) {
        const titleMap = {
            product_user: '选择生产人员',
            card_user: '选择开车人员',
            not_full_attend_user: '选择不满勤人员',
        };
        return titleMap[field] || '选择人员';
    },

    // 通用的人员选择器显示方法
    showUserPicker(field) {
        let currentSelected = [];
        if (field === 'product_user') {
            currentSelected = this.data.selectedProductUsers.map(user => String(user.user_id));
        } else if (field === 'card_user') {
            currentSelected = this.data.selectedCardUsers.map(user => String(user.user_id));
        } else if (field === 'not_full_attend_user') {
            currentSelected = this.data.selectedNotFullAttendUsers.map(user => String(user.user_id));
        }

        this.setData({
            tempUserField: field,
            userPickerTitle: this.getUserPickerTitle(field),
            selectedUserIds: [...currentSelected],
            selectedUserNames: this.syncSelectedUserNames(currentSelected),
            userSearchValue: '',
            filteredUserOptions: [...this.data.userOptions]
        }, () => {
            this.setData({
                showUserPopup: true
            });
        });
    },

    // 人员检索输入
    onUserSearchInput(e) {
        const value = e.detail.value || '';
        this.setData({
            userSearchValue: value,
            filteredUserOptions: this.filterUsersByKeyword(value)
        });
    },

    // 清除人员检索
    clearUserSearch() {
        this.setData({
            userSearchValue: '',
            filteredUserOptions: [...this.data.userOptions]
        });
    },

    // 点击整行选中/取消
    onUserCellTap(e) {
        const userId = String(e.currentTarget.dataset.userId);
        const selectedIds = [...this.data.selectedUserIds];
        const idx = selectedIds.indexOf(userId);
        if (idx >= 0) {
            selectedIds.splice(idx, 1);
        } else {
            selectedIds.push(userId);
        }
        this.setData({
            selectedUserIds: selectedIds,
            selectedUserNames: this.syncSelectedUserNames(selectedIds)
        });
    },

    noop() {},

    // 获取人员选项
    getUserOptions() {
        this.setData({
            userLoading: true
        });
        util.request("admin/system.User/productlist", {
                limit: 1000,
                page: 1,
                processes_name: this.data.processes_name
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const users = res.data.data.list.map(user => ({
                        ...user,
                        user_id: String(user.user_id), // 确保ID是字符串类型
                        dept_names: user.dept_names || '未分配部门',
                        nickname: user.nickname || '',
                    }));

                    this.setData({
                        userOptions: users,
                        filteredUserOptions: users,
                        userLoading: false
                    });
                } else {
                    Toast.fail('获取人员列表失败');
                    this.setData({
                        userLoading: false
                    });
                }
            })
            .catch(err => {
                console.error(err);
                Toast.fail('接口请求失败');
                this.setData({
                    userLoading: false
                });
            });
    },

    // 隐藏人员选择器
    hideUserPicker() {
        this.setData({
            showUserPopup: false
        });
    },

    // 人员选择变化
    onUserSelectChange(e) {
        const selectedIds = Array.isArray(e.detail) ? e.detail : [e.detail];
        const idStrings = selectedIds.map((id) => String(id));
        this.setData({
            selectedUserIds: idStrings,
            selectedUserNames: this.syncSelectedUserNames(idStrings)
        });
    },

    // 确认选择人员
    confirmUserSelect() {
        const field = this.data.tempUserField;
        const selectedIds = this.data.selectedUserIds;

        if (selectedIds.length === 0) {
            Toast.fail('请至少选择一人');
            return;
        }

        // 获取选中的人员信息
        const selectedUsers = this.data.userOptions.filter(user =>
            selectedIds.includes(user.user_id)
        );

        // 更新对应字段
        if (field === 'product_user') {
            this.setData({
                selectedProductUsers: [...selectedUsers], // 存储完整的用户对象数组
                product_user_display: selectedUsers.map(u =>
                    `${u.nickname || ''}`
                ).join(', ')
            });
        } else if (field === 'card_user') {
            this.setData({
                selectedCardUsers: [...selectedUsers], // 存储完整的用户对象数组
                card_user_display: selectedUsers.map(u =>
                    `${u.nickname || ''}`
                ).join(', ')
            });
        } else if (field === 'not_full_attend_user') {
            const hoursMap = this.data.notFullAttendItems.reduce((acc, item) => {
                acc[String(item.user_id)] = item.work_hours || '';
                return acc;
            }, {});
            const nextNotFullAttendItems = selectedUsers.map(user => ({
                user_id: String(user.user_id),
                nickname: user.nickname || '',
                work_hours: hoursMap[String(user.user_id)] || ''
            }));

            this.setData({
                selectedNotFullAttendUsers: [...selectedUsers],
                notFullAttendDisplay: selectedUsers.map(u => `${u.nickname || ''}`).join(', '),
                notFullAttendItems: nextNotFullAttendItems
            });
        }

        this.setData({
            showUserPopup: false
        });
    },

    // 不满勤上班时长变化
    onNotFullAttendHoursChange(e) {
        const index = e.currentTarget.dataset.index;
        const value = e.detail.value;
        const isValid = /^\d*\.?\d*$/.test(value) || value === '';
        if (!isValid) {
            wx.showToast({
                title: '请输入有效的上班时长',
                icon: 'none',
                duration: 2000
            });
            return;
        }

        const nextItems = [...this.data.notFullAttendItems];
        nextItems[index].work_hours = value;
        this.setData({
            notFullAttendItems: nextItems
        });
    },

    // 移除不满勤人员
    removeNotFullAttendItem(e) {
        const index = e.currentTarget.dataset.index;
        const nextItems = [...this.data.notFullAttendItems];
        nextItems.splice(index, 1);
        const nextUsers = this.data.selectedNotFullAttendUsers.filter(user =>
            nextItems.some(item => String(item.user_id) === String(user.user_id))
        );

        this.setData({
            notFullAttendItems: nextItems,
            selectedNotFullAttendUsers: nextUsers,
            notFullAttendDisplay: nextUsers.map(u => `${u.nickname || ''}`).join(', ')
        });
    },

    // 加载任务详情
    loadTaskDetail() {
        if (!this.data.id) {
            Toast.fail('任务ID不能为空');
            return Promise.reject('任务ID不能为空');
        }

        const requestPayload = {
            id: this.data.id,
        };
        if (this.data.process_code) {
            requestPayload.processes_code = this.data.process_code;
        }

        return util.request("admin/production.ProductTask/info", requestPayload, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const taskInfo = res.data.data;
                    const processesName = this.data.processes_name || taskInfo.processes_name || '';
                    const processCode = this.data.process_code || taskInfo.process_code || '';
                    const showYarnCount = ['穿孔', '球化', '矫直'].includes(processesName);

                    this.setData({
                        relation_code: taskInfo.task_code || '',
                        routing_code: taskInfo.routing_code || '',
                        routing_name: taskInfo.routing_name || '',
                        material_id: taskInfo.material_id || '',
                        reportNum: taskInfo.report_num ?? 0,
                        c_material_name: taskInfo.c_material_name || '',
                        c_material_spec: taskInfo.c_material_spec || '',
                        y_material_name: taskInfo.y_material_name || '',
                        y_material_manufacturer: taskInfo.y_material_manufacturer || '',
                        y_material_spec: taskInfo.y_material_spec || '',
                        y_material_model: taskInfo.y_material_model || '',
                        c_material_model: taskInfo.c_material_model || '',
                        processes_name: processesName,
                        process_code: processCode,
                        showYarnCountField: showYarnCount,
                        yarnCountTimer: null,
                        calculating: false,
                        lastYarnCount: '',
                    }, () => {
                        this.getMaterialName(this.data.material_id);
                        if (processesName) {
                            this.getUserOptions();
                        }
                    });
                } else {
                    Toast.fail('获取任务详情失败');
                    return Promise.reject(res.data.msg || '获取任务详情失败');
                }
            })
            .catch(err => {
                Toast.fail('接口请求失败');
                console.error(err);
                return Promise.reject(err);
            });
    },

    // 是否代理班长切换
    onReplaceChange(e) {
        this.setData({
            is_replace: e.detail
        });
    },

    // 切换班次
    onClassSelect(e) {
        const classValue = parseInt(e.currentTarget.dataset.value);
        this.setData({
            class: classValue
        });
    },

    // 显示型号选择器
    // showWagePicker() {
    //     this.getWageOptions();
    // },

    // 获取型号选项
    getWageOptions() {
        this.setData({
            loading: true
        });

        // 调用型号接口，传递material_spec
        util.request("admin/wage.Wage/list", {
                material_spec: this.data.c_material_spec || this.data.y_material_spec || ''
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    // 假设接口返回格式为 { id: 1, name: "选项1", value: "value1" }
                    const options = res.data.data.list || [];
                    this.setData({
                        wageOptions: options,
                        showWagePopup: true
                    });
                } else {
                    Toast.fail('获取型号选项失败');
                }
            })
            .catch(err => {
                console.error(err);
                Toast.fail('接口请求失败');
            })
            .finally(() => {
                this.setData({
                    loading: false
                });
            });
    },

    // 隐藏型号选择器
    hideWagePicker() {
        this.setData({
            showWagePopup: false
        });
    },

    // 选择型号确认
    onWageConfirm(e) {
        console.log(e.detail.value);
        const selected = e.detail.value;
        this.setData({
            selectedWage: selected.material_spec,
            wage_display: selected.material_spec,
            showWagePopup: false
        });
    },

    // 验证表单
    validateForm() {
        const {
            processes_name,
            report_num,
            device_id,
            showYarnCountField,
            yarn_count
        } = this.data;

        if (!report_num) {
            Toast.fail('请输入报工数量');
            return false;
        }

        // 如果需要显示支数字段，则验证支数
        if (showYarnCountField && !yarn_count) {
            Toast.fail('请输入支数');
            return false;
        }

        // 不同工序的特殊验证
        if (processes_name === '断钢') {
            if (!device_id) {
                Toast.fail('请选择设备名称');
                return false;
            }
            for (const item of this.data.defectiveItems) {
                if (item.quantity <= 0) {
                    Toast.fail('请填写不良品数量');
                    return false;
                }
            }
        } else if (processes_name === '穿孔') {
            if (!this.data.deviceLocked && !this.data.device_id) {
                Toast.fail('请选择设备名称');
                return false;
            }
            if (this.data.deviceLocked && !this.data.device_code) {
                Toast.fail('设备信息缺失，请返回重新选择设备');
                return false;
            }
            // 验证用户对象数组是否为空
            if (!this.data.selectedProductUsers || this.data.selectedProductUsers.length === 0) {
                Toast.fail('请选择生产人员');
                return false;
            }
            if (!this.data.selectedCardUsers || this.data.selectedCardUsers.length === 0) {
                Toast.fail('请选择开车人员');
                return false;
            }
        } else if (processes_name === '酸洗') {
            if (!this.data.selectedProductUsers || this.data.selectedProductUsers.length === 0) {
                Toast.fail('请选择生产人员');
                return false;
            }
        } else if (processes_name === '球化') {
            // 新增球化工序验证
            if (!device_id) {
                Toast.fail('请选择设备名称');
                return false;
            }
            if (!this.data.selectedProductUsers || this.data.selectedProductUsers.length === 0) {
                Toast.fail('请选择生产人员');
                return false;
            }
        } else if (processes_name === '矫直') {
            // 新增矫直工序验证
            if (!device_id) {
                Toast.fail('请选择设备名称');
                return false;
            }
        }

        // 断钢或穿孔工序新增验证
        // if (processes_name === '断钢' || processes_name === '穿孔') {
        //     if (!this.data.selectedWage) {
        //         Toast.fail('请选择型号');
        //         return false;
        //     }
        // }

        return true;
    },

    onSubmitSuccessConfirm() {
        this._submitLock = false;
        this.setData({
            submitting: false,
            showSubmitSuccessDialog: false
        });
        wx.navigateBack({
            delta: 1
        });
    },

    onSubmitSuccessDialogClose() {
        this._submitLock = false;
        this.setData({
            showSubmitSuccessDialog: false,
            submitting: false
        });
    },

    // 提交表单
    async submitForm() {
        if (this._submitLock || this.data.submitting) {
            return;
        }
        if (this.data.yarnCountTimer) {
            clearTimeout(this.data.yarnCountTimer);
        }
        if (!this.validateForm()) {
            return;
        }

        this._submitLock = true;
        this.setData({
            loading: true,
            submitting: true
        });
        wx.showLoading({
            title: '提交中...',
            mask: true
        });

        try {
            const {
                processes_name,
                showYarnCountField
            } = this.data;
            const postData = {
                relation_code: this.data.relation_code,
                routing_code: this.data.routing_code,
                processes_code: this.data.process_code,
                material_id: this.data.material_id,
                report_num: this.data.report_num,
                remark: this.data.remark,
                defective_num: this.data.defectiveItems.map(item => ({
                    name: item.typeText,
                    num: item.quantity.toString()
                })),
                is_replace: parseInt(this.data.is_replace),
                class: this.data.class
            };

            // 添加支数字段
            if (showYarnCountField) {
                postData.yarn_count = this.data.yarn_count;
            }

            // 根据不同工序添加特定字段
            if (processes_name === '穿孔') {
                postData.product_user = this.data.selectedProductUsers.map(u => u.nickname);
                postData.card_user = this.data.selectedCardUsers.map(u => u.nickname);
                postData.device_name = this.data.device_name;
                postData.device_code = this.data.device_code;
                // postData.material_spec = this.data.selectedWage;
            } else if (processes_name === '酸洗') {
                postData.product_user = this.data.selectedProductUsers.map(u => u.nickname);
            } else if (processes_name === '断钢') {
                postData.device_name = this.data.device_name;
                postData.device_code = this.data.device_code;
                // postData.material_spec = this.data.selectedWage;
            } else if (processes_name === '球化') {
                postData.product_user = this.data.selectedProductUsers.map(u => u.nickname);
                postData.device_name = this.data.device_name;
                postData.device_code = this.data.device_code;
            } else if (processes_name === '矫直') {
                postData.device_name = this.data.device_name;
                postData.device_code = this.data.device_code;
            }

            if (['穿孔', '球化', '酸洗', '矫直'].includes(processes_name) && this.data.notFullAttendItems.length > 0) {
                postData.not_full_attend = this.data.notFullAttendItems.map(item => ({
                    name: item.nickname,
                    hours: item.work_hours
                }));
            }

            const res = await util.request("admin/production.ProductReport/report", postData, "POST");
            if (res.data.code === 200) {
                this.setData({
                    loading: false,
                    showSubmitSuccessDialog: true
                });
            } else {
                this._submitLock = false;
                Toast.fail(res.data.msg || '报工失败');
                this.setData({
                    loading: false,
                    submitting: false
                });
            }
        } catch (err) {
            console.error(err);
            this._submitLock = false;
            // 区分业务错误和网络错误
            if (err && err.data && err.data.msg) {
                // 后台返回了具体原因，直接显示
                Toast.fail(err.data.msg);
            } else {
                Toast.fail('接口请求失败，若网络不稳定可能已提交成功，请到列表核对后再试');
            }
            this.setData({
                loading: false,
                submitting: false
            });
        } finally {
            wx.hideLoading();
        }
    },


    // 获取物料名称
    getMaterialName(materialId) {
        util.request("admin/production.Material/info", {
                id: materialId
            }, "POST")
            .then(res => {
                if (res.data.code === 200 && res.data.data) {
                    const materialInfo = res.data.data;
                    this.setData({
                        materialName: materialInfo.material_name || '',
                        materialSpec: materialInfo.material_spec || '',
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

        const id = options.id || '';
        if (!id) {
            Toast.fail('缺少任务信息');
            return;
        }

        const lockedDeviceCode = options.locked_device_code ? decodeURIComponent(options.locked_device_code) : '';
        const lockedDeviceName = options.locked_device_name ? decodeURIComponent(options.locked_device_name) : '';
        const processesName = options.processes_name ? decodeURIComponent(options.processes_name) : '';
        const processCode = options.process_code || options.processes_code || wx.getStorageSync('work_processes_code') || '';

        this.setData({
            id,
            process_code: processCode,
            processes_name: processesName,
            routing_code: options.routing_code || '',
            routing_name: options.routing_name || '',
            showYarnCountField: ['穿孔', '球化', '矫直'].includes(processesName),
            device_code: lockedDeviceCode || this.data.device_code,
            device_name: lockedDeviceName || this.data.device_name,
            deviceLocked: !!lockedDeviceCode,
        }, () => {
            this.loadTaskDetail();
        });
    },

    // 页面卸载时清除计时器
    onUnload() {
        if (this.data.yarnCountTimer) {
            clearTimeout(this.data.yarnCountTimer);
        }
    },
});