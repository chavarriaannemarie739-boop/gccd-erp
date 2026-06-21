import request from '@/utils/request'

const url = '/admin/production.Production/'

/**
 * 流转卡打印选项
 */
export function flowCardPrintOptions(data) {
  return request({
    url: url + 'flowCardPrintOptions',
    method: 'post',
    data
  })
}

/**
 * 流转卡标签数据
 */
export function flowCardLabel(data) {
  return request({
    url: url + 'flowCardLabel',
    method: 'post',
    data
  })
}
