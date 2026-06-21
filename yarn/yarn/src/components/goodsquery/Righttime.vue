<template>
  <div class="timeSpan">
    {{ nowTime }}
  </div>
</template>
<script>
import dayjs from 'dayjs'
export default {
  name: 'Timescomponent',
  data() {
    return {
      nowTime: null // 当前时间
    }
  },
  mounted() {
    this.$nextTick(() => { // 动态时间展示
      setInterval(this.showTime, 1000)
    })
  },
  methods: {
    showTime() { // 动态展示现在时间
      var date = new Date()
      var year = date.getFullYear()
      var month = date.getMonth() + 1
      month = month < 10 ? '0' + month : month
      var day = date.getDate()
      day = day < 10 ? '0' + day : day
      var week = '日一二三四五六'.charAt(date.getDay()) // 使用charAt函数提取相应汉字
      var hour = date.getHours()
      hour = hour < 10 ? '0' + hour : hour // 用三目运算符调整数字显示格式
      var minute = date.getMinutes()
      minute = minute < 10 ? '0' + minute : minute
      var second = date.getSeconds()
      second = second < 10 ? '0' + second : second
      // 加载现在时间
      var current = hour + ':' + minute + ':' + second
      this.nowTime = current
    }
  }
}
</script>
    <style  scoped>
    .timeSpan{
      font-size: 30px;
      text-align: end;
      font-weight: bold;
      color: #fff;
      margin-right: 170px;
      margin-top: 40px;
    }
    </style>

s