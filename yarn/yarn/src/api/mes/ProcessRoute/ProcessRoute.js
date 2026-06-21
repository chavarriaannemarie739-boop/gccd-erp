import request from '@/utils/request'

const url = '/admin/production.Routing/'

/**
 * 工艺列表
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
 * 工艺列表
 * @param {array} data 请求数据
 */
export function listGoods(data) {
  return request({
    url: url + 'list',
    method: 'post',
    data
  })
}

/**
 * 工艺删除
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
 * 创建工艺
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
 * 工艺详情
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
 * 编辑工艺
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
 * 按工艺编码取工序列表（与小程序工单页一致，用于解析报工 report 的工序编码）
 * @param {string} routing_code
 */
export function getProcess(routing_code) {
  return request({
    url: url + 'getProcess',
    method: 'post',
    data: { routing_code }
  })
}
