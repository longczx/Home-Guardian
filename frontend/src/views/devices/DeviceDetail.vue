<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NCard, NDescriptions, NDescriptionsItem, NButton, NIcon, NSpace,
  NDataTable, NTag, NInput, NModal, NForm, NFormItem,
  useMessage, NSpin, NEmpty, NDivider,
} from 'naive-ui'
import { ArrowBackOutline, SendOutline, RefreshOutline } from '@vicons/ionicons5'
import StatusBadge from '@/components/StatusBadge.vue'
import TimeRangePicker from '@/components/TimeRangePicker.vue'
import { useAppStore } from '@/stores/app'
import { getDevice, getDeviceAttributes, sendCommand, getCommandLogs } from '@/api/device'
import request from '@/api/request'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const appStore = useAppStore()

const deviceId = Number(route.params.id)
const device = ref<any>(null)
const attributes = ref<any[]>([])
const telemetryData = ref<any[]>([])
const commandLogs = ref<any[]>([])
const loading = ref(true)

const showCommandModal = ref(false)
const commandForm = ref({ command: '', params: '{}' })

async function loadDevice() {
  loading.value = true
  try {
    const [devRes, attrRes, cmdRes] = await Promise.all([
      getDevice(deviceId),
      getDeviceAttributes(deviceId),
      getCommandLogs({ device_id: deviceId, per_page: 10 }),
    ])
    device.value = devRes.data
    attributes.value = attrRes.data || []
    commandLogs.value = cmdRes.data?.data || cmdRes.data || []
  } catch {
    message.error('加载设备信息失败')
  } finally {
    loading.value = false
  }
}

async function loadTelemetry(range: { start: string; end: string }) {
  try {
    const res = await request.get<any, any>('/telemetry', {
      params: { device_id: deviceId, start_time: range.start, end_time: range.end, per_page: 100 },
    })
    telemetryData.value = res.data?.data || res.data || []
  } catch {}
}

async function handleSendCommand() {
  try {
    let params = {}
    try {
      params = JSON.parse(commandForm.value.params)
    } catch {}
    await sendCommand(deviceId, { command: commandForm.value.command, params })
    message.success('指令已发送')
    showCommandModal.value = false
    commandForm.value = { command: '', params: '{}' }
    loadDevice()
  } catch (err: any) {
    message.error(err.response?.data?.message || '发送失败')
  }
}

const telemetryColumns = [
  { title: '时间', key: 'recorded_at', width: 170, render: (row: any) => (row.recorded_at || row.timestamp)?.replace('T', ' ').slice(0, 19) || '-' },
  { title: '指标', key: 'metric', width: 120 },
  { title: '值', key: 'value', width: 120 },
  { title: '单位', key: 'unit', width: 80 },
]

const cmdColumns = [
  { title: '时间', key: 'created_at', width: 170, render: (row: any) => row.created_at?.replace('T', ' ').slice(0, 19) || '-' },
  { title: '指令', key: 'command', width: 150 },
  { title: '参数', key: 'params', ellipsis: true, render: (row: any) => JSON.stringify(row.params || {}) },
  { title: '状态', key: 'status', width: 100, render: (row: any) => {
    const map: Record<string, 'success' | 'error' | 'warning' | 'info'> = {
      success: 'success', failed: 'error', pending: 'warning', sent: 'info',
    }
    return h(NTag, { size: 'small', type: map[row.status] || 'default', bordered: false }, () => row.status)
  }},
]

import { h } from 'vue'

onMounted(loadDevice)
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-4">
      <NButton quaternary circle @click="router.back()">
        <template #icon><NIcon><ArrowBackOutline /></NIcon></template>
      </NButton>
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        {{ device?.name || '设备详情' }}
      </h2>
      <StatusBadge v-if="device" :status="device.status" />
    </div>

    <NSpin :show="loading">
      <div v-if="device" class="space-y-4">
        <!-- Basic Info -->
        <NCard
          title="基本信息"
          :style="{
            backgroundColor: appStore.cardBg,
            backdropFilter: 'blur(12px)',
            borderRadius: '12px',
            border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
          }"
        >
          <NDescriptions :column="3" label-placement="left">
            <NDescriptionsItem label="ID">{{ device.id }}</NDescriptionsItem>
            <NDescriptionsItem label="名称">{{ device.name }}</NDescriptionsItem>
            <NDescriptionsItem label="类型">{{ device.type }}</NDescriptionsItem>
            <NDescriptionsItem label="Device Key">{{ device.device_key }}</NDescriptionsItem>
            <NDescriptionsItem label="位置">{{ device.location || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="最后在线">{{ device.last_seen_at?.replace('T', ' ').slice(0, 19) || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="创建时间">{{ device.created_at?.replace('T', ' ').slice(0, 19) || '-' }}</NDescriptionsItem>
          </NDescriptions>
        </NCard>

        <!-- Attributes -->
        <NCard
          v-if="attributes.length"
          title="设备属性"
          :style="{
            backgroundColor: appStore.cardBg,
            backdropFilter: 'blur(12px)',
            borderRadius: '12px',
            border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
          }"
        >
          <NDescriptions :column="3" label-placement="left">
            <NDescriptionsItem
              v-for="attr in attributes"
              :key="attr.key || attr.attribute_key"
              :label="attr.key || attr.attribute_key"
            >
              {{ attr.value || attr.attribute_value }}
            </NDescriptionsItem>
          </NDescriptions>
        </NCard>

        <!-- Telemetry Data -->
        <NCard
          title="遥测数据"
          :style="{
            backgroundColor: appStore.cardBg,
            backdropFilter: 'blur(12px)',
            borderRadius: '12px',
            border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
          }"
        >
          <template #header-extra>
            <TimeRangePicker @change="loadTelemetry" />
          </template>
          <NDataTable
            :columns="telemetryColumns"
            :data="telemetryData"
            :bordered="false"
            size="small"
            max-height="400"
          />
        </NCard>

        <!-- Commands -->
        <NCard
          title="指令历史"
          :style="{
            backgroundColor: appStore.cardBg,
            backdropFilter: 'blur(12px)',
            borderRadius: '12px',
            border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
          }"
        >
          <template #header-extra>
            <NButton size="small" type="primary" @click="showCommandModal = true">
              <template #icon><NIcon><SendOutline /></NIcon></template>
              发送指令
            </NButton>
          </template>
          <NDataTable
            :columns="cmdColumns"
            :data="commandLogs"
            :bordered="false"
            size="small"
          />
        </NCard>
      </div>

      <NEmpty v-else-if="!loading" description="设备不存在" />
    </NSpin>

    <NModal
      v-model:show="showCommandModal"
      preset="dialog"
      title="发送指令"
      positive-text="发送"
      negative-text="取消"
      @positive-click="handleSendCommand"
      style="width: 480px"
    >
      <NForm :model="commandForm" label-placement="left" label-width="60">
        <NFormItem label="指令" path="command">
          <NInput v-model:value="commandForm.command" placeholder="输入指令名称" />
        </NFormItem>
        <NFormItem label="参数" path="params">
          <NInput
            v-model:value="commandForm.params"
            type="textarea"
            placeholder='JSON 格式参数，如 {"key": "value"}'
            :rows="4"
          />
        </NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
