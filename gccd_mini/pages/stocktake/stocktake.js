Page({
  data: {
    statusBarHeight: 0,
  },

  onLoad() {
    wx.getSystemInfo({
      success: (res) => {
        this.setData({ statusBarHeight: res.statusBarHeight });
      },
    });
  },

  goStocktake() {
    wx.navigateTo({ url: '/pageWorkOrder/pages/stocktakeList/stocktakeList' });
  },

  goQualityCheck() {
    wx.navigateTo({ url: '/pageWorkOrder/pages/qualityCheckList/qualityCheckList' });
  },
});
