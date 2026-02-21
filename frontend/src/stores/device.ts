import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/api/request'

export interface Device {
  id: number
  name: string
  type: string
  device_key: string
  location: string
  status: string
  last_seen_at: string | null
  created_at: string
  latestTelemetry?: Record<string, any>
}

export const useDeviceStore = defineStore('device', () => {
  const devices = ref<Device[]>([])
  const loading = ref(false)

  async function fetchDevices(params?: Record<string, any>) {
    loading.value = true
    try {
      const res = await request.get<any, any>('/devices', { params })
      devices.value = res.data?.data || res.data || []
    } finally {
      loading.value = false
    }
  }

  function updateDeviceTelemetry(deviceId: number, data: any) {
    const device = devices.value.find((d) => d.id === deviceId)
    if (device) {
      device.latestTelemetry = { ...device.latestTelemetry, ...data }
    }
  }

  function updateDeviceStatus(deviceId: number, status: string) {
    const device = devices.value.find((d) => d.id === deviceId)
    if (device) {
      device.status = status
    }
  }

  return {
    devices,
    loading,
    fetchDevices,
    updateDeviceTelemetry,
    updateDeviceStatus,
  }
})
