<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NSwitch, NSelect, NTag,
  NModal, NForm, NFormItem, NInput, NInputNumber,
  useMessage, useDialog,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import { usePermission } from '@/composables/usePermission'
import { getAlertRules, createAlertRule, updateAlertRule, deleteAlertRule } from '@/api/alert'
import { getDevices } from '@/api/device'

const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()
const { hasPermission } = usePermission()

const loading = ref(false)
const rules = ref<any[]>([])
const deviceOptions = ref<Array<{ label: string; value: number }>>([])

const showModal = ref(false)
const editingRule = ref<any>(null)
const formData = ref({
  name: '',
  device_id: null as number | null,
  metric: '',
  operator: '>',
  threshold: 0,
  severity: 'warning',
  message_template: '',
  enabled: true,
})

const operatorOptions = [
  { label: '>', value: '>' },
  { label: '>=', value: '>=' },
  { label: '<', value: '<' },
  { label: '<=', value: '<=' },
  { label: '==', value: '==' },
  { label: '!=', value: '!=' },
]

const severityOptions = [
  { label: '信息', value: 'info' },
  { label: '警告', value: 'warning' },
  { label: '严重', value: 'critical' },
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '名称', key: 'name', ellipsis: true },
  { title: '设备', key: 'device_name', width: 120, ellipsis: true, render: (row: any) => row.device?.name || row.device_name || row.device_id },
  { title: '指标', key: 'metric', width: 120 },
  {
    title: '条件',
    key: 'condition',
    width: 140,
    render: (row: any) => `${row.operator} ${row.threshold}`,
  },
  {
    title: '级别',
    key: 'severity',
    width: 80,
    render: (row: any) => {
      const map: Record<string, 'info' | 'warning' | 'error'> = {
        info: 'info', warning: 'warning', critical: 'error',
      }
      return h(NTag, { size: 'small', type: map[row.severity] || 'default', bordered: false }, () => row.severity)
    },
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

async function loadRules() {
  loading.value = true
  try {
    const res = await getAlertRules({ per_page: 100 })
    rules.value = res.data?.data || res.data || []
  } catch {
    message.error('加载告警规则失败')
  } finally {
    loading.value = false
  }
}

async function loadDevices() {
  try {
    const res = await getDevices({ per_page: 200 })
    const list = res.data?.data || res.data || []
    deviceOptions.value = list.map((d: any) => ({ label: d.name, value: d.id }))
  } catch {}
}

function openCreate() {
  editingRule.value = null
  formData.value = {
    name: '', device_id: null, metric: '', operator: '>',
    threshold: 0, severity: 'warning', message_template: '', enabled: true,
  }
  showModal.value = true
}

function openEdit(rule: any) {
  editingRule.value = rule
  formData.value = {
    name: rule.name,
    device_id: rule.device_id,
    metric: rule.metric,
    operator: rule.operator,
    threshold: rule.threshold,
    severity: rule.severity,
    message_template: rule.message_template || '',
    enabled: rule.enabled,
  }
  showModal.value = true
}

async function handleSave() {
  try {
    if (editingRule.value) {
      await updateAlertRule(editingRule.value.id, formData.value)
      message.success('规则已更新')
    } else {
      await createAlertRule(formData.value)
      message.success('规则已创建')
    }
    showModal.value = false
    loadRules()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

async function toggleEnabled(rule: any, enabled: boolean) {
  try {
    await updateAlertRule(rule.id, { ...rule, enabled })
    rule.enabled = enabled
  } catch {
    message.error('更新失败')
  }
}

function confirmDelete(rule: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除规则 "${rule.name}" 吗？`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await deleteAlertRule(rule.id)
        message.success('规则已删除')
        loadRules()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(() => {
  loadRules()
  loadDevices()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        告警规则
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadRules" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton
          v-if="hasPermission('alerts.create')"
          type="primary"
          @click="openCreate"
        >
          <template #icon><NIcon><AddOutline /></NIcon></template>
          创建规则
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
        :data="rules"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingRule ? '编辑规则' : '创建规则'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 540px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="名称" path="name">
          <NInput v-model:value="formData.name" placeholder="规则名称" />
        </NFormItem>
        <NFormItem label="设备" path="device_id">
          <NSelect v-model:value="formData.device_id" :options="deviceOptions" placeholder="选择设备" filterable />
        </NFormItem>
        <NFormItem label="指标" path="metric">
          <NInput v-model:value="formData.metric" placeholder="如 temperature, humidity" />
        </NFormItem>
        <NSpace :size="12">
          <NFormItem label="运算符" path="operator">
            <NSelect v-model:value="formData.operator" :options="operatorOptions" style="width: 100px" />
          </NFormItem>
          <NFormItem label="阈值" path="threshold">
            <NInputNumber v-model:value="formData.threshold" style="width: 140px" />
          </NFormItem>
        </NSpace>
        <NFormItem label="级别" path="severity">
          <NSelect v-model:value="formData.severity" :options="severityOptions" />
        </NFormItem>
        <NFormItem label="消息模板" path="message_template">
          <NInput v-model:value="formData.message_template" type="textarea" :rows="2" placeholder="告警消息模板" />
        </NFormItem>
        <NFormItem label="启用" path="enabled">
          <NSwitch v-model:value="formData.enabled" />
        </NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
