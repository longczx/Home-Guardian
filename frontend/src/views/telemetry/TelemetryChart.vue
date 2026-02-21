<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { NCard, NSelect, NSpace, NSpin, NEmpty, NSwitch, NTag, useMessage } from 'naive-ui'
import VChart from 'vue-echarts'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  ToolboxComponent,
} from 'echarts/components'
import TimeRangePicker from '@/components/TimeRangePicker.vue'
import { useAppStore } from '@/stores/app'
import { getDevices } from '@/api/device'
import { getTelemetryAggregated, getTelemetry } from '@/api/telemetry'

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, ToolboxComponent])

const message = useMessage()
const appStore = useAppStore()

const deviceOptions = ref<Array<{ label: string; value: number }>>([])
const selectedDevices = ref<number[]>([])
const metricInput = ref('temperature')
const loading = ref(false)
const chartData = ref<any[]>([])
const useAggregated = ref(true)
const timeRange = ref({ start: '', end: '' })

const chartOption = computed(() => {
  const isDark = appStore.isDark
  const series: any[] = []
  const legend: string[] = []

  const groupedByDevice: Record<number, any[]> = {}
  chartData.value.forEach((item) => {
    const devId = item.device_id
    if (!groupedByDevice[devId]) groupedByDevice[devId] = []
    groupedByDevice[devId].push(item)
  })

  const deviceMap = Object.fromEntries(deviceOptions.value.map((d) => [d.value, d.label]))

  Object.entries(groupedByDevice).forEach(([devId, items]) => {
    const name = deviceMap[Number(devId)] || `设备 ${devId}`
    legend.push(name)

    const sortedItems = items.sort((a, b) => {
      const timeA = a.hour || a.recorded_at || a.timestamp || ''
      const timeB = b.hour || b.recorded_at || b.timestamp || ''
      return timeA.localeCompare(timeB)
    })

    series.push({
      name,
      type: 'line',
      smooth: true,
      showSymbol: false,
      data: sortedItems.map((item) => [
        item.hour || item.recorded_at || item.timestamp,
        useAggregated.value ? Number(item.avg_value ?? item.value) : Number(item.value),
      ]),
    })

    if (useAggregated.value && sortedItems[0]?.min_value != null) {
      series.push({
        name: `${name} (范围)`,
        type: 'line',
        smooth: true,
        showSymbol: false,
        lineStyle: { opacity: 0 },
        areaStyle: { opacity: 0.1 },
        data: sortedItems.map((item) => [
          item.hour,
          [Number(item.min_value), Number(item.max_value)],
        ]),
        tooltip: { show: false },
      })
    }
  })

  return {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'axis',
      backgroundColor: isDark ? 'rgba(36,36,40,0.95)' : '#fff',
      borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
      textStyle: { color: isDark ? '#fff' : '#333' },
    },
    legend: {
      data: legend,
      textStyle: { color: isDark ? 'rgba(255,255,255,0.65)' : 'rgba(0,0,0,0.65)' },
    },
    grid: { left: 50, right: 30, top: 50, bottom: 60 },
    xAxis: {
      type: 'time',
      axisLabel: { color: isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)' },
      axisLine: { lineStyle: { color: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' } },
    },
    yAxis: {
      type: 'value',
      axisLabel: { color: isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)' },
      splitLine: { lineStyle: { color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)' } },
    },
    dataZoom: [{ type: 'inside' }, { type: 'slider', height: 20, bottom: 10 }],
    series,
  }
})

async function loadDevices() {
  try {
    const res = await getDevices({ per_page: 200 })
    const list = res.data?.data || res.data || []
    deviceOptions.value = list.map((d: any) => ({ label: d.name, value: d.id }))
    if (list.length > 0 && selectedDevices.value.length === 0) {
      selectedDevices.value = [list[0].id]
    }
  } catch {}
}

async function loadChart() {
  if (selectedDevices.value.length === 0 || !timeRange.value.start) return
  loading.value = true
  try {
    const params: Record<string, any> = {
      device_id: selectedDevices.value.join(','),
      metric: metricInput.value,
      start_time: timeRange.value.start,
      end_time: timeRange.value.end,
    }
    if (useAggregated.value) {
      const res = await getTelemetryAggregated(params)
      chartData.value = res.data || []
    } else {
      params.per_page = 500
      const res = await getTelemetry(params)
      chartData.value = res.data?.data || res.data || []
    }
  } catch {
    message.error('加载数据失败')
  } finally {
    loading.value = false
  }
}

function handleTimeChange(range: { start: string; end: string }) {
  timeRange.value = range
  loadChart()
}

watch([selectedDevices, metricInput, useAggregated], () => {
  loadChart()
})

onMounted(loadDevices)
</script>

<template>
  <div>
    <h2
      class="text-lg font-semibold mb-4"
      :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
    >
      数据图表
    </h2>

    <NCard
      :style="{
        backgroundColor: appStore.cardBg,
        backdropFilter: 'blur(12px)',
        borderRadius: '12px',
        border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
      }"
    >
      <NSpace class="mb-4" align="center" :wrap="true">
        <NSelect
          v-model:value="selectedDevices"
          :options="deviceOptions"
          multiple
          placeholder="选择设备"
          style="min-width: 250px"
          max-tag-count="responsive"
        />
        <NSelect
          v-model:value="metricInput"
          :options="[
            { label: 'temperature', value: 'temperature' },
            { label: 'humidity', value: 'humidity' },
            { label: 'pressure', value: 'pressure' },
            { label: 'battery', value: 'battery' },
          ]"
          tag
          filterable
          placeholder="输入指标名"
          style="width: 180px"
        />
        <NSpace align="center" :size="4">
          <span :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.65)' : 'rgba(0,0,0,0.65)', fontSize: '13px' }">
            聚合
          </span>
          <NSwitch v-model:value="useAggregated" size="small" />
        </NSpace>
        <TimeRangePicker @change="handleTimeChange" />
      </NSpace>

      <NSpin :show="loading">
        <VChart
          v-if="chartData.length > 0"
          :option="chartOption"
          autoresize
          style="height: 450px"
        />
        <NEmpty v-else-if="!loading" description="暂无数据，请选择设备和时间范围" class="py-16" />
      </NSpin>
    </NCard>
  </div>
</template>
