<template>
  <div class="productProcess">
    <div style="color: aliceblue; margin-top: 10px; font-weight: 700; font-size: 17px">
      【计划列表】
    </div>
    <div class="table_body">
      <div class="table_th">
        <div class="tr2 th_style">订单编号</div>
        <div class="tr3 th_style">计划名称</div>
        <div class="tr4 th_style">计划数量</div>
        <div class="tr5 th_style">计划状态</div>
      </div>
    </div>
    <!-- <vueSeamlessScroll :list="listData" :class-option="classOption" class="scroll"> -->
    <div class="table_main_body">
      <ul class="table_inner_body">
        <li v-for="item in listData" :key="item.id" class="table_tr">
          <span class="trs1">{{ item.title }}</span>
          <span class="trs2">{{ item.date }}</span>
          <span class="trs4">{{ item.date2 }}</span>
          <span class="trs5">{{ item.date3 }}</span>
        </li>
      </ul>
    </div>
    <!-- </vueSeamlessScroll> -->
  </div>
</template>

<script>
// import vueSeamlessScroll from 'vue-seamless-scroll'
export default {
  components: {
    // vueSeamlessScroll
  },
  props: {
    schedul: {
      type: Array,
      isGetData: false
    }
  },
  data() {
    return {
      listData: [
        {
          id: 1,
          title: 'scjh-202610210002',
          date: 'SG计划2',
          date2: '34',
          date3: '生产中'
        },
        {
          id: 2,
          title: 'scjh-202610210006',
          date: 'SG计划1',
          date2: '32',
          date3: '生产中'
        },
        {
          id: 3,
          title: 'scjh-202610210005',
          date: 'YH计划',
          date2: '35',
          date3: '生产中'
        },
        {
          id: 4,
          title: 'scjh-202610210002',
          date: 'KY计划2',
          date2: '34',
          date3: '生产中'
        },
        {
          id: 5,
          title: 'scjh-202610210001',
          date: 'SY计划1',
          date2: '33',
          date3: '未开始'
        },
        {
          id: 6,
          title: 'scjh-202610210001',
          date: 'SY计划1',
          date2: '34',
          date3: '生产中'
        },
        {
          id: 7,
          title: 'scjh-202610210003',
          date: 'SY计划2',
          date2: '32',
          date3: '未开始'
        }
      ],
      classOption: {
        singleHeight: 49,
        waitTime: 2000
      }
    }
  }
}
</script>
<style>
.scroll {
  height: 490px;
  overflow: hidden;
}

.scroll .item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 3px 0;
}
</style>
<style scoped>
.seamless-warp {
  height: 490px;
  overflow: hidden;
}
.table_body {
  width: 100%;
}
.table_th {
  width: 100%;
  display: flex;
  height: 40px;
  line-height: 40px;
}
.tr {
  text-overflow: ellipsis;
  white-space: nowrap;
  box-sizing: border-box;
  text-align: center;
  font-size: 12px;
}
.tr1 {
  width: 57%;
}
.tr2 {
  width: 26%;
}
.tr3 {
  width: 24%;
}
.tr4 {
  width: 20%;
}
.tr5 {
  width: 25%;
}
.tr6 {
  width: 25%;
}
.tr7 {
  width: 15%;
}
.tr8 {
  width: 15%;
}

.th_style {
  color: rgb(0, 221, 253);
  font-weight: bold;
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  box-sizing: border-box;
  padding: 0 5px;
  text-align: center;
}
.table_tr {
  display: flex;
  height: 40px;
  line-height: 40px;
  color: #eee;
  /* font-size: 15px; */
  background: rgba(3, 145, 167, 0.1);
  border: 1px solid rgb(4, 114, 131);
  margin-top: 7px;
  /* margin: 7px; */
}

.table_tr .trs1 {
  width: 140px;
  margin-left: 30px;
  white-space: nowrap;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs2 {
  width: 140px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs3 {
  width: 110px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs4 {
  width: 117px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs5 {
  width: 165px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs6 {
  width: 90px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs7 {
  width: 99px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
.table_tr .trs8 {
  width: 63px;
  white-space: nowrap;
  text-align: center;
  font-size: 12px;
  overflow: hidden; /*超出的文本隐藏*/
  text-overflow: ellipsis; /* 溢出用省略号*/
}
</style>
