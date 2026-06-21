Page({
  data: {
    statusBarHeight: 0,
  },

  navigateBack() {
    wx.navigateBack({ delta: 1 });
  },

  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({ statusBarHeight: res.statusBarHeight });
      },
    });
  },
});
