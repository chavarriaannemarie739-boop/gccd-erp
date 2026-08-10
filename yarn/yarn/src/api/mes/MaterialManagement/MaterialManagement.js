import request from '@/utils/request'

const url = '/admin/production.Material/'

/**
 * 物料列表
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
 * [AI-2026-08] 圆钢档案导航树（名称>厂商>材质>型号）
 * @param {object} [data]
 */
export function roundSteelTree(data = {}) {
  return request({
    url: url + 'roundSteelTree',
    method: 'post',
    data
  })
}

/**
 * 物料删除
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
 * 创建物料
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
 * 物料详情
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
 * 编辑物料
 * @param {array} data 请求数据
 */
export function edit(data) {
  return request({
    url: url + 'edit',
    method: 'post',
    data
  })
}
