import { ref } from 'vue'
import { defineStore } from 'pinia'

export const useAnalysisStore = defineStore('analysis', () => {
  const deviceList = ref([])
  let deviceActionTime = new Date(0)
  const parameterList = ref([])
  let parameterActionTime = new Date(0)
  let eventSource = null

  function getDeviceList() {
    if (new Date() - deviceActionTime > 300000) {
      fetch('https://gaochidevice.xnssoft.com/analysis/api/getDeviceList').then(async (res) => {
        if (!res.ok) {
          return
        }
        deviceList.value = await res.json()
        deviceActionTime = new Date()
      })
    }
  }

  function getParameterList() {
    if (new Date() - parameterActionTime > 300000) {
      fetch('https://gaochidevice.xnssoft.com/analysis/api/getParameterList').then(async (res) => {
        if (!res.ok) {
          return
        }
        parameterList.value = await res.json()
        parameterActionTime = new Date()
      })
    }
  }

  const startSSE = () => {
    if (eventSource) {
      return
    }

    try {
      eventSource = new EventSource('https://gaochidevice.xnssoft.com/analysis/api/sse/stream')

      eventSource.onopen = () => {
        console.log('sse connected')
      }

      eventSource.onmessage = (event) => {
        let parsedMessage = JSON.parse(event.data)
        //console.log(parsedMessage)
        for (let parameter of parameterList.value) {
          if (parsedMessage.content.parameter_id == parameter.id) {
            parameter.value = parsedMessage.content.value
            parameter.flowRate = parsedMessage.content.flowRate
          }
        }
      }
    } catch (error) {
      console.log(error)
    }
  }

  return { deviceList, parameterList, getDeviceList, getParameterList, startSSE }
})
