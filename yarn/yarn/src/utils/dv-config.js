let cachedConfig = null

const defaultConfig = () => ({
  apiBaseUrl: import.meta.env.VITE_APP_BASE_URL || '',
  kioskKey: import.meta.env.VITE_KIOSK_KEY || ''
})

/**
 * 读取运行时配置（部署后可改 public/dv-config.json 而无需重打包）
 *
 * @returns {Promise<{ apiBaseUrl: string, kioskKey: string }>}
 */
export async function getDvConfig() {
  if (cachedConfig) {
    return cachedConfig
  }

  const fallback = defaultConfig()
  const base = import.meta.env.BASE_URL || '/'
  const configCandidates = [
    './dv-config.json',
    `${base}dv-config.json`.replace(/\/+/g, '/'),
    '/dv/dv-config.json'
  ]
  const uniquePaths = [...new Set(configCandidates)]

  for (const configPath of uniquePaths) {
    try {
      const resp = await fetch(configPath, { cache: 'no-store' })
      if (resp.ok) {
        const json = await resp.json()
        cachedConfig = {
          apiBaseUrl: json.apiBaseUrl || fallback.apiBaseUrl,
          kioskKey: json.kioskKey || fallback.kioskKey
        }
        return cachedConfig
      }
    } catch {
      // try next path
    }
  }

  console.warn('[dv-config] 未读取到 dv-config.json，使用环境变量')
  cachedConfig = fallback
  return cachedConfig
}

/** 清除缓存（重试连接时可用） */
export function resetDvConfigCache() {
  cachedConfig = null
}
