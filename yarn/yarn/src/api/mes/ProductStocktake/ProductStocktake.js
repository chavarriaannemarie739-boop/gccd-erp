import request from '@/utils/request'

const url = '/admin/production.Inventory/'

/**
 * 智能推算盘点库存
 * @param {object} data 请求数据（calc_date 可选）
 */
export function autoCalc(data) {
  return request({
    url: url + 'autoCalc',
    method: 'post',
    data
  })
}

/**
 * 盘点列表
 * @param {object} data 请求数据
 */
export function list(data) {
  return request({
    url: url + 'list',
    method: 'post',
    data
  })
}

/**
 * 盘点详情
 * @param {object} data 请求数据
 */
export function info(data) {
  return request({
    url: url + 'info',
    method: 'post',
    data
  })
}

/**
 * 添加盘点
 * @param {object} data 请求数据
 */
export function add(data) {
  return request({
    url: url + 'add',
    method: 'post',
    data
  })
}

/**
 * 修改盘点
 * @param {object} data 请求数据
 */
export function edit(data) {
  return request({
    url: url + 'edit',
    method: 'post',
    data
  })
}

/**
 * 删除盘点
 * @param {object} data 请求数据
 */
export function dele(data) {
  return request({
    url: url + 'dele',
    method: 'post',
    data
  })
}

/**
 * 月度库存台账
 * @param {object} data 请求数据（year_month 可选，格式 "2026-05"）
 */
export function monthlyLedger(data) {
  return request({
    url: url + 'monthlyLedger',
    method: 'post',
    data
  })
}

/**
 * 批量提交盘点（与小程序 submitStocktake 同源）
 * @param {object} data { stocktake_date, process_name, remark?, items[] }
 */
export function submitStocktake(data) {
  return request({
    url: url + 'submitStocktake',
    method: 'post',
    data
  })
}
