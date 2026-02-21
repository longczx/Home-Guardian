<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NTag, NSwitch,
  NModal, NForm, NFormItem, NInput, NSelect,
  useMessage, useDialog,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline, PaperPlaneOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import {
  getNotificationChannels, createNotificationChannel,
  updateNotificationChannel, deleteNotificationChannel,
  testNotificationChannel,
} from '@/api/notification'

const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()

const loading = ref(false)
const channels = ref<any[]>([])

const showModal = ref(false)
const editingChannel = ref<any>(null)
const formData = ref({
  name: '',
  type: 'email',
  config: '{}',
  enabled: true,
})

const typeOptions = [
  { label: '邮件', value: 'email' },
  { label: 'Webhook', value: 'webhook' },
  { label: '企业微信', value: 'wechat_work' },
  { label: '钉钉', value: 'dingtalk' },
  { label: 'Telegram', value: 'telegram' },
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '名称', key: 'name', ellipsis: true },
  {
    title: '类型',
    key: 'type',
    width: 100,
    render: (row: any) => h(NTag, { size: 'small', bordered: false }, () => row.type),
  },
  {
    title: '启用',
    key: 'enabled',
    width: 80,
    render: (row: any) => h(NSwitch, {
      size: 'small',
      value: row.enabled,
      onUpdateValue: (val: boolean) => toggleEnabled(row, val),
    }),
  },
  { title: '创建时间', key: 'created_at', width: 170, render: (row: any) => row.created_at?.replace('T', ' ').slice(0, 19) || '-' },
  {
    title: '操作',
    key: 'actions',
    width: 160,
    render: (row: any) =>
      h(NSpace, { size: 4 }, () => [
        h(NButton, {
          size: 'tiny', type: 'info', secondary: true,
          onClick: () => handleTest(row.id),
        }, { default: () => '测试', icon: () => h(NIcon, null, () => h(PaperPlaneOutline)) }),
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

async function loadChannels() {
  loading.value = true
  try {
    const res = await getNotificationChannels({ per_page: 100 })
    channels.value = res.data?.data || res.data || []
  } catch {
    message.error('加载失败')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingChannel.value = null
  formData.value = { name: '', type: 'email', config: '{}', enabled: true }
  showModal.value = true
}

function openEdit(channel: any) {
  editingChannel.value = channel
  formData.value = {
    name: channel.name,
    type: channel.type,
    config: JSON.stringify(channel.config || {}, null, 2),
    enabled: channel.enabled,
  }
  showModal.value = true
}

async function handleSave() {
  try {
    const payload = { ...formData.value, config: JSON.parse(formData.value.config) }
    if (editingChannel.value) {
      await updateNotificationChannel(editingChannel.value.id, payload)
      message.success('已更新')
    } else {
      await createNotificationChannel(payload)
      message.success('已创建')
    }
    showModal.value = false
    loadChannels()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

async function toggleEnabled(channel: any, enabled: boolean) {
  try {
    await updateNotificationChannel(channel.id, { ...channel, enabled })
    channel.enabled = enabled
  } catch {
    message.error('更新失败')
  }
}

async function handleTest(id: number) {
  try {
    await testNotificationChannel(id)
    message.success('测试通知已发送')
  } catch (err: any) {
    message.error(err.response?.data?.message || '测试发送失败')
  }
}

function confirmDelete(channel: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除渠道 "${channel.name}" 吗？`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await deleteNotificationChannel(channel.id)
        message.success('已删除')
        loadChannels()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(loadChannels)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        通知渠道
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadChannels" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton type="primary" @click="openCreate">
          <template #icon><NIcon><AddOutline /></NIcon></template>
          添加渠道
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
        :data="channels"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingChannel ? '编辑渠道' : '添加渠道'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 520px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="名称"><NInput v-model:value="formData.name" placeholder="渠道名称" /></NFormItem>
        <NFormItem label="类型"><NSelect v-model:value="formData.type" :options="typeOptions" /></NFormItem>
        <NFormItem label="配置">
          <NInput v-model:value="formData.config" type="textarea" :rows="5" placeholder="JSON 配置" />
        </NFormItem>
        <NFormItem label="启用"><NSwitch v-model:value="formData.enabled" /></NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
