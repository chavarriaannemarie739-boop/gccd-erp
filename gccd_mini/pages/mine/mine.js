const util = require('../../utils/util.js');
import Toast from '@vant/weapp/toast/toast';
import Dialog from '@vant/weapp/dialog/dialog';

Page({
  data: {
    // 导航栏相关内容
    statusBarHeight: 0,
    // 用户信息
    nickName: '',
    // 加载状态
    loading: false,
  },

  // 获取用户信息
  getUserInfo() {
    // 从本地存储获取昵称
    const userInfo = wx.getStorageSync('nickname') || {};
    this.setData({
      nickName: userInfo || '',
    });
  },

  // 处理退出登录
  handleLogout() {
    Dialog.confirm({
      title: '提示',
      message: '确定要退出登录吗？',
    })
      .then(() => {
        // 清除本地存储的token和用户信息
        wx.removeStorageSync('AdminToken');
        wx.removeStorageSync('userInfo');

        Toast.success('已退出登录');

        // 延迟跳转到登录页或首页
        setTimeout(() => {
          wx.reLaunch({
            url: '/pages/login/login',
          });
        }, 1500);
      })
      .catch(() => {
        // 用户取消了操作
      });
  },

  // 导航栏宽高获取
  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({
          statusBarHeight: res.statusBarHeight,
        });
      },
    });
  },

  onShow() {
    this.getUserInfo();
  },
});
