/**
 * 工单 / 物料 / 能耗 等非 Tab 页底部导航，与 app.json Tab 一致（工作台｜设备｜盘点｜我的）。
 */
const TABS = [
  {
    path: '/pages/workbench/workbench',
    text: '工作台',
    icon: '/images/zhuye.png',
  },
  {
    path: '/pages/equipment/equipment',
    text: '设备',
    icon: '/images/fabu1.png',
  },
  {
    path: '/pages/stocktake/stocktake',
    text: '盘点',
    icon: '/images/ziyuan1.png',
  },
  {
    path: '/pages/mine/mine',
    text: '我的',
    icon: '/images/wode1.png',
  },
];

Component({
  data: {
    safeBottom: 0,
    tabs: TABS,
  },
  lifetimes: {
    attached() {
      try {
        const sys = wx.getSystemInfoSync();
        const bottom = (sys.safeAreaInsets && sys.safeAreaInsets.bottom) || 0;
        this.setData({ safeBottom: bottom });
      } catch (e) {
        this.setData({ safeBottom: 0 });
      }
    },
  },
  methods: {
    onSwitch(e) {
      const path = e.currentTarget.dataset.path;
      if (!path) return;
      // 从工单/物料/能耗返回时，若用 switchTab 仍会停在栈顶子页；reLaunch 可回到工作台首页
      if (path === '/pages/workbench/workbench') {
        wx.reLaunch({ url: path });
        return;
      }
      wx.switchTab({ url: path });
    },
  },
});
