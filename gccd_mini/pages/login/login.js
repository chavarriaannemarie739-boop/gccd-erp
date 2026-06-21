const util = require('../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';
Page({
  /**
   * 页面的初始数据
   */
  data: {
    phone: '',
    verify_code: '',
  },
  // getImage(){
  //     util.request("/index/getPolicies", {}, method)
  //     .then((res) => {
  //         console.log(res);
  //     })
  //     https://qyzj.xnssoft.com/qyzj_logo.png
  // },
  onChange1(event) {
    // event.detail 为当前输入的值
    // console.log(event.detail);
    this.setData({
      phone: event.detail,
    });
  },
  onChange2(event) {
    // event.detail 为当前输入的值
    // console.log(event.detail);
    this.setData({
      verify_code: event.detail,
    });
  },
  // // 倒计时逻辑
  // startCountdown() {
  //     this.setData({
  //         isCounting: true,
  //         countdown: 60
  //     });
  //     const timer = setInterval(() => {
  //         if (this.data.countdown <= 1) {
  //             clearInterval(timer);
  //             this.setData({
  //                 isCounting: false,
  //                 countdown: 60
  //             });
  //         } else {
  //             this.setData({
  //                 countdown: this.data.countdown - 1
  //             });
  //         }
  //     }, 1000);
  // },
  // 获取验证码
  // getCode() {
  //     console.log(this.data.isCounting);
  //     // 校验手机号格式
  //     if (!/^1[3-9]\d{9}$/.test(this.data.phone)) {
  //         Toast.fail('手机号格式错误');
  //         return;
  //     }
  //     this.startCountdown()
  //     util.request("/index/sendVerifySms", {
  //             phone: this.data.phone
  //         })
  //         .then((res) => {
  //             if (res.data.code === 1) {
  //                 this.startCountdown(); // 请求成功后再开始倒计时
  //             } else {
  //                 Toast.fail(res.info || '发送失败');
  //             }
  //         })
  //         .catch((err) => {
  //             Toast.fail('请求异常');
  //         });
  // },
  loginFun() {
    const username = (this.data.phone || '').trim();
    const password = (this.data.verify_code || '').trim();
    if (!username || !password) {
      Toast.fail('请输入账号和密码');
      return;
    }
    // 部分后端登录成功返回 code 为 1，与全局 200 并存
    util
      .request('admin/system.Login/login', { username, password }, 'POST', {
        successCodes: [1, '1'],
      })
      .then((res) => {
        const payload = res.data && res.data.data;
        if (!payload) {
          Toast.fail(res.data.msg || '登录数据异常');
          return;
        }
        const token = payload.AdminToken || payload.adminToken || payload.token;
        if (!token) {
          Toast.fail('未返回登录令牌，请联系管理员');
          return;
        }
        wx.setStorageSync('AdminToken', token);
        wx.setStorageSync('nickname', payload.nickname || '');
        wx.setStorageSync('user_id', payload.user_id != null ? payload.user_id : '');
        wx.setStorageSync('dept_names', payload.dept_names || '');
        wx.setStorageSync('role_names', payload.role_names || '');
        wx.setStorageSync('is_super', payload.is_super || 0);
        Toast.success('登录成功');
        // 用 reLaunch 清空栈并进入 Tab 首页，避免 switchTab 在部分场景不触发
        setTimeout(() => {
          wx.reLaunch({
            url: '/pages/workbench/workbench',
          });
        }, 400);
      })
      .catch(() => {
        // util 已 toast；此处兜底，避免静默失败
      });
  },
  //   去注册页面
  goRegistration() {
    wx.navigateTo({
      url: '/pages/registration/registration',
    });
  },
  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {},

  /**
   * 生命周期函数--监听页面初次渲染完成
   */
  onReady() {},

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {},

  /**
   * 生命周期函数--监听页面隐藏
   */
  onHide() {},

  /**
   * 生命周期函数--监听页面卸载
   */
  onUnload() {},

  /**
   * 页面相关事件处理函数--监听用户下拉动作
   */
  onPullDownRefresh() {},

  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {},

  /**
   * 用户点击右上角分享
   */
  onShareAppMessage() {},
});
