import request from '@/utils/request'

const url = '/admin/stock.InStock/'

/**
 * 入库列表
 * @param {array} data 请求数据
 */
export function list(data) {
  return request({
    url: url + 'list',
    method: 'post',
    data
  })
}

/**
 * 入库详情
 * @param {array} data 请求数据
 */
export function info(data) {
  return request({
    url: url + 'info',
    method: 'post',
    data
  })
}

/**
 * 入库单删除
 * @param {array} data 请求数据
 */
export function dele(data) {
  return request({
    url: url + 'dele',
    method: 'post',
    data
  })
}

/**
 * 新增
 * @param {array} data 请求数据
 */
export function add(data) {
  return request({
    url: url + 'add',
    method: 'post',
    data
  })
}

/**
 * 物料仓库
 * @param {array} data 请求数据
 */
export function mateiallist(data) {
  return request({
    url: url + 'mateiallist',
    method: 'post',
    data
  })
}

/**
 * 入库编辑
 * @param {array} data 请求数据
 */
export function edit(data) {
  return request({
    url: url + 'edit',
    method: 'post',
    data
  })
}

/**
 * 所有物料的批次
 * @param {array} data 请求数据
 */
export function getBatch(data) {
  return request({
    url: url + 'getBatch',
    method: 'post',
    data
  })
}

/**
 * 获取对应物料批次
 * @param {array} data 请求数据
 */
export function getMaterialBatch(data) {
  return request({
    url: url + 'getMaterialBatch',
    method: 'post',
    data
  })
}

/**
 * 配件/圆钢库存预警汇总
 * @param {object} data 请求数据
 */
export function stockAlertSummary(data) {
  return request({
    url: url + 'stockAlertSummary',
    method: 'post',
    data
  })
}

/**
 * 成品库操作权限
 */
export function stockActionPermissions(data) {
  return request({
    url: url + 'stockActionPermissions',
    method: 'post',
    data
  })
}

/**
 * 客户余量部分转库存
 */
export function transferToStock(data) {
  return request({
    url: url + 'transferToStock',
    method: 'post',
    data
  })
}

/**
 * 成品库归属清零（主管）
 */
export function writeOffStock(data) {
  return request({
    url: url + 'writeOffStock',
    method: 'post',
    data
  })
}
