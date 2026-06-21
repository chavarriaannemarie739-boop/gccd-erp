/**
 * 与小程序 pages/workOrder/workOrder 一致：固定 5 道工序及报工汇总逻辑
 */

export const FIXED_PROCESSES = [
  { name: '穿孔', key: '穿孔' },
  { name: '球化', key: '球化' },
  { name: '矫直', key: '矫直' },
  { name: '酸洗', key: '酸洗' }
]

export const parseReportField = (report) => {
  if (report == null || report === '') return {}
  if (typeof report === 'string') {
    try {
      return JSON.parse(report) || {}
    } catch {
      return {}
    }
  }
  if (typeof report === 'object') return report
  return {}
}

/** routing getProcess 返回的 content：process_code -> 工序显示名 的反向映射（工序名 -> 编码） */
export const buildProcessNameToCodeMap = (content) => {
  const nameToCode = {}
  ;(content || []).forEach((p) => {
    const code = p.process_code
    const name = p.processes_name
    if (code && name) nameToCode[name] = code
  })
  return nameToCode
}

/** 按工艺路线实际工序汇总报工数量；无路线信息时回退固定5道 */
export const aggregatePlanProgress = (tasks, nameToCode, routeContent = []) => {
  const items = routeContent.length > 0
    ? routeContent.slice().sort((a, b) => (a.sort || 0) - (b.sort || 0))
    : FIXED_PROCESSES.map(fp => ({ process_code: nameToCode[fp.name], processes_name: fp.name, sort: 0 }))

  return items.map(item => {
    const code = item.process_code
    const name = item.processes_name || code || '-'
    let sum = 0
    if (code) {
      tasks.forEach((task) => {
        const raw = parseReportField(task.report)
        const cell = raw[code]
        if (cell != null && cell.report_num != null) {
          sum += parseFloat(cell.report_num) || 0
        }
      })
    }
    return { name, report_num: sum }
  })
}
