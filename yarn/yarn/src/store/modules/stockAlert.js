import { defineStore } from 'pinia'
import { stockAlertSummary } from '@/api/mes/WarehouseManagement/WarehouseManagement'

export const useStockAlertStore = defineStore('stockAlert', {
  state: () => ({
    accessoryCount: 0,
    roundSteelCount: 0,
    totalCount: 0,
    loaded: false
  }),
  actions: {
    async fetchSummary() {
      try {
        const resp = await stockAlertSummary({})
        const data = resp.data || {}
        this.accessoryCount = data.accessory_count || 0
        this.roundSteelCount = data.round_steel_count || 0
        this.totalCount = data.total_count || 0
        this.loaded = true
      } catch (e) {
        this.loaded = false
      }
    }
  }
})
