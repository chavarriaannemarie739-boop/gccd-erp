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
      【用电/气体消耗】
    </div>
    <div ref="myDiv" class="radar-echart"></div>
  </div>
</template>

<script>
import * as echarts from 'echarts/core'
import { GridComponent, TooltipComponent } from 'echarts/components'
import { BarChart } from 'echarts/charts'
import { CanvasRenderer } from 'echarts/renderers'
import { PictorialBarChart } from 'echarts/charts'

echarts.use([GridComponent, BarChart, CanvasRenderer, TooltipComponent, PictorialBarChart])
// 计划产值
var zzx1 = [9838, 10143, 8580, 10443, 6176, 11722]
var zx = zzx1.map((item) => {
  return item
})
// 实际产值
var wgx1 = [12909, 13253, 8061, 8854, 8471, 8567]
var wg = wgx1.map((item) => {
  return item
})

var barWidth = 40
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
      // backgroundColor: '#010243',
      tooltip: {
        trigger: 'axis',
        axisPointer: {
          // 坐标轴指示器，坐标轴触发有效
          type: 'shadow' // 默认为直线，可选为：'line' | 'shadow'
        },
        formatter: function (e) {
          // console.log(e);
          var str =
            e[6].axisValue +
            '<br>' +
            "<span style='display:inline-block;margin-right:5px;border-radius:10px;width:10px;height:10px;background-color:" +
            e[6].color.colorStops[0].color +
            ";'></span>" +
            '' +
            e[6].seriesName +
            ' : ' +
            e[6].value +
            '<br>' +
            "<span style='display:inline-block;margin-right:5px;border-radius:10px;width:10px;height:10px;background-color:" +
            e[8].color.colorStops[0].color +
            ";'></span>" +
            '' +
            e[8].seriesName +
            ' : ' +
            e[8].value +
            ''
          return str
        }
      },
      grid: {
        left: '2%',
        right: '4%',
        bottom: '4%',
        top: '16%',
        containLabel: true
      },
      legend: {
        data: ['用电消耗', '气体消耗'],

        top: 1,
        textStyle: {
          color: '#00ffff',
          fontSize: 14
        },
        itemWidth: 12,
        itemHeight: 10,
        itemGap: 35,
        color: '#242424',
        selectedMode: false
      },
      xAxis: {
        type: 'category',
        data: ['2026-2-6', '2026-2-7', '2026-2-8', '2026-2-9', '2026-2-10'],
        axisLine: {
          show: true,
          lineStyle: {
            color: '#15205B'
          }
        },
        axisTick: {
          show: false
        },
        axisLabel: {
          // interval: 0,
          // rotate: 40,
          textStyle: {
            fontFamily: 'Microsoft YaHei',
            color: '#fff', // x轴颜色
            fontWeight: 'normal',
            fontSize: '14',
            lineHeight: 22
          },
          interval: 0, //标签设置为全部显示
          margin: 15,
          lineHeight: 15
          // fontSize: 11,
          // formatter: function (params) {
          //   var a = params.substring(0, params.length - 5)
          //   //   console.log(params.substring(0, params.length - 3));
          //   var newParamsName = a + '\n质量合格率'

          //   //将最终的字符串返回
          //   return newParamsName
          // }
        }
      },

      yAxis: {
        type: 'value',

        axisLine: {
          show: true,
          lineStyle: {
            color: '#15205B'
          }
        },
        splitLine: {
          show: false,
          lineStyle: {
            color: 'rgba(135,140,147,1)' //左侧显示线
          }
        },
        axisTick: {
          show: false
        },
        axisLabel: {
          formatter: '{value}',
          textStyle: {
            color: '#c9c9c9',
            fontSize: 14
          }
        }
      },
      series: [
        // 计划产值中间正方形
        {
          type: 'pictorialBar',
          symbol: 'diamond',
          symbolSize: [barWidth, 8],
          symbolOffset: [-26, -4],
          symbolPosition: 'end',
          z: 12,
          color: '#3185FF',
          data: zzx1
        },
        // 实际产值中间正方形
        {
          type: 'pictorialBar',
          symbol: 'diamond',
          symbolSize: [barWidth, 8],
          symbolOffset: [26, -4],
          symbolPosition: 'end',
          z: 12,
          color: '#1BCC98',
          data: wgx1
        },
        //  计划产值底部正方形
        {
          type: 'pictorialBar',
          symbol: 'diamond',
          symbolSize: [barWidth, 8],
          symbolOffset: [-26, 4],
          z: 12,
          color: '#161D6E',
          data: zzx1
        },
        // 实际产值底部正方形
        {
          name: '',
          type: 'pictorialBar',
          symbol: 'diamond',
          symbolSize: [barWidth, 8],
          symbolOffset: [26, 4],
          color: '#151D6E',
          z: 12,
          data: wgx1
        },
        // 计划产值上部正方形
        {
          data: [100, 100, 100, 100, 100],
          type: 'pictorialBar',

          symbol: 'diamond',
          symbolSize: [barWidth, 8],
          symbolOffset: [-26, -4],
          color: '#283190',

          symbolPosition: 'end'
        },
        // 实际产值上部正方形
        {
          data: [100, 100, 100, 100, 100],
          type: 'pictorialBar',

          symbol: 'diamond',
          symbolSize: [barWidth, 8],
          symbolOffset: [26, -4],
          color: '#283190',

          symbolPosition: 'end'
        },
        // 计划产值进度柱子
        {
          name: '用电消耗',
          type: 'bar',
          barWidth: barWidth,
          // zlevel: 2,
          stack: '1',
          itemStyle: {
            normal: {
              opacity: 0.7,
              color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                {
                  offset: 0,
                  color: '#2863D2'
                },
                {
                  offset: 1,
                  color: '#161D6E'
                }
              ]),
              barBorderRadius: 0
            }
          },
          label: {
            show: true,
            position: ['-18', '-18'],
            color: '#00f8ff',
            fontSize: 12
          },
          data: zzx1
        },
        // 计划产值底部柱子
        {
          data: zx,
          type: 'bar',

          barWidth: barWidth,
          stack: '1',

          zlevel: -1,
          itemStyle: {
            normal: {
              opacity: 0.7,
              color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                {
                  offset: 0,
                  color: '#182075'
                },
                {
                  offset: 1,
                  color: '#182075'
                }
              ]),
              barBorderRadius: 0
            }
          }
        },
        // 实际产值进度柱子
        {
          name: '气体消耗',
          type: 'bar',
          stack: '2',
          barWidth: barWidth,
          itemStyle: {
            normal: {
              opacity: 0.7,
              color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                {
                  offset: 0,
                  color: '#19958B'
                },
                {
                  offset: 1,
                  color: '#151B6F'
                }
              ]),
              barBorderRadius: 0
            }
          },
          label: {
            show: true,
            position: ['18', '-18'],
            color: '#00f8ff',
            fontSize: 12
          },
          data: wgx1
        },
        // 实际产值底部柱子
        {
          data: wg,
          type: 'bar',
          barWidth: barWidth,
          stack: '2',

          zlevel: -1,
          itemStyle: {
            normal: {
              opacity: 0.7,
              color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                {
                  offset: 0,
                  color: '#182075'
                },
                {
                  offset: 1,
                  color: '#182075'
                }
              ]),
              barBorderRadius: 0
            }
          }
        }
      ]
    })
  }
}
</script>

<style scoped>
.radar-echart {
  width: 100%;
  height: 350px;
}
</style>
