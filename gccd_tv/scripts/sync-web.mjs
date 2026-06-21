import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, '..')
const srcDir = path.resolve(root, '../yarn/yarn/dv')
const destDir = path.resolve(root, 'www')

if (!fs.existsSync(srcDir)) {
  console.error('[sync-web] 请先执行 yarn/yarn 下 npm run build:dv')
  process.exit(1)
}

fs.rmSync(destDir, { recursive: true, force: true })
fs.cpSync(srcDir, destDir, { recursive: true })

/** Capacitor 本地加载时使用相对路径资源 */
const indexPath = path.join(destDir, 'index.html')
if (fs.existsSync(indexPath)) {
  let html = fs.readFileSync(indexPath, 'utf8')
  html = html.replace(/\/dv\//g, './')
  fs.writeFileSync(indexPath, html)
}

const configPath = path.join(destDir, 'dv-config.json')
if (fs.existsSync(configPath)) {
  const cfg = JSON.parse(fs.readFileSync(configPath, 'utf8'))
  if (!cfg.apiBaseUrl || cfg.apiBaseUrl.includes('请与')) {
    cfg.apiBaseUrl = 'https://gccd.xnssoft.com'
  }
  fs.writeFileSync(configPath, `${JSON.stringify(cfg, null, 2)}\n`)
}

console.log('[sync-web] 已同步到 gccd_tv/www')
