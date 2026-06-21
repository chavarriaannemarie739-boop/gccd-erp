import request from '@/utils/request'

const url = '/admin/repair.Device/'

/**
 * 设备台账
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
 * 维修记录
 * @param {array} data 请求数据
 */
export function repairlist(data) {
  return request({
    url: url + 'repairlist',
    method: 'post',
    data
  })
}

/**
 * 添加设备
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
 * 批量删除维修记录（软删除）
 */
export function repairDele(data) {
  return request({
    url: url + 'repairDele',
    method: 'post',
    data
  })
}

/** 按工序筛选设备（穿孔选机台） */
export function listByProcess(data) {
  return request({
    url: url + 'listByProcess',
    method: 'post',
    data
  })
}
