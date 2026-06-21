import request from '@/utils/request'

const url = '/admin/processes.Processes/'
const urlGoods = '/admin/wage.Wage/'

/**
 *库存预警列表
 * @param params
 * @returns
 */
// export function list(params) {
//   return request({
//     url: url + 'list',
//     method: 'get',
//     params: params
//   })
// }

/**
 * 工序列表
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
 * 工序详情
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
 * 工序添加
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
 * 新增工序检验项
 * @param {array} data 请求数据
 */
export function addCheck(data) {
  return request({
    url: url + 'addCheck',
    method: 'post',
    data
  })
}

/**
 * 工序列表
 * @param {array} data 请求数据
 */
export function listGoods(data) {
  return request({
    url: urlGoods + 'list',
    method: 'post',
    data
  })
}
