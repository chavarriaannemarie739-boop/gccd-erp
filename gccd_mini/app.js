// app.js
App({
    onLaunch() {
        // 展示本地存储能力
        const logs = wx.getStorageSync('logs') || []
        logs.unshift(Date.now())
        wx.setStorageSync('logs', logs)

        // 检查是否有 AdminToken
        const adminToken = wx.getStorageSync('AdminToken')

        // Tab 页须用 switchTab；reLaunch 在 glass-easel 下易导致首屏空白
        if (adminToken) {
            wx.switchTab({
                url: '/pages/workbench/workbench'
            })
        } else {
            wx.reLaunch({
                url: '/pages/login/login'
            })
        }

        // 登录
        wx.login({
            success: res => {
                // 发送 res.code 到后台换取 openId, sessionKey, unionId
            }
        })
    },
    globalData: {
        userInfo: null
    }
})