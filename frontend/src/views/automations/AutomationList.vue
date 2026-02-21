<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NSwitch, NTag,
  NModal, NForm, NFormItem, NInput, NSelect,
  useMessage, useDialog,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import { getAutomations, createAutomation, updateAutomation, deleteAutomation } from '@/api/automation'

const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()

const loading = ref(false)
const automations = ref<any[]>([])

const showModal = ref(false)
const editingItem = ref<any>(null)
const formData = ref({
  name: '',
  description: '',
  trigger_type: 'telemetry',
  trigger_config: '{}',
  action_type: 'command',
  action_config: '{}',
  enabled: true,
})

const triggerTypeOptions = [
  { label: '遥测触发', value: 'telemetry' },
  { label: '设备状态', value: 'device_status' },
  { label: '定时触发', value: 'schedule' },
]

const actionTypeOptions = [
  { label: '发送指令', value: 'command' },
  { label: '发送通知', value: 'notification' },
  { label: 'Webhook', value: 'webhook' },
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '名称', key: 'name', ellipsis: true },
  { title: '描述', key: 'description', ellipsis: true },
  {
    title: '触发类型',
    key: 'trigger_type',
    width: 110,
    render: (row: any) => h(NTag, { size: 'small', bordered: false }, () => row.trigger_type),
  },
  {
    title: '动作类型',
    key: 'action_type',
    width: 110,
    render: (row: any) => h(NTag, { size: 'small', bordered: false }, () => row.action_type),
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

async function loadAutomations() {
  loading.value = true
  try {
    const res = await getAutomations({ per_page: 100 })
    automations.value = res.data?.data || res.data || []
  } catch {
    message.error('加载失败')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingItem.value = null
  formData.value = {
    name: '', description: '', trigger_type: 'telemetry',
    trigger_config: '{}', action_type: 'command', action_config: '{}', enabled: true,
  }
  showModal.value = true
}

function openEdit(item: any) {
  editingItem.value = item
  formData.value = {
    name: item.name,
    description: item.description || '',
    trigger_type: item.trigger_type,
    trigger_config: JSON.stringify(item.trigger_config || {}, null, 2),
    action_type: item.action_type,
    action_config: JSON.stringify(item.action_config || {}, null, 2),
    enabled: item.enabled,
  }
  showModal.value = true
}

async function handleSave() {
  try {
    const payload = {
      ...formData.value,
      trigger_config: JSON.parse(formData.value.trigger_config),
      action_config: JSON.parse(formData.value.action_config),
    }
    if (editingItem.value) {
      await updateAutomation(editingItem.value.id, payload)
      message.success('已更新')
    } else {
      await createAutomation(payload)
      message.success('已创建')
    }
    showModal.value = false
    loadAutomations()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

async function toggleEnabled(item: any, enabled: boolean) {
  try {
    await updateAutomation(item.id, { ...item, enabled })
    item.enabled = enabled
  } catch {
    message.error('更新失败')
  }
}

function confirmDelete(item: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除自动化 "${item.name}" 吗？`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await deleteAutomation(item.id)
        message.success('已删除')
        loadAutomations()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(loadAutomations)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        自动化规则
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadAutomations" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton type="primary" @click="openCreate">
          <template #icon><NIcon><AddOutline /></NIcon></template>
          创建自动化
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
        :data="automations"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingItem ? '编辑自动化' : '创建自动化'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 580px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="名称"><NInput v-model:value="formData.name" placeholder="自动化名称" /></NFormItem>
        <NFormItem label="描述"><NInput v-model:value="formData.description" type="textarea" :rows="2" /></NFormItem>
        <NFormItem label="触发类型"><NSelect v-model:value="formData.trigger_type" :options="triggerTypeOptions" /></NFormItem>
        <NFormItem label="触发配置"><NInput v-model:value="formData.trigger_config" type="textarea" :rows="3" placeholder="JSON 配置" /></NFormItem>
        <NFormItem label="动作类型"><NSelect v-model:value="formData.action_type" :options="actionTypeOptions" /></NFormItem>
        <NFormItem label="动作配置"><NInput v-model:value="formData.action_config" type="textarea" :rows="3" placeholder="JSON 配置" /></NFormItem>
        <NFormItem label="启用"><NSwitch v-model:value="formData.enabled" /></NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
