import request from '@/utils/request'

const url = '/admin/production.ProductReport/'

/**
 * 抄表列表
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
 * 报工删除
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
 * 能源统计
 * @param {array} data 请求数据
 */
export function powerStat(data) {
  return request({
    url: url + 'powerStat',
    method: 'post',
    data
  })
}

/**
 * 报工详情
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
 * 抄表列表
 * @param {array} data 请求数据
 */
export function powerlist(data) {
  return request({
    url: url + 'powerlist',
    method: 'post',
    data
  })
}

/**
 * 编辑报工
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
 * 抄表列表删除

 * @param {array} data 请求数据
 */
export function deletePowerRecord(data) {
  return request({
    url: url + 'deletePowerRecord',
    method: 'post',
    data
  })
}

/**
 * 酸洗车间求和
 * @param {array} data 请求数据
 */
export function getPowerConsumptionStats(data) {
  return request({
    url: url + 'getPowerConsumptionStats',
    method: 'post',
    data
  })
}
