import request from '@/utils/request'

const url = '/admin/production.Ship/'

/**
 * 发货列表
 */
export function list(data) {
  return request({
    url: url + 'list',
    method: 'post',
    data
  })
}

/**
 * 新增发货
 */
export function add(data) {
  return request({
    url: url + 'add',
    method: 'post',
    data
  })
}

/**
 * 历史客户名称
 */
export function customer_list(data) {
  return request({
    url: url + 'customer_list',
    method: 'post',
    data: data || {}
  })
}

/**
 * 删除发货
 */
export function dele(data) {
  return request({
    url: url + 'dele',
    method: 'post',
    data
  })
}
