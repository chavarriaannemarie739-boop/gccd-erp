<template>
  <div id="fullDiv" class="Dashboard">
    <!-- 头部的盒子 -->
    <header>
      <div class="headerssw">
        <!-- <h6>阳谷昊泉机械有限公司</h6> -->
        <h5>数据可视化大屏</h5>
        <div class="showTime">
          <!-- <timescomponent /> -->
        </div>
        <div class="showTimest">
          <!-- <Righttime /> -->
        </div>
        <!-- <div class="full-screen" @click="toFullOrExit">
          <img src="@/assets/quanping.png" />
        </div> -->
      </div>
    </header>
    <!-- 页面主体部分 -->
    <section class="mainbox">
      <div class="column">
        <!-- 生产任务管理 -->
        <div class="panel123">
          <Production v-if="schedul.length > 0" :schedul="schedul" />
          <div class="panel-footer"></div>
        </div>
        <div class="panelsda">
          <!-- 生产日期，数量 -->
          <div class="chart123">
            <Histogram />
            <div class="panel-footer"></div>
          </div>
        </div>
      </div>
      <div class="column">
        <!-- 地图模块 -->
        <!-- 月份生产数量 -->
        <div class="panelsdas">
          <div class="chart123s">
            <Mapping class="Mapping" />
            <div class="panel-footer"></div>
          </div>
        </div>
        <div class="map">
          <!-- 生产车间信息-->
          <div class="realtime">
            <Columnchart />
            <div class="panel-footer"></div>
          </div>
        </div>
      </div>
      <div class="column">
        <!-- 生产进度 -->
        <div class="panel123">
          <methods v-if="undelivered.length > 0" :undelivered="undelivered" />
          <div class="panel-footer"></div>
        </div>
        <div class="panelsda">
          <!-- 饼形图 -->
          <div class="chart123">
            <!-- <Piechart v-if="piechart.length > 0" :piechart="piechart" /> -->
            <Piechart />
            <div class="panel-footer"></div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import Columnchart from '@/components/goodsquery/Columnchart.vue'
import Mapping from '@/components/goodsquery/Mapping.vue'
import Production from '@/components/goodsquery/Production.vue'
// import timescomponent from '@/components/goodsquery/timecomponent.vue'
// import Righttime from '@/components/goodsquery/Righttime.vue'
import Histogram from '@/components/goodsquery/Histogram.vue'
import methods from '@/components/goodsquery/methods.vue'
import Piechart from '@/components/goodsquery/Piechart.vue'
import { list } from '@/api/mes/ProductionOrderList/ProductionOrderList'
export default {
  name: 'Index',
  components: {
    Columnchart,
    Mapping,
    Production,
    // timescomponent,
    Histogram,
    Piechart,
    methods
    // Righttime
  },
  data() {
    return {
      // 数采数据
      Factory: [],
      // 生产进度
      schedul: [],
      // 未派单条数
      undelivered: [],
      // 生产计划
      piechart: [],
      isGetData: false,
      // 生产日期，入库数量
      time: [],
      // 生产数量
      Production: [],
      query: {
        AdminToken: '',
        page: 1,
        limit: 50
      },
      queryParam: {
        AdminToken: '',
        page: 1,
        limit: 50
      }
    }
  },
  created() {
    // this.taskList()
    // // this.Delayer()
    this.list()
    // this.getDeviceData()
    // this.getProduceWorkSchedule()
    // this.getProduceDetailList()
    // this.getPendingProduce()
    // this.getStockData()
    // this.getProduceData()
  },
  unmounted() {
    if (this.timer) {
      clearInterval(this.timer)
    }
    if (this.goods) {
      clearInterval(this.goods)
    }
  },
  methods: {
    // async list() {
    //   this.loading = true
    //   const resp = await list(this.query)
    //   this.tableData = resp.data.list
    //   this.total = resp.data.count
    //   console.log(resp, 'resp')
    //   this.loading = false
    // },
    // 工序管理
    async list() {
      const resp = await list(this.query)
      if (resp.code === 200) {
        this.schedul = resp.data.list
        this.undelivered = resp.data.list
        this.timer = setTimeout(() => {
          this.list()
          clearInterval(this.timer)
        }, 9000 * 10)
      }
    },
    // 生产计划列表
    // async list() {
    //   this.getOneWeekAgo()
    //   const resp = await list(this.queryParam)
    //   for (let j = 0; j < resp.data.list.length; j++) {
    //     const goods = resp.data.list[j]
    //     for (let i = 0; i < goods.Lines.length; i++) {
    //       const element = goods.Lines[i]
    //       this.schedul.push({
    //         DocNo: goods.DocNo, // 对应项目
    //         OrderByCode: goods.OrderByCode, // 对应项目id
    //         BusinessDate: goods.BusinessDate, // 质检内容
    //         ItemName: element.ItemName, // 质检内容
    //         ItemSpecs: element.ItemSpecs, // 质检内容
    //         OrderByQtyTU: element.OrderByQtyTU, // 质检内容
    //         produce_status: goods.produce_status === 0 ? '未排产' : '已排产'
    //       })
    //     }
    //   }
    //   console.log(this.schedul, '12312')
    //   if (resp.code === 200) {
    //     this.goods = setTimeout(() => {
    //       this.list()
    //       clearInterval(this.goods)
    //     }, 9000 * 10)
    //   }
    // },
    requestFullScreen() {
      let de = document.documentElement
      if (de.requestFullscreen) {
        de.requestFullscreen()
      } else if (de.mozRequestFullScreen) {
        de.mozRequestFullScreen()
      } else if (de.webkitRequestFullScreen) {
        de.webkitRequestFullScreen()
      }
    },
    exitFullscreen() {
      let de = document
      if (de.exitFullscreen) {
        de.exitFullscreen()
      } else if (de.mozCancelFullScreen) {
        de.mozCancelFullScreen()
      } else if (de.webkitCancelFullScreen) {
        de.webkitCancelFullScreen()
      }
    },
    toFullOrExit() {
      this.isFull = !this.isFull
      if (this.isFull) {
        this.fullImg = require('@/assets/quanping.png')
        this.requestFullScreen()
      } else {
        this.fullImg = require('@/assets/suoxiao.png')
        this.exitFullscreen()
      }
    },
    // 获取一周前的时间（含时分秒）
    getOneWeekAgo() {
      const now = new Date()
      // 计算一周前的毫秒数（7天 * 24小时 * 3600秒 * 1000毫秒）
      const oneWeekBefore = new Date(now.getTime() - 2 * 24 * 60 * 60 * 1000)
      return this.formatDate(oneWeekBefore)
    },

    // 格式化日期为 YYYY-MM-DD HH:mm:ss
    formatDate(date) {
      const year = date.getFullYear()
      const month = String(date.getMonth() + 1).padStart(2, '0') // 月份从0开始
      const day = String(date.getDate()).padStart(2, '0')
      const hours = String(date.getHours()).padStart(2, '0')
      const minutes = String(date.getMinutes()).padStart(2, '0')
      const seconds = String(date.getSeconds()).padStart(2, '0')
      return `${year}-${month}-${day}`
    },
    // 数采数据
    // async  getDeviceData() {
    //   const resp = await getDeviceData()
    //   if (resp.code === 200) {
    //     this.Factory = resp.data
    //     console.log(resp)
    //     setTimeout(() => {
    //       this.list()
    //     }, 2000 * 10)
    //   }
    // },
    Delayer() {
      setInterval(() => {
        // 工序管理
        this.list()
      }, 9000 * 10)
      setInterval(() => {
        // 生产进度
        // this.list()
      }, 9000 * 10)
      // setInterval(() => {
      // // 生产进度
      //   this.getDeviceData()
      // }, 1000 * 10)
    }
  }
}
</script>

<style>
* {
  padding: 0;
  margin: 0;
}

li {
  list-style: none;
}
.Dashboard {
  min-width: 1920px;
  max-width: 1080px;
  height: 1080px;
  background: url(../../../assets/bg.jpg);
}
.headerssw {
  position: relative;
  height: 70px;
  background: url(../../../assets/head_bg.png) no-repeat;
  background-size: 100% 100%;
}
.headerssw h1 {
  font-size: 30px;
  color: #fff;
  text-align: center;
  line-height: 65px;
}
.headerssw .showTime {
  position: absolute;
  left: 0.375rem;
  top: 0;
  line-height: 0.9375rem;
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.25rem;
}
.headerssw .showTimest {
  position: absolute;
  right: 0.375rem;
  top: 0;
  line-height: 0.9375rem;
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.25rem;
}
h5 {
  font-size: 30px;
  color: #fff;
  text-align: center;
  line-height: 70px;
}
h6 {
  font-size: 26px;
  color: #fff;
  text-align: center;
}
.headerssw .full-screen {
  position: absolute;
  right: 6.7rem;
  top: 2rem;
  line-height: 0.9375rem;
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.25rem;
}
.mainbox {
  display: flex;
  /* min-width: 2160px;
  max-width: 3480px; */
  margin: 0 auto;
  padding: 10px 10px 0;
}

.mainbox .column {
  flex: 3;
}

.mainbox .column:nth-child(2) {
  flex: 5;
  margin: 0 10px 0;
  overflow: hidden;
}
/*  */
.panelsda .chart123 {
  position: relative;
  height: 400px;
  width: 620px;
  border: 1px solid rgba(25, 186, 139, 0.17);
  background: url(../../../assets/line.png) rgba(225, 225, 225, 0.03);
}

.panelsda .chart123::before {
  position: absolute;
  top: 0;
  left: 0;
  width: 10px;
  height: 10px;
  border-left: 2px solid #02a6b5;
  border-top: 2px solid #02a6b5;
  content: '';
}

.panelsda .chart123::after {
  position: absolute;
  top: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-top: 2px solid #02a6b5;
  content: '';
}

.panelsda .chart123 .panel-footer {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
}

.panelsda .chart123 .panel-footer::before {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  border-left: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
  content: '';
}

.panelsda .chart123 .panel-footer::after {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
  content: '';
}
/*  */
.panelsdas .chart123s {
  position: relative;
  height: 390px;
  width: 640px;
  border: 2px solid rgba(25, 186, 139, 0.17);
  margin-bottom: 15px;
  background: url(../../../assets/line.png) rgba(225, 225, 225, 0.03);
}

.panelsdas .chart123s::before {
  position: absolute;
  top: 0;
  left: 0;
  width: 10px;
  height: 10px;
  border-left: 2px solid #02a6b5;
  border-top: 2px solid #02a6b5;
  content: '';
}

.panelsdas .chart123s::after {
  position: absolute;
  top: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-top: 2px solid #02a6b5;
  content: '';
}

.panelsdas .chart123s .panel-footer {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
}

.panelsdas .chart123s .panel-footer::before {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  border-left: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
  content: '';
}

.panelsdas .chart123s .panel-footer::after {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
  content: '';
}
/*  */
.mainbox .panel123 {
  position: relative;
  height: 58%;
  width: 620px;
  border: 1px solid rgba(25, 186, 139, 0.17);
  margin-bottom: 15px;
  background: url(../../../assets/line.png) rgba(225, 225, 225, 0.03);
}
.mainbox .panel123::before {
  position: absolute;
  top: 0;
  left: 0;
  width: 10px;
  height: 10px;
  border-left: 2px solid #02a6b5;
  border-top: 2px solid #02a6b5;
  content: '';
}

.mainbox .panel123::after {
  position: absolute;
  top: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-top: 2px solid #02a6b5;
  content: '';
}

.mainbox .panel123 .panel-footer {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
}

.mainbox .panel123 .panel-footer::before {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  border-left: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
  content: '';
}

.mainbox .panel123 .panel-footer::after {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
  content: '';
}

.mainbox .panel h2 {
  height: 30px;
  color: #fff;
  line-height: 30px;
  text-align: center;
  font-size: 18px;
  font-weight: 400;
}

.mainbox .panel h2 a {
  color: #fff;
  text-decoration: none;
  margin: 0 10px;
}

.mainbox .panel .chart {
  height: 24px;
  color: #fff;
}

.mainbox .panel .line2 {
  height: 24px;
  color: #02a6b5;
}

.no {
  background: rgba(101, 132, 226, 0.1);
  padding: 15px;
}

.no .no-hd {
  position: relative;
  border: 1px solid rgba(25, 186, 226, 0.17);
}

.no .no-hd::before {
  position: absolute;
  top: 0;
  left: 0;
  content: '';
  width: 30px;
  height: 10px;
  border-top: 2px solid #02a6b5;
  border-left: 2px solid #02a6b5;
}

.no .no-hd::after {
  position: absolute;
  bottom: 0;
  right: 0;
  content: '';
  width: 30px;
  height: 10px;
  border-right: 2px solid #02a6b5;
  border-bottom: 2px solid #02a6b5;
}

.no .no-hd ul {
  display: flex;
}

.no .no-hd ul li {
  position: relative;
  flex: 1;
  line-height: 80px;
  font-size: 50px;
  color: #ffeb7b;
  text-align: center;
  font-family: serif;
}

.no .no-hd ul li::after {
  content: '';
  position: absolute;
  top: 25%;
  right: 0;
  height: 50%;
  width: 1px;
  background: rgba(19, 14, 14, 0.2);
}

.no .no-bd ul {
  display: flex;
}

.no .no-bd ul li {
  flex: 1;
  text-align: center;
  color: rgba(225, 225, 225, 0.7);
  font-size: 18px;
  height: 40px;
  line-height: 40px;
  padding-top: 10px;
}

.map {
  position: relative;
  height: 100%;
}
.map .Mapping {
  width: 100%;
  height: 46%;
  margin-bottom: 20px;
  background: rgba(101, 132, 226, 0.1);
}
.map .realtime .Columnchart {
  width: 100%;
  height: 90%;
}

.map .realtime {
  position: relative;
  height: 58.3%;
  width: 100%;
  border: 1px solid rgba(25, 186, 139, 0.17);
  background: url(../../../assets/line.png) rgba(225, 225, 225, 0.03);
}

.map .realtime::before {
  position: absolute;
  top: 0;
  left: 0;
  width: 10px;
  height: 10px;
  border-left: 3px solid #02a6b5;
  border-top: 3px solid #02a6b5;
  content: '';
}

.map .realtime::after {
  position: absolute;
  top: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 3px solid #02a6b5;
  border-top: 3px solid #02a6b5;
  content: '';
}

.map .realtime .panel-footer {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
}

.map .realtime .panel-footer::before {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  border-left: 3px solid #02a6b5;
  border-bottom: 3px solid #02a6b5;
  content: '';
}

.map .realtime .panel-footer::after {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 10px;
  height: 10px;
  border-right: 3px solid #02a6b5;
  border-bottom: 3px solid #02a6b5;
  content: '';
}

/* html {
    overflow-y: hidden;
    overflow-x: hidden;
    // overflow: hidden;
} */
</style>
