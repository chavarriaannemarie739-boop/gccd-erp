const util = require("../../../utils/util.js");
import Toast from '@vant/weapp/toast/toast';
import Dialog from '@vant/weapp/dialog/dialog';

Page({
  data: {
    statusBarHeight: 0,
    title: '维修报工',
    type: '', // jixiu or diangong
    loading: false,
    submitting: false,

    // 设备选择
    _allDevices: [],
    filteredDevices: [],
    deviceSearch: '',
    selectedDevice: null,
    showDevicePopup: false,

    // 人员选择 (全局字段)
    _allUsers: [],
    filteredUsers: [],
    userSearch: '',
    selectedUserIds: [],
    selectedUserMap: {},
    selectedUsers: [],
    showUserPopup: false,

    // 维修项目 (多选)
    _allMaintenance: [],
    filteredMaintenance: [],
    maintenanceSearch: '',
    selectedMaintenanceIds: [],
    selectedMaintenanceMap: {},
    showMaintenancePopup: false,

    // 当前待添加的明细
    maintenanceItems: [],

    // 已添加列表
    addedRecords: [],
    remark: '',
    selectedUsersText: '', // 已选人员名称拼接
  },

  onLoad(options) {
    const type = options.type || 'jixiu';
    this.setData({
      type,
      title: type === 'jixiu' ? '机修报工' : '电工报工'
    });

    wx.getSystemInfo({
      success: (res) => {
        this.setData({
          statusBarHeight: res.statusBarHeight,
        });
      },
    });

    this.getDeviceOptions();
    this.getUserOptions();
    // 先获取大类列表，再获取维修项
    this.getCategoryOptions().then(() => {
      this.getMaintenanceOptions();
    });
  },

  // 获取大类列表
  getCategoryOptions() {
    return util.request("admin/production.ProductCount/category")
    .then(res => {
      console.log('category 返回:', res.data);
      if (res.data.code === 200 && res.data.data) {
        const list = res.data.data; 
        console.log('大类列表:', list);
        let targetParentId = 0;
        if (this.data.type === 'jixiu') {
          const jixiuCategory = list.find(c => c.repair_name === '机修');
          targetParentId = jixiuCategory ? jixiuCategory.id : 0;
        } else if (this.data.type === 'diangong') {
          const diangongCategory = list.find(c => c.repair_name === '电工');
          targetParentId = diangongCategory ? diangongCategory.id : 0;
        }
        this.setData({
          targetParentId: String(targetParentId)
        });
      }
    })
    .catch(err => {
      console.error('获取大类列表失败:', err);
    });
  },

  // 返回上一页
  navigateBack() {
    wx.navigateBack({
      delta: 1
    });
  },

  // ======== 设备选择 ========
  getDeviceOptions() {
    util.request("admin/repair.Device/list", {
      limit: 1000,
      page: 1
    }, "POST")
    .then(res => {
      if (res.data.code === 200 && res.data.data) {
        const list = res.data.data.list || [];
        this.setData({
          _allDevices: list,
          filteredDevices: list
        });
      }
    })
    .catch(err => {
      console.error('获取设备列表失败', err);
    });
  },

  showDevicePicker() {
    this.setData({ 
      showDevicePopup: true,
      deviceSearch: '',
      filteredDevices: this.data._allDevices
    });
  },
  hideDevicePicker() {
    this.setData({ showDevicePopup: false });
  },
  onDeviceSearchInput(e) {
    const kw = (e.detail.value || '').toLowerCase();
    const filtered = this.data._allDevices.filter(it => 
      it.device_name.toLowerCase().includes(kw) || 
      it.device_code.toLowerCase().includes(kw)
    );
    this.setData({ deviceSearch: kw, filteredDevices: filtered });
  },
  onDeviceSelect(e) {
    const idx = e.currentTarget.dataset.idx;
    this.setData({
      selectedDevice: this.data.filteredDevices[idx],
      showDevicePopup: false
    });
  },

  // ======== 人员选择 ========
  getUserOptions() {
    util.request("admin/system.User/list", {
      limit: 1000,
      page: 1
    }, "POST")
    .then(res => {
      if (res.data.code === 200 && res.data.data) {
        const list = res.data.data.list || [];

        // 过滤机修电工（dept_names 可能是字符串或数组）
        const filtered = list.filter(u => {
          if (!u.dept_names) return false;
          if (Array.isArray(u.dept_names)) {
            return u.dept_names.includes('机修电工');
          }
          // 字符串情况：可能是"机修电工"或"机修电工，其他部门"
          return u.dept_names.includes('机修电工');
        }).map(u => ({
          ...u,
          user_id: String(u.user_id),
          nickname: u.nickname || u.user_name
        }));

        this.setData({
          _allUsers: filtered,
          filteredUsers: filtered
        });
      }
    })
    .catch(err => {
      console.error('获取人员列表失败', err);
    });
  },

  showUserPicker() {
    if (this.data._allUsers.length === 0) {
      Toast('暂无机修电工人员数据');
      this.getUserOptions();
      return;
    }
    const ids = this.data.selectedUserIds;
    const map = {};
    ids.forEach(id => map[id] = true);
    this.setData({
      showUserPopup: true,
      userSearch: '',
      filteredUsers: this.data._allUsers,
      selectedUserIds: ids,
      selectedUserMap: map
    });
  },
  hideUserPicker() {
    this.setData({ showUserPopup: false });
  },
  onUserSearchInput(e) {
    const kw = (e.detail.value || '').toLowerCase();
    const filtered = this.data._allUsers.filter(it =>
      it.nickname.toLowerCase().includes(kw)
    );
    this.setData({ userSearch: kw, filteredUsers: filtered });
  },
  onUserToggle(e) {
    const id = e.currentTarget.dataset.id;
    const ids = [...this.data.selectedUserIds];
    const map = { ...this.data.selectedUserMap };
    const idx = ids.indexOf(id);
    if (idx >= 0) {
      ids.splice(idx, 1);
      delete map[id];
    } else {
      ids.push(id);
      map[id] = true;
    }
    this.setData({ selectedUserIds: ids, selectedUserMap: map });
  },
  confirmUserSelect() {
    const newUsers = this.data.selectedUserIds.map(id => {
      return this.data._allUsers.find(u => u.user_id === id);
    }).filter(Boolean);
    this.setData({
      selectedUsers: newUsers,
      selectedUsersText: newUsers.map(u => u.nickname).join(','),
      showUserPopup: false
    });
  },

  // ======== 维修项目多选 ========
  getMaintenanceOptions() {
    util.request("admin/production.ProductCount/list", {
      limit: 1000,
      page: 1
    }, "POST")
    .then(res => {
      console.log('list 返回:', res.data);
      if (res.data.code === 200 && res.data.data) {
        const allTree = res.data.data.list || [];
        
        let filteredItems = [];
        let categoryNode = null;
        
        if (this.data.type === 'jixiu') {
          categoryNode = allTree.find(it => it.repair_name && it.repair_name.includes('机修'));
        } else if (this.data.type === 'diangong') {
          categoryNode = allTree.find(it => it.repair_name && it.repair_name.includes('电工'));
        }

        if (categoryNode) {
          if (categoryNode.children && categoryNode.children.length > 0) {
            filteredItems = categoryNode.children;
          } else {
            // 兼容扁平列表返回（若后端未组装树）
            filteredItems = allTree.filter(it => it.parent_id == categoryNode.id);
          }
        }

        const list = filteredItems.map(it => ({
          ...it,
          id: String(it.id),
          repair_name: it.repair_name || ''
        }));

        this.setData({
          _allMaintenance: list,
          filteredMaintenance: list
        });
      }
    })
    .catch(err => {
      console.error('获取维修项目失败', err);
    });
  },

  showMaintenancePicker() {
    if (this.data._allMaintenance.length === 0) {
      Toast('暂无维修项目数据');
      return;
    }
    const ids = this.data.maintenanceItems.map(it => it.id);
    const map = {};
    ids.forEach(id => map[id] = true);
    this.setData({
      showMaintenancePopup: true,
      maintenanceSearch: '',
      filteredMaintenance: this.data._allMaintenance,
      selectedMaintenanceIds: ids,
      selectedMaintenanceMap: map
    });
  },
  hideMaintenancePicker() {
    this.setData({ showMaintenancePopup: false });
  },
  onMaintenanceSearchInput(e) {
    const kw = (e.detail.value || '').toLowerCase();
    const filtered = this.data._allMaintenance.filter(it => 
      it.repair_name.toLowerCase().includes(kw)
    );
    this.setData({ maintenanceSearch: kw, filteredMaintenance: filtered });
  },
  onMaintenanceToggle(e) {
    const id = e.currentTarget.dataset.id;
    const ids = [...this.data.selectedMaintenanceIds];
    const map = { ...this.data.selectedMaintenanceMap };
    const idx = ids.indexOf(id);
    if (idx >= 0) {
      ids.splice(idx, 1);
      delete map[id];
    } else {
      ids.push(id);
      map[id] = true;
    }
    this.setData({ selectedMaintenanceIds: ids, selectedMaintenanceMap: map });
  },
  confirmMaintenanceSelect() {
    const oldItems = this.data.maintenanceItems;
    const newItems = this.data.selectedMaintenanceIds.map(id => {
      const existing = oldItems.find(it => it.id === id);
      if (existing) return existing;
      const origin = this.data._allMaintenance.find(it => it.id === id);
      return { id, repair_name: origin ? origin.repair_name : '', quantity: '' };
    });
    this.setData({
      maintenanceItems: newItems,
      showMaintenancePopup: false
    });
  },

  removeMaintenanceItem(e) {
    const idx = e.currentTarget.dataset.index;
    const items = [...this.data.maintenanceItems];
    items.splice(idx, 1);
    this.setData({ maintenanceItems: items });
  },

  onRepairContentInput(e) {
    const idx = e.currentTarget.dataset.index;
    this.setData({
      [`maintenanceItems[${idx}].repair_content`]: e.detail.value
    });
  },

  // ======== 添加记录 ========
  addRecord() {
    const { selectedDevice, maintenanceItems, addedRecords } = this.data;
    if (!selectedDevice) {
      Toast.fail('请选择设备');
      return;
    }
    if (maintenanceItems.length === 0) {
      Toast.fail('请选择维修项目');
      return;
    }

    const record = {
      device_id: selectedDevice.id,
      device_name: selectedDevice.device_name,
      device_code: selectedDevice.device_code,
      items: maintenanceItems.map(it => ({
        id: it.id,
        repair_name: it.repair_name,
        repair_content: it.repair_content || ''
      }))
    };

    this.setData({
      addedRecords: [...addedRecords, record],
      selectedDevice: null,
      maintenanceItems: []
    });
    Toast.success('已添加');
  },

  removeRecord(e) {
    const idx = e.currentTarget.dataset.index;
    const list = [...this.data.addedRecords];
    list.splice(idx, 1);
    this.setData({ addedRecords: list });
  },

  onRemarkInput(e) {
    this.setData({ remark: e.detail.value });
  },

  // ======== 提交 ========
  onSubmitTap() {
    const { addedRecords, selectedUsers } = this.data;

    if (addedRecords.length === 0) {
      Toast.fail('请至少添加一条报工记录');
      return;
    }

    if (!selectedUsers || selectedUsers.length === 0) {
      Toast.fail('请选择作业人员');
      return;
    }

    this._doSubmit();
  },

  _doSubmit() {
    if (this.data.loading || this.data.submitting) return;

    this.setData({ loading: true, submitting: true });
    wx.showLoading({ title: '提交中...', mask: true });

    const postData = {
      type: this.data.type,
      user_ids: this.data.selectedUsers.map(u => u.user_id),
      user_names: this.data.selectedUsers.map(u => u.nickname),
      remark: this.data.remark,
      records: this.data.addedRecords
    };

    util.request("admin/repair.Device/reportBatch", postData, "POST")
      .then(res => {
        if (res.data.code === 200) {
          Dialog.alert({
            title: '提交成功',
            message: '报工记录已成功提交',
          }).then(() => {
            wx.navigateBack();
          });
        } else {
          Toast.fail(res.data.msg || '提交失败');
        }
      })
      .catch(err => {
        console.error('提交失败', err);
        Toast.fail('接口请求失败');
      })
      .finally(() => {
        this.setData({ loading: false, submitting: false });
        wx.hideLoading();
      });
  }
});
