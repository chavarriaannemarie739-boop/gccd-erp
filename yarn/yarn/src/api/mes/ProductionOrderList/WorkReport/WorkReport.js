import request from '@/utils/request'

const url = '/admin/production.Production/'

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
