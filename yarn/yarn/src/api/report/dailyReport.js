import request from '@/utils/request'

/** 与路由 production.DailyReport 一致（支持 start_date / end_date） */
const url = '/admin/production.DailyReport/'

/**
 * 获取报表数据（支持日期范围）
 * @param {Object} params - 查询参数
 * @param {string} params.start_date - 开始日期
 * @param {string} params.end_date - 结束日期
 * @param {string} params.processes_name - 工序名称（可选）
 * @returns {Promise}
 */
export function getDailyReport(params) {
  return request({
    url: url + 'getReport',
    method: 'get',
    params
  })
}

/**
 * 保存手动输入数据
 * @param {Object} data - 数据
 * @returns {Promise}
 */
export function saveManualData(data) {
  return request({
    url: url + 'saveManualData',
    method: 'post',
    data
  })
}

/**
 * 批量保存手动输入数据
 * @param {Object} data - 数据
 * @returns {Promise}
 */
export function batchSaveManualData(data) {
  return request({
    url: url + 'batchSaveManualData',
    method: 'post',
    data
  })
}

/**
 * 导出生产统计日报 Excel（与页面日期范围一致）
 * @param {Object} params start_date, end_date, processes_name（可选）
 * @returns {Promise<{ code, data: { file_name, file_path, download_url }, msg }>}
 */
export function exportDailyReportExcel(params) {
  return request({
    url: url + 'export',
    method: 'get',
    params
  })
}

const wechatUrl = '/admin/wechat.WeChat/'
const productionUrl = '/admin/production.Production/'

/** 工序每日生产报表：列表数据（与导出同源） */
export function getProcessDailyReport(params) {
  return request({
    url: wechatUrl + 'processDailyReport',
    method: 'get',
    params
  })
}

/** 工序每日生产报表 Excel（与日期区间一致） */
export function exportProcessDailyReportApi(params) {
  return request({
    url: wechatUrl + 'exportProcessDailyReport',
    method: 'get',
    params
  })
}

/** 工序成本日报表：列表数据（与导出同源统计） */
export function getProcessCostDailyReport(data) {
  return request({
    url: productionUrl + 'getProcessCostDailyReport',
    method: 'post',
    data: data || {}
  })
}

/** 工序成本日报表导出 Excel（与日期区间一致，POST） */
export function exportProcessCostDailyReportApi(data) {
  return request({
    url: productionUrl + 'exportProcessCostDailyReport',
    method: 'post',
    data
  })
}
