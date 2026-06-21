<template>
  <div>
    <div style="color: aliceblue; margin-top: 5px; font-weight: 700; font-size: 17px">
      【设备状态】
    </div>
    <div class="wrap clearfix">
      <div class="item">
        <div class="item1">
          <span>设备总数： <span class="quantity">31</span></span>
        </div>
      </div>
      <div class="item">
        <div class="item1">
          <span>正常数量： <span class="quantity">28</span></span>
        </div>
      </div>
      <div class="item">
        <div class="item1">
          <span>工作数量： <span class="quantity">25</span></span>
        </div>
      </div>
      <div class="item">
        <div class="item1">
          <span>停止数量: <span class="quantity">5</span></span>
        </div>
      </div>
    </div>
    <div class="itemss" style="display: flex">
      <div class="item1ss">
        <dashboard />
      </div>
    </div>
  </div>
</template>

<script>
import dashboard from './dashboard.vue'
export default {
  name: 'Columnchart',
  components: {
    dashboard
  },
  props: {
    // Factory: {
    //   type: Array,
    //   isGetData: false
    // }
  },
  data() {
    return {}
  }
}
</script>
<style scoped>
.wrap {
  display: flex;
  width: 100%;
  height: 100%;
  /* 先两边贴边， 在分配剩余的空间 */
  justify-content: space-around;
  flex-wrap: wrap;
}
.item {
  display: flex;
  line-height: 97px;
  flex-direction: column;
  justify-content: space-between;
  width: 305px;
  height: 97px;
  margin: 5px;
  border: 1px solid rgb(4, 114, 131);
}
.item .item1 {
  /* width: 90%; */
  /* height: 50%; */
  /* color:white; */
  /* color: aqua; */
  /* text-align: center; */
  margin-left: 30px;
}
.item .item1 span {
  color: white;
  /* color: aqua; */
  font-size: 25px;
  text-align: center;
  line-height: 50px;
}
.item .item1 img {
  width: 35%;
  height: 90%;
  vertical-align: middle;
}
.item .quantity {
  text-align: center;
  line-height: 50px;
  font-size: 50px;
  color: aqua;
  line-height: 97px;
  font-family: electronicFont;
}
</style>
