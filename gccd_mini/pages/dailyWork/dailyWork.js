const util = require('../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';

Page({
  data: {
    statusBarHeight: 0,
    loading: false,
    submitting: false,

    workDate: '',
    workType: 1, // 1=全天，2=半天
    workHours: 12,
    remark: '',

    // 人员选择
    selectedUsers: [],
    selectedUserIds: [],
    selectedUserDisplay: '',
    userOptions: [],
    filteredUserOptions: [],
    showUserPopup: false,
    userSearchValue: '',

    // 工资预览
    dailyWagePerPerson: 200,
    leaderAllowance: 0, // 报工人岗位津贴（固定 1 份）
    reporterName: '', // 当前登录报工人
    totalWage: 0,

    editMode: false,
    editId: 0,
  },

  onLoad(options) {
    const windowInfo = wx.getWindowInfo ? wx.getWindowInfo() : wx.getSystemInfoSync();
    this.setData({
      statusBarHeight: windowInfo.statusBarHeight || 0,
      reporterName: wx.getStorageSync('nickname') || '',
    });

    const today = this.formatDate(new Date());
    this.setData({ workDate: today });

    if (options && options.id) {
      this.setData({ editMode: true, editId: parseInt(options.id) });
    }

    this.getUserOptions();
  },

  formatDate(date) {
    const y = date.getFullYear();
    const m = (date.getMonth() + 1).toString().padStart(2, '0');
    const d = date.getDate().toString().padStart(2, '0');
    return `${y}-${m}-${d}`;
  },

  getUserOptions() {
    util
      .request(
        'admin/system.User/productlist',
        {
          limit: 1000,
          page: 1,
          processes_name: '穿孔',
        },
        'POST',
      )
      .then((res) => {
        if (res.data.code === 200 && res.data.data) {
          const users = res.data.data.list.map((user) => ({
            ...user,
            user_id: String(user.user_id),
            dept_names: user.dept_names || '未分配部门',
            nickname: user.nickname || '',
          }));
          this.setData({
            userOptions: users,
            filteredUserOptions: users,
          });
        }
      })
      .catch(() => {});
  },

  showUserPicker() {
    this.setData({
      selectedUserIds: this.data.selectedUsers.map((u) => String(u.user_id)),
      userSearchValue: '',
      filteredUserOptions: [...this.data.userOptions],
      showUserPopup: true,
    });
  },

  hideUserPicker() {
    this.setData({ showUserPopup: false });
  },

  onUserSearch(e) {
    const val = (e.detail || '').trim().toLowerCase();
    this.setData({
      userSearchValue: val,
      filteredUserOptions: val
        ? this.data.userOptions.filter((u) => (u.nickname || '').toLowerCase().includes(val))
        : [...this.data.userOptions],
    });
  },

  onUserSelectChange(e) {
    const selectedIds = Array.isArray(e.detail) ? e.detail : [e.detail];
    this.setData({
      selectedUserIds: selectedIds.map((id) => String(id)),
    });
  },

  confirmUserSelect() {
    const { selectedUserIds, userOptions } = this.data;
    if (selectedUserIds.length === 0) {
      Toast.fail('请至少选择一人');
      return;
    }
    const selectedUsers = userOptions.filter((u) => selectedUserIds.includes(String(u.user_id)));
    this.setData({
      selectedUsers: [...selectedUsers],
      selectedUserDisplay: selectedUsers.map((u) => u.nickname || '').join('、'),
      showUserPopup: false,
    });
    this.calcWage();
  },

  onDateChange(e) {
    this.setData({ workDate: e.detail.value });
  },

  onWorkTypeChange(e) {
    const workType = parseInt(e.detail);
    const workHours = workType === 1 ? 12 : 6;
    this.setData({ workType, workHours });
    this.calcWage();
  },

  onHoursChange(e) {
    let val = parseFloat(e.detail.value);
    if (isNaN(val) || val <= 0) val = 0;
    if (val > 12) val = 12;
    this.setData({ workHours: val });
    this.calcWage();
  },

  onRemarkChange(e) {
    this.setData({ remark: e.detail.value });
  },

  // 穿孔日工：选中人员每人日工工资 + 报工人 1 份岗位津贴
  calcWage() {
    const { workType, workHours, selectedUsers } = this.data;
    const personCount = selectedUsers.length;
    const dailyWagePerPerson = workType === 1 ? 200 : 100;
    const allowanceRatio = Math.min(workHours / 12, 1);
    const leaderAllowance = Math.round(200 * allowanceRatio * 100) / 100;
    const totalWage =
      Math.round((dailyWagePerPerson * personCount + leaderAllowance) * 100) / 100;

    this.setData({
      dailyWagePerPerson,
      leaderAllowance,
      totalWage,
    });
  },

  onSubmit() {
    const { workDate, workType, workHours, remark, selectedUsers, editMode, editId, submitting } =
      this.data;
    if (submitting) return;
    if (!workDate) {
      Toast.fail('请选择工作日期');
      return;
    }
    if (workHours <= 0) {
      Toast.fail('请输入有效工时');
      return;
    }
    if (selectedUsers.length === 0) {
      Toast.fail('请选择报工人员');
      return;
    }

    this.setData({ submitting: true, loading: true });
    wx.showLoading({ title: '提交中...', mask: true });

    const data = {
      work_date: workDate,
      work_type: workType,
      work_hours: workHours,
      remark,
      user_ids: selectedUsers.map((u) => parseInt(u.user_id)),
    };
    const url = editMode ? 'admin/dailyWork/edit' : 'admin/dailyWork/report';
    if (editMode) data.id = editId;

    util
      .request(url, data, 'POST')
      .then((res) => {
        if (res.data.code === 200) {
          wx.showToast({
            title: editMode ? '修改成功' : '报工成功',
            icon: 'success',
            duration: 1200,
          });
          setTimeout(() => {
            wx.navigateBack({ delta: 1 });
          }, 1200);
        } else {
          this.setData({ submitting: false, loading: false });
          Toast.fail(res.data.msg || '提交失败');
        }
      })
      .catch(() => {
        this.setData({ submitting: false, loading: false });
        Toast.fail('网络异常');
      })
      .finally(() => {
        wx.hideLoading();
      });
  },

  navigateBack() {
    wx.navigateBack({ delta: 1 });
  },
});
