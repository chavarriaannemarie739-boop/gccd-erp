import request from '@/utils/request'

const url = '/admin/receiving.Receiving/'

/**
 *库存预警列表
 * @param params
 * @returns
 */
export function list(params) {
  return request({
    url: url + 'list',
    method: 'get',
    params: params
  })
}
