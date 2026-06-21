import request from '@/utils/request'

// 获取系数列表
export function getCoefficientList(params) {
  return request({
    url: '/admin/ReportCoefficient/list',
    method: 'get',
    params
  })
}

// 新增系数
export function addCoefficient(data) {
  return request({
    url: '/admin/ReportCoefficient/add',
    method: 'post',
    data
  })
}

// 编辑系数
export function editCoefficient(data) {
  return request({
    url: '/admin/ReportCoefficient/edit',
    method: 'post',
    data
  })
}

// 删除系数
export function deleteCoefficient(ids) {
  return request({
    url: '/admin/ReportCoefficient/delete',
    method: 'post',
    data: { ids }
  })
}

// 获取系数类型选项
export function getCoefficientTypes() {
  return request({
    url: '/admin/ReportCoefficient/add',
    method: 'get'
  })
}
