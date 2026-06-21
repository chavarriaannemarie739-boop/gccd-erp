import fs from 'fs'
import path from 'path'

const root = path.resolve(process.cwd(), 'dv')
const nested = path.join(root, 'entries', 'dv')

if (!fs.existsSync(nested)) {
  console.warn('[postbuild-dv] 未找到 dv/entries/dv，跳过整理')
  process.exit(0)
}

const indexPath = path.join(nested, 'index.html')
if (fs.existsSync(indexPath)) {
  fs.copyFileSync(indexPath, path.join(root, 'index.html'))
}

fs.rmSync(path.join(root, 'entries'), { recursive: true, force: true })
console.log('[postbuild-dv] 已生成 dv/index.html')
