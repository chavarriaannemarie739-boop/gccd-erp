import request from '@/utils/request'

const url = '/admin/wage.UserWage/'

/**
 * 工资列表
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
 * 员工月度工资详情（含报工明细）
 * @param {object} data { id }
 */
export function info(data) {
  return request({
    url: url + 'info',
    method: 'post',
    data
  })
}

/**
 * 员工报工工资列表
 * @param {array} data 请求数据
 */
export function getUserDayWage(data) {
  return request({
    url: url + 'getUserDayWage',
    method: 'post',
    data
  })
}
