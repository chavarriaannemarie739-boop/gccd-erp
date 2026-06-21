const defaultSettings = {
  loginBgUrl: '',
  loginBgColor: '',
  systemName: 'yylAdmin',
  pageTitle: '',
  faviconUrl: '',
  logoUrl: '',
  sidebarLogo: true,
  sidebarName: true,
  fixedHeader: true,
  tagsView: false,
  layout: 'left', // 导航模式：left | top | mix（全站已固定左侧，见 App.vue）
  theme: 'light', // 主题：light明亮，dark暗黑
  themeColor: '#409EFF', // 主题颜色
  tokenType: 'header', // token方式，header、param
  tokenName: 'AdminToken', // token名称，前后端必须一致
  pageLimit: 10, // 分页每页默认数量
  size: 'default', // 组件大小：large、default、small
  language: 'zh-cn', //语言：zh-cn中文，en英文
  storePrefix: 'admin_' // 本地存储前缀
}

export default defaultSettings
