<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { NGrid, NGi, NCard, NDataTable, NButton, NIcon, NSpin, NEmpty, useMessage } from 'naive-ui'
import { RefreshOutline, CheckmarkCircleOutline } from '@vicons/ionicons5'
import DeviceCard from '@/components/DeviceCard.vue'
import MetricGauge from '@/components/MetricGauge.vue'
import { useDeviceStore } from '@/stores/device'
import { useAppStore } from '@/stores/app'
import { useWebSocket } from '@/composables/useWebSocket'
import request from '@/api/request'

const message = useMessage()
const deviceStore = useDeviceStore()
const appStore = useAppStore()
const { connected, connect } = useWebSocket()

const loading = ref(true)
const recentAlerts = ref<any[]>([])
const stats = ref({
  totalDevices: 0,
  onlineDevices: 0,
  activeAlerts: 0,
  todayDataPoints: 0,
})

const onlineRate = computed(() => {
  if (stats.value.totalDevices === 0) return '0'
  return ((stats.value.onlineDevices / stats.value.totalDevices) * 100).toFixed(0)
})

async function loadData() {
  loading.value = true
  try {
    const [devicesRes, alertsRes] = await Promise.all([
      request.get<any, any>('/devices', { params: { per_page: 100 } }),
      request.get<any, any>('/alert-logs', { params: { per_page: 5, status: 'triggered' } }),
    ])

    const deviceList = devicesRes.data?.data || devicesRes.data || []
    deviceStore.devices = deviceList

    stats.value.totalDevices = deviceList.length
    stats.value.onlineDevices = deviceList.filter((d: any) => d.status === 'online').length

    const alertData = alertsRes.data?.data || alertsRes.data || []
    recentAlerts.value = alertData
    stats.value.activeAlerts = alertsRes.data?.total || alertData.length

    // Fetch latest telemetry for each online device
    const onlineDevices = deviceList.filter((d: any) => d.status === 'online')
    if (onlineDevices.length > 0) {
      try {
        const telRes = await request.get<any, any>('/telemetry/latest', {
          params: { device_id: onlineDevices.map((d: any) => d.id).join(',') },
        })
        const telData = telRes.data || []
        if (Array.isArray(telData)) {
          telData.forEach((t: any) => {
            deviceStore.updateDeviceTelemetry(t.device_id, t)
          })
        }
      } catch {}
    }
  } catch (err: any) {
    message.error('加载数据失败')
  } finally {
    loading.value = false
  }
}

async function acknowledgeAlert(id: number) {
  try {
    await request.patch(`/alert-logs/${id}/acknowledge`)
    message.success('已确认告警')
    loadData()
  } catch {
    message.error('操作失败')
  }
}

const alertColumns = [
  { title: '时间', key: 'triggered_at', width: 170, render: (row: any) => row.triggered_at?.replace('T', ' ').slice(0, 19) || '-' },
  { title: '规则', key: 'rule_name', ellipsis: true },
  { title: '设备', key: 'device_name', ellipsis: true },
  { title: '消息', key: 'message', ellipsis: true },
  {
    title: '操作',
    key: 'action',
    width: 80,
    render: (row: any) => {
      if (row.status === 'triggered') {
        return h(NButton, {
          size: 'tiny',
          type: 'primary',
          quaternary: true,
          onClick: () => acknowledgeAlert(row.id),
        }, { default: () => '确认' })
      }
      return row.status
    },
  },
]

import { h } from 'vue'

onMounted(() => {
  loadData()
  connect()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        概览
      </h2>
      <NButton quaternary circle @click="loadData" :loading="loading">
        <template #icon>
          <NIcon><RefreshOutline /></NIcon>
        </template>
      </NButton>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <MetricGauge label="设备总数" :value="stats.totalDevices" />
      <MetricGauge label="在线设备" :value="stats.onlineDevices" color="#18a058" />
      <MetricGauge label="活跃告警" :value="stats.activeAlerts" color="#d03050" />
      <MetricGauge label="在线率" :value="onlineRate" unit="%" color="#2080f0" />
    </div>

    <!-- Device Grid -->
    <h3
      class="text-base font-medium mb-3"
      :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.82)' : 'rgba(0,0,0,0.82)' }"
    >
      设备状态
    </h3>
    <NSpin :show="loading && deviceStore.devices.length === 0">
      <div
        v-if="deviceStore.devices.length > 0"
        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6"
      >
        <DeviceCard
          v-for="device in deviceStore.devices"
          :key="device.id"
          :device="device"
        />
      </div>
      <NEmpty v-else-if="!loading" description="暂无设备" class="py-12" />
    </NSpin>

    <!-- Recent Alerts -->
    <h3
      class="text-base font-medium mb-3"
      :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.82)' : 'rgba(0,0,0,0.82)' }"
    >
      最近告警
    </h3>
    <NCard
      :style="{
        backgroundColor: appStore.cardBg,
        backdropFilter: 'blur(12px)',
        borderRadius: '12px',
        border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
      }"
    >
      <NDataTable
        :columns="alertColumns"
        :data="recentAlerts"
        :bordered="false"
        size="small"
        :pagination="false"
      />
    </NCard>
  </div>
</template>
