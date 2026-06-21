import request from '@/utils/request'

const url = '/admin/production.ProductCount/'

/**
 * 维修项列表
 */
export function list(data) {
  return request({
    url: url + 'list',
    method: 'post',
    data
  })
}

/**
 * 大类列表
 */
export function category() {
  return request({
    url: url + 'category',
    method: 'post'
  })
}

/**
 * 维修项添加
 */
export function add(data) {
  return request({
    url: url + 'add',
    method: 'post',
    data
  })
}

/**
 * 维修项删除
 */
export function dele(data) {
  return request({
    url: url + 'dele',
    method: 'post',
    data
  })
}

/**
 * 维修项编辑
 */
export function edit(data) {
  return request({
    url: url + 'edit',
    method: 'post',
    data
  })
}
