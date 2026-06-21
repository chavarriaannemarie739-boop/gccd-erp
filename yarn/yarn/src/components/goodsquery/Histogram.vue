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
      【不良品/废品】
    </div>
    <div ref="myDiv" class="radar-echart"></div>
  </div>
</template>

<script>
import * as echarts from 'echarts/core'
import { GridComponent } from 'echarts/components'
import { LineChart } from 'echarts/charts'
import { UniversalTransition } from 'echarts/features'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([GridComponent, LineChart, CanvasRenderer, UniversalTransition])
var charts = {
  // unit: '户数',
  names: ['不良品', '废品'],
  lineX: ['2026-2-6', '2026-2-7', '2026-2-8', '2026-2-9', '2026-2-10'],
  value: [
    [9, 7, 3, 8, 7],
    [10, 8, 5, 5, 8]
  ]
}
var color = ['rgba(23, 255, 243', 'rgba(119,61,190']
var lineY = []

for (var i = 0; i < charts.names.length; i++) {
  var x = i
  if (x > color.length - 1) {
    x = color.length - 1
  }
  var data = {
    name: charts.names[i],
    type: 'line',
    color: color[x] + ')',
    smooth: true,
    areaStyle: {
      normal: {
        color: new echarts.graphic.LinearGradient(
          0,
          0,
          0,
          1,
          [
            {
              offset: 0,
              color: color[x] + ', 0.3)'
            },
            {
              offset: 0.8,
              color: color[x] + ', 0)'
            }
          ],
          false
        ),
        shadowColor: 'rgba(0, 0, 0, 0.1)',
        shadowBlur: 10
      }
    },
    symbol: 'circle',
    symbolSize: 5,
    data: charts.value[i]
  }
  lineY.push(data)
}

lineY[0].markLine = {
  silent: true,
  data: [
    {
      yAxis: 5
    },
    {
      yAxis: 100
    },
    {
      yAxis: 200
    },
    {
      yAxis: 300
    },
    {
      yAxis: 400
    }
  ]
}
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
      // backgroundColor: '#0d235e',
      tooltip: {
        trigger: 'axis'
      },
      legend: {
        data: charts.names,
        textStyle: {
          fontSize: 12,
          color: 'rgb(0,253,255,0.6)'
        },
        right: '4%'
      },
      grid: {
        top: '14%',
        left: '4%',
        right: '10%',
        bottom: '12%',
        containLabel: true
      },
      xAxis: {
        type: 'category',
        boundaryGap: false,
        data: charts.lineX,
        axisLabel: {
          textStyle: {
            color: 'rgb(0,253,255,0.6)'
          },
          formatter: function (params) {
            return params.split(' ')[0]
          }
        }
      },
      yAxis: {
        name: charts.unit,
        type: 'value',
        axisLabel: {
          formatter: '{value}',
          textStyle: {
            color: 'rgb(0,253,255,0.6)'
          }
        },
        splitLine: {
          lineStyle: {
            color: 'rgb(23,255,243,0.3)'
          }
        },
        axisLine: {
          lineStyle: {
            color: 'rgb(0,253,255,0.6)'
          }
        }
      },
      series: lineY
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
