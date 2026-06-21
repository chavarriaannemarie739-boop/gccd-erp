import request from '@/utils/request'

const url = '/admin/wechat.WeChat/'

/**
 * 抄表列表
 * @param {array} data 请求数据
 */
export function index(data) {
  return request({
    url: url + 'index',
    method: 'post',
    data
  })
}

/** 首页驾驶舱：设备、工序能耗、报工/质检不良、计划进度、工序实时产量 */
export function dashboardBoard(data) {
  return request({
    url: url + 'dashboardBoard',
    method: 'post',
    data: data || {}
  })
}

/** 设备台账列表（驾驶舱 devices 为空时可二次拉取 device_list） */
export function getDevice(data) {
  return request({
    url: url + 'getDevice',
    method: 'post',
    data: data || {}
  })
}
