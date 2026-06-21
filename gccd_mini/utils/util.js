import Toast from '@vant/weapp/toast/toast';
const formatTime = date => {
    const year = date.getFullYear()
    const month = date.getMonth() + 1
    const day = date.getDate()
    const hour = date.getHours()
    const minute = date.getMinutes()
    const second = date.getSeconds()

    return `${[year, month, day].map(formatNumber).join('/')} ${[hour, minute, second].map(formatNumber).join(':')}`
}

const formatNumber = n => {
    n = n.toString()
    return n[1] ? n : `0${n}`
}

/**
 * @param {object} [options]
 * @param {(number|string)[]} [options.successCodes] 额外视为成功的业务 code（默认仅 200）
 * @param {boolean} [options.silentFail] 失败时不弹 toast（由调用方自行降级）
 */
function request(url, data = {}, method = "POST", options = {}) {
    const extraSuccess = options.successCodes || []
    const silentFail = options.silentFail === true
    const isSuccessCode = (code) =>
        code == 200 ||
        code === '200' ||
        extraSuccess.some((c) => c == code)
    const toastFail = (title) => {
        if (!silentFail) {
            wx.showToast({ title, icon: 'none' })
        }
    }

    return new Promise((resolve, reject) => {
        wx.request({
            url: 'https://gccd.xnssoft.com/' + url,
            data: data,
            method: method,
            header: {
                AdminToken: wx.getStorageSync('AdminToken'),
            },
            success: (res) => {
                const body = res.data
                if (body == null || typeof body !== 'object') {
                    toastFail('服务返回异常')
                    reject(res)
                    return
                }
                const code = body.code
                if (isSuccessCode(code)) {
                    resolve(res)
                    return
                }
                if (code == 404) {
                    toastFail(body.msg || '资源不存在')
                    reject(res)
                    return
                }
                if (code == 401) {
                    toastFail(body.msg || '请重新登录')
                    wx.removeStorageSync('AdminToken')
                    wx.removeStorageSync('nickname')
                    if (!silentFail) {
                        wx.navigateTo({
                            url: '/pages/login/login',
                        })
                    }
                    reject(res)
                    return
                }
                if (code == 400) {
                    toastFail(body.msg || '请求错误')
                    reject(res)
                    return
                }
                // 其它业务码：必须结束 Promise，否则 .then 永远不执行、页面会「卡住」
                toastFail(body.msg || `请求失败(${code})`)
                reject(res)
            },
            fail: (err) => {
                toastFail('网络异常，请检查网络')
                reject(err)
            }
        })
    })
}
module.exports = {
    formatTime,
    request
}