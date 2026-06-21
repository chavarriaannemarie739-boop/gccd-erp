import request from '@/utils/request'

const url = '/admin/production.Production/'
const urlGoods = '/admin/message.Message/'

/**
 * 生产订单列表
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
 * 工序删除
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
 * 创建生产计划
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
 * 编辑
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
 * 生产计划详情
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
 * 下推计划
 * @param {array} data 请求数据
 */
export function push(data) {
  return request({
    url: url + 'push',
    method: 'post',
    data
  })
}

/**
 * 下推计划
 * @param {array} data 请求数据
 */
export function getProcess(data) {
  return request({
    url: url + 'getProcess',
    method: 'post',
    data
  })
}

/**
 * 导出
 * @param {array} data 请求数据
 */
export function exportExcel(data) {
  return request({
    url: urlGoods + 'exportExcel',
    method: 'post',
    data
  })
}
