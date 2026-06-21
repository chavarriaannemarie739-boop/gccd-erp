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
    selectedUsers: [], // 选中的人员对象数组
    selectedUserIds: [], // 选中的人员ID数组
    selectedUserDisplay: '', // 显示文本
    userOptions: [], // 所有人员选项
    filteredUserOptions: [], // 过滤后的人员选项
    showUserPopup: false,
    userSearchValue: '',

    // 工资预览
    dailyWagePerPerson: 200, // 每人日工工资
    allowancePerPerson: 0, // 每人岗位津贴
    totalWage: 0, // 工资合计

    isTeamLeader: false,

    editMode: false,
    editId: 0,
  },

  onLoad(options) {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({ statusBarHeight: res.statusBarHeight });
      },
    });

    const today = this.formatDate(new Date());
    this.setData({ workDate: today });

    if (options && options.id) {
      this.setData({ editMode: true, editId: parseInt(options.id) });
    }

    this.getUserOptions();
    this.checkTeamLeader();
  },

  formatDate(date) {
    const y = date.getFullYear();
    const m = (date.getMonth() + 1).toString().padStart(2, '0');
    const d = date.getDate().toString().padStart(2, '0');
    return `${y}-${m}-${d}`;
  },

  // 获取穿孔工序人员列表
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

  // 检查当前用户是否为班长
  checkTeamLeader() {
    util
      .request('admin/dailyWork/checkLeader', {}, 'POST')
      .then((res) => {
        if (res.data.code === 200) {
          this.setData({ isTeamLeader: res.data.data.isTeamLeader || false });
          this.calcWage();
        }
      })
      .catch(() => {});
  },

  // 显示人员选择弹窗
  showUserPicker() {
    this.setData({
      selectedUserIds: this.data.selectedUsers.map((u) => String(u.user_id)),
      userSearchValue: '',
      filteredUserOptions: [...this.data.userOptions],
      showUserPopup: true,
    });
  },

  // 隐藏人员选择弹窗
  hideUserPicker() {
    this.setData({ showUserPopup: false });
  },

  // 人员搜索
  onUserSearch(e) {
    const val = (e.detail || '').trim().toLowerCase();
    this.setData({
      userSearchValue: val,
      filteredUserOptions: val
        ? this.data.userOptions.filter((u) => (u.nickname || '').toLowerCase().includes(val))
        : [...this.data.userOptions],
    });
  },

  // 人员选择变化
  onUserSelectChange(e) {
    const selectedIds = Array.isArray(e.detail) ? e.detail : [e.detail];
    this.setData({
      selectedUserIds: selectedIds.map((id) => String(id)),
    });
  },

  // 确认选择人员
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

  // 日期变更
  onDateChange(e) {
    this.setData({ workDate: e.detail.value });
  },

  // 报工类型变更
  onWorkTypeChange(e) {
    const workType = parseInt(e.detail);
    const workHours = workType === 1 ? 12 : 6;
    this.setData({ workType, workHours });
    this.calcWage();
  },

  // 工时变更
  onHoursChange(e) {
    let val = parseFloat(e.detail.value);
    if (isNaN(val) || val <= 0) val = 0;
    if (val > 12) val = 12;
    this.setData({ workHours: val });
    this.calcWage();
  },

  // 备注变更
  onRemarkChange(e) {
    this.setData({ remark: e.detail.value });
  },

  // 计算工资
  calcWage() {
    const { workType, workHours, isTeamLeader, selectedUsers } = this.data;
    const personCount = selectedUsers.length || 1;
    const dailyWagePerPerson = workType === 1 ? 200 : 100;
    const allowanceRatio = Math.min(workHours / 12, 1);
    const allowancePerPerson = isTeamLeader ? Math.round(200 * allowanceRatio * 100) / 100 : 0;
    const totalWage =
      Math.round((dailyWagePerPerson + allowancePerPerson) * personCount * 100) / 100;
    this.setData({ dailyWagePerPerson, allowancePerPerson, totalWage });
  },

  // 提交报工
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
          Toast.success(editMode ? '修改成功' : '报工成功');
          setTimeout(() => {
            wx.navigateBack({ delta: 1 });
          }, 800);
        } else {
          this.setData({ submitting: false });
          Toast.fail(res.data.msg || '提交失败');
        }
      })
      .catch(() => {
        this.setData({ submitting: false });
        Toast.fail('网络异常');
      })
      .finally(() => {
        this.setData({ loading: false });
      });
  },

  navigateBack() {
    wx.navigateBack({ delta: 1 });
  },
});
