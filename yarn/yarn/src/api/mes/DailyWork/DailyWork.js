import request from '@/utils/request'

const url = '/admin/dailyWork/'

export function list(data) {
  return request({ url: url + 'list', method: 'post', data })
}

export function report(data) {
  return request({ url: url + 'report', method: 'post', data })
}

export function edit(data) {
  return request({ url: url + 'edit', method: 'post', data })
}

export function dele(data) {
  return request({ url: url + 'dele', method: 'post', data })
}

export function stat(data) {
  return request({ url: url + 'stat', method: 'post', data })
}

export function getByDate(data) {
  return request({ url: url + 'getByDate', method: 'post', data })
}
