import request from '@/utils/request'

const url = '/admin/material.Stock/'

/**
 * 仓库列表
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
 * 仓库列表
 * @param {array} data 请求数据
 */
export function listGoods(data) {
  return request({
    url: url + 'list',
    method: 'post',
    data
  })
}

/**
 * 仓库新增
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
 * 仓库编辑
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
 * 仓库删除
 * @param {array} data 请求数据
 */
export function dele(data) {
  return request({
    url: url + 'dele',
    method: 'post',
    data
  })
}
