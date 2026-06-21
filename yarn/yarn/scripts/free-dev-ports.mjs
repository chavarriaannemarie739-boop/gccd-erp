/**
 * 开发前释放 9526（PHP）、9527（Vite），避免「换端口 / 旧进程占坑」导致每次更新后打不开预览。
 * macOS / Linux：lsof + kill。Windows 仅提示，请任务管理器结束占用进程。
 */
import { execSync } from 'node:child_process'
import process from 'node:process'

const PORTS = [9526, 9527]

function freePort(port) {
  if (process.platform === 'win32') {
    console.warn(`[dev] Windows：若 ${port} 被占用，请在任务管理器中结束对应进程。`)
    return
  }
  try {
    const out = execSync(`lsof -ti tcp:${port}`, { encoding: 'utf8' }).trim()
    if (!out) return
    for (const pid of out.split(/\n/).filter(Boolean)) {
      try {
        process.kill(Number(pid), 'SIGKILL')
        console.log(`[dev] 已结束占用端口 ${port} 的进程 PID ${pid}`)
      } catch {
        /* ignore */
      }
    }
  } catch {
    /* 无监听进程 */
  }
}

for (const p of PORTS) freePort(p)
