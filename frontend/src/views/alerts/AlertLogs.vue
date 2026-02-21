<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NSelect, NTag,
  useMessage, NPagination,
} from 'naive-ui'
import { RefreshOutline, CheckmarkCircleOutline, CheckmarkDoneCircleOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import { getAlertLogs, acknowledgeAlert, resolveAlert } from '@/api/alert'

const message = useMessage()
const appStore = useAppStore()

const loading = ref(false)
const logs = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const filterStatus = ref<string | null>(null)

const statusOptions = [
  { label: '全部状态', value: '' },
  { label: '已触发', value: 'triggered' },
  { label: '已确认', value: 'acknowledged' },
  { label: '已解决', value: 'resolved' },
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  {
    title: '时间',
    key: 'triggered_at',
    width: 170,
    render: (row: any) => row.triggered_at?.replace('T', ' ').slice(0, 19) || '-',
  },
  { title: '规则', key: 'rule_name', width: 140, ellipsis: true, render: (row: any) => row.alert_rule?.name || row.rule_name || '-' },
  { title: '设备', key: 'device_name', width: 120, ellipsis: true, render: (row: any) => row.device?.name || row.device_name || '-' },
  {
    title: '级别',
    key: 'severity',
    width: 80,
    render: (row: any) => {
      const sev = row.alert_rule?.severity || row.severity || 'info'
      const map: Record<string, 'info' | 'warning' | 'error'> = {
        info: 'info', warning: 'warning', critical: 'error',
      }
      return h(NTag, { size: 'small', type: map[sev] || 'default', bordered: false }, () => sev)
    },
  },
  { title: '消息', key: 'message', ellipsis: true },
  { title: '指标值', key: 'metric_value', width: 80 },
  {
    title: '状态',
    key: 'status',
    width: 90,
    render: (row: any) => {
      const map: Record<string, 'error' | 'warning' | 'success'> = {
        triggered: 'error', acknowledged: 'warning', resolved: 'success',
      }
      return h(NTag, { size: 'small', type: map[row.status] || 'default', bordered: false }, () => row.status)
    },
  },
  {
    title: '操作',
    key: 'actions',
    width: 140,
    render: (row: any) =>
      h(NSpace, { size: 4 }, () => [
        row.status === 'triggered' &&
          h(NButton, {
            size: 'tiny', type: 'warning', secondary: true,
            onClick: () => handleAcknowledge(row.id),
          }, { default: () => '确认', icon: () => h(NIcon, null, () => h(CheckmarkCircleOutline)) }),
        (row.status === 'triggered' || row.status === 'acknowledged') &&
          h(NButton, {
            size: 'tiny', type: 'success', secondary: true,
            onClick: () => handleResolve(row.id),
          }, { default: () => '解决', icon: () => h(NIcon, null, () => h(CheckmarkDoneCircleOutline)) }),
      ]),
  },
]

async function loadLogs() {
  loading.value = true
  try {
    const params: Record<string, any> = { page: page.value, per_page: pageSize.value }
    if (filterStatus.value) params.status = filterStatus.value
    const res = await getAlertLogs(params)
    logs.value = res.data?.data || res.data || []
    total.value = res.data?.total || logs.value.length
  } catch {
    message.error('加载告警日志失败')
  } finally {
    loading.value = false
  }
}

async function handleAcknowledge(id: number) {
  try {
    await acknowledgeAlert(id)
    message.success('告警已确认')
    loadLogs()
  } catch {
    message.error('操作失败')
  }
}

async function handleResolve(id: number) {
  try {
    await resolveAlert(id)
    message.success('告警已解决')
    loadLogs()
  } catch {
    message.error('操作失败')
  }
}

function handlePageChange(p: number) {
  page.value = p
  loadLogs()
}

onMounted(loadLogs)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        告警日志
      </h2>
      <NButton quaternary circle @click="loadLogs" :loading="loading">
        <template #icon><NIcon><RefreshOutline /></NIcon></template>
      </NButton>
    </div>

    <NCard
      :style="{
        backgroundColor: appStore.cardBg,
        backdropFilter: 'blur(12px)',
        borderRadius: '12px',
        border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
      }"
    >
      <NSpace class="mb-4">
        <NSelect
          v-model:value="filterStatus"
          :options="statusOptions"
          style="width: 150px"
          @update:value="loadLogs"
        />
      </NSpace>

      <NDataTable
        :columns="columns"
        :data="logs"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />

      <div class="flex justify-end mt-4" v-if="total > pageSize">
        <NPagination
          :page="page"
          :page-size="pageSize"
          :item-count="total"
          @update:page="handlePageChange"
        />
      </div>
    </NCard>
  </div>
</template>
