import request from '@/utils/request'

const url = '/admin/production.ProductTask/'

/**
 * 任务列表
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
 * 按多个生产计划单号批量拉取工单（工序报工汇总，单次请求）
 * @param {{ relation_codes: string[] }} data
 */
export function listByRelationCodes(data) {
  return request({
    url: url + 'listByRelationCodes',
    method: 'post',
    data
  })
}

/**
 * 任务删除
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
 * 任务详情
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
 * 编辑任务（用于同步完成状态）
 * @param {{ id: number, status: number }} data
 */
export function editTask(data) {
  return request({
    url: url + 'edit',
    method: 'post',
    data
  })
}
