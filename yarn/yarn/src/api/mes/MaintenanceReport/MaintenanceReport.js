import request from '@/utils/request'

const url = '/admin/repair.Device/'

/**
 * 机修电工日常报工列表
 * @param {object} data 请求数据
 */
export function maintenanceReportList(data) {
  return request({
    url: url + 'maintenanceReportList',
    method: 'post',
    data
  })
}

/**
 * 机修电工日常报工编辑
 * @param {object} data 请求数据
 */
export function maintenanceReportEdit(data) {
  return request({
    url: url + 'maintenanceReportEdit',
    method: 'post',
    data
  })
}

/**
 * 机修电工日常报工删除
 * @param {object} data 请求数据
 */
export function maintenanceReportDele(data) {
  return request({
    url: url + 'maintenanceReportDele',
    method: 'post',
    data
  })
}
