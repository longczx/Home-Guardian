<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NInput, NSelect,
  NModal, NForm, NFormItem, NSwitch, useMessage, useDialog,
  NTag,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline } from '@vicons/ionicons5'
import StatusBadge from '@/components/StatusBadge.vue'
import { useAppStore } from '@/stores/app'
import { usePermission } from '@/composables/usePermission'
import { getDevices, createDevice, updateDevice, deleteDevice } from '@/api/device'
import { useRouter } from 'vue-router'

const router = useRouter()
const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()
const { hasPermission } = usePermission()

const loading = ref(false)
const devices = ref<any[]>([])
const search = ref('')
const filterType = ref<string | null>(null)
const filterStatus = ref<string | null>(null)

const showModal = ref(false)
const editingDevice = ref<any>(null)
const formData = ref({
  name: '',
  type: 'sensor',
  location: '',
  mqtt_username: '',
  mqtt_password: '',
})

const typeOptions = [
  { label: '全部类型', value: '' },
  { label: '传感器', value: 'sensor' },
  { label: '执行器', value: 'actuator' },
  { label: '网关', value: 'gateway' },
  { label: '摄像头', value: 'camera' },
]

const statusOptions = [
  { label: '全部状态', value: '' },
  { label: '在线', value: 'online' },
  { label: '离线', value: 'offline' },
  { label: '未激活', value: 'inactive' },
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  {
    title: '名称',
    key: 'name',
    ellipsis: true,
    render: (row: any) =>
      h('a', {
        class: 'cursor-pointer text-[#18a058] hover:underline',
        onClick: () => router.push({ name: 'device-detail', params: { id: row.id } }),
      }, row.name),
  },
  {
    title: '类型',
    key: 'type',
    width: 100,
    render: (row: any) => h(NTag, { size: 'small', bordered: false }, () => row.type),
  },
  { title: '位置', key: 'location', width: 120, ellipsis: true },
  { title: 'Device Key', key: 'device_key', width: 200, ellipsis: true },
  {
    title: '状态',
    key: 'status',
    width: 90,
    render: (row: any) => h(StatusBadge, { status: row.status }),
  },
  { title: '最后上线', key: 'last_seen_at', width: 170, render: (row: any) => row.last_seen_at?.replace('T', ' ').slice(0, 19) || '-' },
  {
    title: '操作',
    key: 'actions',
    width: 120,
    render: (row: any) =>
      h(NSpace, { size: 4 }, () => [
        hasPermission('devices.edit') && h(NButton, {
          size: 'tiny', quaternary: true, type: 'primary',
          onClick: () => openEdit(row),
        }, { icon: () => h(NIcon, null, () => h(CreateOutline)) }),
        hasPermission('devices.delete') && h(NButton, {
          size: 'tiny', quaternary: true, type: 'error',
          onClick: () => confirmDelete(row),
        }, { icon: () => h(NIcon, null, () => h(TrashOutline)) }),
      ]),
  },
]

async function loadDevices() {
  loading.value = true
  try {
    const params: Record<string, any> = { per_page: 100 }
    if (search.value) params.search = search.value
    if (filterType.value) params.type = filterType.value
    if (filterStatus.value) params.status = filterStatus.value
    const res = await getDevices(params)
    devices.value = res.data?.data || res.data || []
  } catch {
    message.error('加载设备列表失败')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingDevice.value = null
  formData.value = { name: '', type: 'sensor', location: '', mqtt_username: '', mqtt_password: '' }
  showModal.value = true
}

function openEdit(device: any) {
  editingDevice.value = device
  formData.value = {
    name: device.name,
    type: device.type,
    location: device.location || '',
    mqtt_username: '',
    mqtt_password: '',
  }
  showModal.value = true
}

async function handleSave() {
  try {
    if (editingDevice.value) {
      await updateDevice(editingDevice.value.id, formData.value)
      message.success('设备已更新')
    } else {
      await createDevice(formData.value)
      message.success('设备已创建')
    }
    showModal.value = false
    loadDevices()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

function confirmDelete(device: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除设备 "${device.name}" 吗？此操作不可恢复。`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await deleteDevice(device.id)
        message.success('设备已删除')
        loadDevices()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(loadDevices)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        设备管理
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadDevices" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton
          v-if="hasPermission('devices.create')"
          type="primary"
          @click="openCreate"
        >
          <template #icon><NIcon><AddOutline /></NIcon></template>
          添加设备
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
      <NSpace class="mb-4" :size="12">
        <NInput
          v-model:value="search"
          placeholder="搜索设备..."
          clearable
          style="width: 200px"
          @update:value="loadDevices"
        />
        <NSelect
          v-model:value="filterType"
          :options="typeOptions"
          style="width: 140px"
          clearable
          @update:value="loadDevices"
        />
        <NSelect
          v-model:value="filterStatus"
          :options="statusOptions"
          style="width: 140px"
          clearable
          @update:value="loadDevices"
        />
      </NSpace>

      <NDataTable
        :columns="columns"
        :data="devices"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingDevice ? '编辑设备' : '添加设备'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 500px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="名称" path="name">
          <NInput v-model:value="formData.name" placeholder="输入设备名称" />
        </NFormItem>
        <NFormItem label="类型" path="type">
          <NSelect
            v-model:value="formData.type"
            :options="typeOptions.filter(o => o.value)"
          />
        </NFormItem>
        <NFormItem label="位置" path="location">
          <NInput v-model:value="formData.location" placeholder="输入设备位置" />
        </NFormItem>
        <template v-if="!editingDevice">
          <NFormItem label="MQTT用户" path="mqtt_username">
            <NInput v-model:value="formData.mqtt_username" placeholder="MQTT 认证用户名" />
          </NFormItem>
          <NFormItem label="MQTT密码" path="mqtt_password">
            <NInput v-model:value="formData.mqtt_password" type="password" placeholder="MQTT 认证密码" />
          </NFormItem>
        </template>
      </NForm>
    </NModal>
  </div>
</template>
