import axios from 'axios'
import { getDvConfig } from '@/utils/dv-config'

const kioskUrl = '/admin/wechat.WeChat/dashboardBoardKiosk'

/**
 * 车间大屏 / TV 只读驾驶舱数据（Header: X-Kiosk-Key）
 *
 * @param {object} data 请求体
 * @returns {Promise<object>}
 */
export async function dashboardBoardKiosk(data) {
  const cfg = await getDvConfig()
  const baseURL = cfg.apiBaseUrl || import.meta.env.VITE_APP_BASE_URL || ''
  const kioskKey = cfg.kioskKey || import.meta.env.VITE_KIOSK_KEY || ''

  const client = axios.create({
    baseURL,
    timeout: 120000,
    headers: {
      'X-Kiosk-Key': kioskKey,
      'think-lang': 'zh-cn'
    }
  })

  const response = await client.post(kioskUrl, data || {})
  return response.data
}
