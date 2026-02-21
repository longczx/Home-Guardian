<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { NCard, NDataTable, NButton, NIcon, NSpace, NPagination, useMessage } from 'naive-ui'
import { RefreshOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import request from '@/api/request'

const message = useMessage()
const appStore = useAppStore()

const loading = ref(false)
const logs = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '时间', key: 'created_at', width: 170, render: (row: any) => row.created_at?.replace('T', ' ').slice(0, 19) || '-' },
  { title: '用户', key: 'username', width: 100, render: (row: any) => row.user?.username || row.username || '-' },
  { title: '操作', key: 'action', width: 120 },
  { title: '资源类型', key: 'resource_type', width: 120 },
  { title: '资源ID', key: 'resource_id', width: 80 },
  { title: 'IP地址', key: 'ip_address', width: 130 },
  { title: '详情', key: 'details', ellipsis: true, render: (row: any) => JSON.stringify(row.details || row.changes || {}) },
]

async function loadLogs() {
  loading.value = true
  try {
    const res = await request.get<any, any>('/audit-logs', {
      params: { page: page.value, per_page: pageSize.value },
    })
    logs.value = res.data?.data || res.data || []
    total.value = res.data?.total || logs.value.length
  } catch {
    message.error('加载失败')
  } finally {
    loading.value = false
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
        审计日志
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
