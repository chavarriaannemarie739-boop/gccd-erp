/**
 * Vite 5+ 在 resolveConfig 中会调用 node:crypto 的 getRandomValues（见 vite dist chunks）。
 * Node 过低或非官方/被替换的运行时会导致：TypeError: crypto$2.getRandomValues is not a function
 */
import crypto from 'node:crypto'

const ver = process.version
const [maj, min] = process.versions.node.split('.').map(Number)

if (maj < 18 || (maj === 18 && min < 18)) {
  console.error(`[vite] 当前 Node ${ver}，项目要求 >= 18.18.0（package.json engines）。`)
  console.error('[vite] 请安装 Node 20 LTS（https://nodejs.org/）或使用 nvm-windows 切换后重新打开终端。')
  process.exit(1)
}

if (typeof crypto.getRandomValues !== 'function') {
  console.error(`[vite] Node ${ver} 下未提供 crypto.getRandomValues，Vite 无法启动。`)
  console.error('[vite] 请升级到 Node 18.18+ / 20 LTS；在终端执行 where node 确认 PATH 指向官方安装目录。')
  process.exit(1)
}

try {
  crypto.getRandomValues(new Uint8Array(1))
} catch (e) {
  console.error(`[vite] crypto.getRandomValues 调用失败：${e?.message || e}`)
  console.error('[vite] 请更换 Node 版本或检查环境（部分精简/便携 Node 可能缺 Web Crypto）。')
  process.exit(1)
}
