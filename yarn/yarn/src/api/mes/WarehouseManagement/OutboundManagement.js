import request from '@/utils/request'

const url = '/admin/stock.OutStock/'

/**
 * 出库列表
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
 * 创建出库单
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
 * 出库单删除
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
 * 出库详情
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
 * 修改出库状态
 * @param {array} data 请求数据
 */
export function editStatus(data) {
  return request({
    url: url + 'editStatus',
    method: 'post',
    data
  })
}

/**
 * 物料流追溯：计划单号 / 领料单号 / 出库单号
 */
export function traceMaterialFlow(data) {
  return request({
    url: url + 'traceMaterialFlow',
    method: 'post',
    data
  })
}
