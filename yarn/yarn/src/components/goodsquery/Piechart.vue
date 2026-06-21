<template>
  <div>
    <div
      style="
        color: aliceblue;
        margin-top: 10px;
        font-weight: 700;
        font-size: 17px;
        margin-bottom: 10px;
      "
    >
      【入库信息】
    </div>
    <div ref="myDiv" class="radar-echart"></div>
  </div>
</template>

<script>
import * as echarts from 'echarts/core'
import { GridComponent } from 'echarts/components'
import { LineChart } from 'echarts/charts'
import { UniversalTransition } from 'echarts/features'
import { LegendComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([GridComponent, LineChart, CanvasRenderer, UniversalTransition, LegendComponent])

export default {
  name: 'Histogram',
  props: {
    // Production: {
    //   type: Array,
    //   isGetData: false
    // }
  },
  // 页面渲染完毕事件
  mounted() {
    const myChart = echarts.init(this.$refs.myDiv) // 得到图表实例
    myChart.setOption({
      tooltip: {
        trigger: 'axis'
      },
      xAxis: [
        {
          type: 'category',
          data: ['2026-2-6', '2026-2-7', '2026-2-8', '2026-2-9', '2026-2-10'],
          axisLine: {
            lineStyle: {
              color: '#fff'
            }
          }
        }
      ],
      yAxis: [
        {
          min: 0, // 配置 Y 轴刻度最小值
          max: 250, // 配置 Y 轴刻度最大值
          type: 'value',
          splitNumber: 4,
          splitLine: {
            lineStyle: {
              type: 'dashed',
              color: '#fff'
            }
          },
          axisLine: {
            show: false,
            lineStyle: {
              color: '#fff'
            }
          },
          nameTextStyle: {
            color: '#fff'
          },
          splitArea: {
            show: false
          }
        }
      ],
      series: [
        {
          name: '价格',
          type: 'line',
          data: [70, 143, 100, 69, 130],
          lineStyle: {
            normal: {
              width: 8,
              color: {
                type: 'linear',

                colorStops: [
                  {
                    offset: 0,
                    color: '#A9F387' // 0% 处的颜色
                  },
                  {
                    offset: 1,
                    color: '#48D8BF' // 100% 处的颜色
                  }
                ],
                globalCoord: false // 缺省为 false
              },
              shadowColor: 'rgba(72,216,191, 0.3)',
              shadowBlur: 10,
              shadowOffsetY: 20
            }
          },
          itemStyle: {
            normal: {
              color: '#fff',
              borderWidth: 10,
              /*shadowColor: 'rgba(72,216,191, 0.3)',
                shadowBlur: 100,*/
              borderColor: '#A9F387'
            }
          },
          smooth: true
        }
      ]
    })
  }
}
</script>

<style scoped>
.radar-echart {
  width: 100%;
  height: 390px;
}
</style>
