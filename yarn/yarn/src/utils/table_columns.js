
/**
 * el-table扩展工具  -- 列宽度自适应
 * @param {*} prop 字段名称(string)
 * @param {*} tableData table数据列表集(array)
 * @returns 列宽(int)
 */
export function getColumnWidth(label, prop, tableData) {
    //label表头名称
    //prop对应的内容
    //tableData表格数据
   
    const minWidth = 80 // 最小宽度
    const padding = 0 // 列内边距
    let arr = tableData.map(item => item[prop])
    arr.push(label)//拼接内容和表头数据
    const contentWidths = arr.map(item => {
      const value = item ? String(item) : ''
      const textWidth = getTextWidth(value)
      return textWidth + padding
    })
    const maxWidth = Math.max(...contentWidths)
    return Math.max(minWidth, maxWidth)
  }
   
  /**
   * el-table扩展工具  -- 列宽度自适应 - 获取列宽内文本宽度
   * @param {*} text 文本内容
   * @returns 文本宽度(int)
   */
  function getTextWidth(text) {
    const span = document.createElement('span')
    span.style.visibility = 'hidden'
    span.style.position = 'absolute'
    span.style.top = '-9999px'
    span.style.whiteSpace = 'nowrap'
    span.innerText = text
    document.body.appendChild(span)
    const width = span.offsetWidth + 40
    document.body.removeChild(span)
    return width
}


/**
 * 获取操作列最大宽度
 * @returns 
 */
export  function getOperatorWidth() {
    // 默认宽度
    let width = 100
    // 内间距
    let paddingSpacing = 8
    // 按钮数量
    let buttonCount = 0

    const operatorColumn = document.getElementsByClassName('optionDiv')
    // 如果节点数量大于0-循环这个节点，
    if (operatorColumn.length > 0) {
        operatorColumn.forEach(item => {
            // 最宽的宽度
            width = width > item.offsetWidth ? width : item.offsetWidth
            const buttons = item.getElementsByClassName('el-button')
            // 计算 <el-button> 标签的数量
            buttonCount = buttons.length
            buttonCount = buttonCount > buttons.length ? buttonCount : buttons.length
        })
        if (buttonCount > 2) {
            width += (paddingSpacing * (buttonCount - 1))
        }
    }
    return width
}
