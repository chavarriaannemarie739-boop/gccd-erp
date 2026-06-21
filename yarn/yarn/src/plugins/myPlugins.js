import clip from '@/utils/clipboard'
import screenHeight from '@/utils/screen-height'
import { getPageLimit } from '@/utils/settings'
import { handleBack } from '@/utils/index.js'
//按需导入echarts
// 引入 echarts 核心模块，核心模块提供了 echarts 使用必须要的接口。
import * as echarts from 'echarts/core'
// 引入柱状图图表，图表后缀都为 Chart
import { LineChart } from 'echarts/charts'
// 引入提示框，标题，直角坐标系，数据集，内置数据转换器组件，组件后缀都为 Component
import {
  TitleComponent,
  TooltipComponent,
  GridComponent,
  DatasetComponent,
  TransformComponent,
  ToolboxComponent
} from 'echarts/components'
// 标签自动布局、全局过渡动画等特性
import { LabelLayout, UniversalTransition } from 'echarts/features'
// 引入 Canvas 渲染器，注意引入 CanvasRenderer 或者 SVGRenderer 是必须的一步
import { CanvasRenderer } from 'echarts/renderers'
// 注册必须的组件
echarts.use([
  TitleComponent,
  TooltipComponent,
  GridComponent,
  DatasetComponent,
  TransformComponent,
  LineChart,
  LabelLayout,
  UniversalTransition,
  CanvasRenderer,
  ToolboxComponent
])
import Pagination from '@/components/Pagination/index.vue'
import preventReClick from '@/directive/common/preventReClick.js'
import hasPermi from '@/directive/common/hasPermi.js'
import checkPermission from '@/utils/permission'
//解决精度丢失问题
import NP from 'number-precision'
import { datetime } from '@/utils/index.js'
const installPlugins = {
  install(app, options) {
    //获取当前时间
    app.config.globalProperties.$datetime = datetime
    //echarts
    app.config.globalProperties.$echarts = echarts
    //复制
    app.config.globalProperties.$clip = clip
    //获取屏幕高度
    app.config.globalProperties.$screenHeight = screenHeight
    //每页条数
    app.config.globalProperties.$getPageLimit = getPageLimit
    //关闭当前页面
    app.config.globalProperties.$handleBack = handleBack
    //权限控制
    app.config.globalProperties.checkPermission = checkPermission
    /**
     * 解决精度丢失
     * NP.strip(num)         // 转为最接近的正确的数字
     * NP.plus(num1, num2, num3, ...)   // 加法：num + num2 + num3, 至少需要2个参数
     * NP.minus(num1, num2, num3, ...)  // 减法：num1 - num2 - num3
     * NP.times(num1, num2, num3, ...)  // 乘法：num1 * num2 * num3
     * NP.divide(num1, num2, num3, ...) // 除法：num1 / num2 / num3
     * NP.round(num, ratio)  // 根据ratio取整
     */

    app.config.globalProperties.$NP = NP
    app.component('Pagination', Pagination)
  }
}
export default installPlugins
