<script setup>
import { useAnalysisStore } from './analysis'
import { ref, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'

const analysisStore = useAnalysisStore()
const { deviceList, parameterList } = storeToRefs(analysisStore)

const addParameterToDevice = (device, parameter) => {
  if (!device.parameterList) {
    device.parameterList = []
  }
  let isHave = false
  for (let parameterItem of device.parameterList) {
    if (parameter.id == parameterItem.id) {
      isHave = true
      return
    }
  }
  device.parameterList.push(parameter)
}

const check = () => {
  if (deviceList.value.length && parameterList.value.length) {
    console.log(deviceList.value)
    console.log(parameterList.value)
    for (let device of deviceList.value) {
      for (let parameter of parameterList.value) {
        if (device.id == parameter.device_id) {
          addParameterToDevice(device, parameter)
        }
      }
    }
  }
}

onMounted(() => {
  analysisStore.getDeviceList()
  analysisStore.getParameterList()
  analysisStore.startSSE()
  check()
})

watch(
  [deviceList, parameterList],
  ([newDeviceList, newParameterList], [oldDeviceList, oldParameterList]) => {
    check()
  }
)
</script>

<template>
  <div class="device-container">
    <div v-for="device in deviceList" class="device-card">
      <h3>{{ device.name }}</h3>
      <div class="parameter-list">
        <div v-for="parameter in device.parameterList" class="parameter-item">
          <span class="value">{{ parameter.value }}mA</span>
          <span class="flow-rate">{{ parameter.flowRate }}m³/h</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.device-container {
  display: flex; /* 水平排列设备卡片 */
  gap: 20px; /* 卡片之间的间距 */
  padding: 20px;
  justify-content: space-around;
  flex-wrap: wrap; /* 允许换行，如果内容超出屏幕宽度 */
}

.device-card {
  /* border: 1px solid #ccc; */
  border-radius: 8px;
  padding: 15px;
  min-width: 280px; /* 设置最小宽度 */
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  background-color: #f9f9f9;
}

.parameter-list {
  margin-top: 10px;
}

.parameter-item {
  margin-bottom: 10px;
  padding: 8px;
  background-color: white;
  border-radius: 4px;
  border-left: 3px solid #007bff; /* 可选：添加一个颜色边框 */
}

.parameter-item:last-child {
  margin-bottom: 0; /* 移除最后一个项目的下边距 */
}

.value,
.flow-rate {
  display: block; /* 使它们垂直堆叠 */
  font-size: 14px;
  line-height: 1.4;
}

.value {
  color: #666;
  font-size: 12px;
}

.flow-rate {
  color: #333;
  font-weight: bold;
}
</style>
