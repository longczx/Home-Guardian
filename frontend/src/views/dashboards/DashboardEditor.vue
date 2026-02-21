<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NTag,
  NModal, NForm, NFormItem, NInput, NSwitch, NSelect,
  useMessage, useDialog, NEmpty,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import request from '@/api/request'

const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()

const loading = ref(false)
const dashboards = ref<any[]>([])

const showModal = ref(false)
const editingDashboard = ref<any>(null)
const formData = ref({
  name: '',
  description: '',
  layout: '[]',
  is_default: false,
})

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '名称', key: 'name', ellipsis: true },
  { title: '描述', key: 'description', ellipsis: true },
  {
    title: '默认',
    key: 'is_default',
    width: 80,
    render: (row: any) => row.is_default
      ? h(NTag, { size: 'small', type: 'success', bordered: false }, () => '是')
      : h(NTag, { size: 'small', bordered: false }, () => '否'),
  },
  { title: '创建时间', key: 'created_at', width: 170, render: (row: any) => row.created_at?.replace('T', ' ').slice(0, 19) || '-' },
  {
    title: '操作',
    key: 'actions',
    width: 100,
    render: (row: any) =>
      h(NSpace, { size: 4 }, () => [
        h(NButton, {
          size: 'tiny', quaternary: true, type: 'primary',
          onClick: () => openEdit(row),
        }, { icon: () => h(NIcon, null, () => h(CreateOutline)) }),
        h(NButton, {
          size: 'tiny', quaternary: true, type: 'error',
          onClick: () => confirmDelete(row),
        }, { icon: () => h(NIcon, null, () => h(TrashOutline)) }),
      ]),
  },
]

async function loadDashboards() {
  loading.value = true
  try {
    const res = await request.get<any, any>('/dashboards', { params: { per_page: 100 } })
    dashboards.value = res.data?.data || res.data || []
  } catch {
    message.error('加载失败')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingDashboard.value = null
  formData.value = { name: '', description: '', layout: '[]', is_default: false }
  showModal.value = true
}

function openEdit(dashboard: any) {
  editingDashboard.value = dashboard
  formData.value = {
    name: dashboard.name,
    description: dashboard.description || '',
    layout: JSON.stringify(dashboard.layout || [], null, 2),
    is_default: dashboard.is_default || false,
  }
  showModal.value = true
}

async function handleSave() {
  try {
    const payload = {
      ...formData.value,
      layout: JSON.parse(formData.value.layout),
    }
    if (editingDashboard.value) {
      await request.put(`/dashboards/${editingDashboard.value.id}`, payload)
      message.success('已更新')
    } else {
      await request.post('/dashboards', payload)
      message.success('已创建')
    }
    showModal.value = false
    loadDashboards()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

function confirmDelete(dashboard: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除仪表盘 "${dashboard.name}" 吗？`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await request.delete(`/dashboards/${dashboard.id}`)
        message.success('已删除')
        loadDashboards()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(loadDashboards)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        自定义仪表盘
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadDashboards" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton type="primary" @click="openCreate">
          <template #icon><NIcon><AddOutline /></NIcon></template>
          创建仪表盘
        </NButton>
      </NSpace>
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
        :data="dashboards"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />

      <NEmpty v-if="dashboards.length === 0 && !loading" description="暂无自定义仪表盘" class="py-8" />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingDashboard ? '编辑仪表盘' : '创建仪表盘'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 600px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="名称"><NInput v-model:value="formData.name" placeholder="仪表盘名称" /></NFormItem>
        <NFormItem label="描述"><NInput v-model:value="formData.description" type="textarea" :rows="2" /></NFormItem>
        <NFormItem label="布局配置">
          <NInput
            v-model:value="formData.layout"
            type="textarea"
            :rows="8"
            placeholder="JSON 布局配置数组"
            style="font-family: monospace"
          />
        </NFormItem>
        <NFormItem label="设为默认"><NSwitch v-model:value="formData.is_default" /></NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
