<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NTag,
  NModal, NForm, NFormItem, NInput, NCheckboxGroup, NCheckbox,
  useMessage, useDialog,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import { getRoles, createRole, updateRole, deleteRole } from '@/api/user'

const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()

const loading = ref(false)
const roles = ref<any[]>([])

const showModal = ref(false)
const editingRole = ref<any>(null)
const formData = ref({
  name: '',
  display_name: '',
  description: '',
  permissions: [] as string[],
})

const allPermissions = [
  'devices.view', 'devices.create', 'devices.edit', 'devices.delete',
  'alerts.view', 'alerts.create', 'alerts.edit', 'alerts.delete',
  'users.view', 'users.create', 'users.edit', 'users.delete',
  'dashboards.view', 'dashboards.create', 'dashboards.edit', 'dashboards.delete',
  'commands.send',
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '标识', key: 'name', width: 120 },
  { title: '显示名', key: 'display_name', width: 120 },
  { title: '描述', key: 'description', ellipsis: true },
  {
    title: '权限',
    key: 'permissions',
    ellipsis: true,
    render: (row: any) => {
      const perms = row.permissions || []
      const items = Array.isArray(perms) ? perms : Object.keys(perms)
      return h(NSpace, { size: 4 }, () =>
        items.slice(0, 5).map((p: string) =>
          h(NTag, { size: 'tiny', bordered: false, key: p }, () => p)
        ).concat(items.length > 5 ? [h('span', { key: 'more' }, `+${items.length - 5}`)] : [])
      )
    },
  },
  { title: '用户数', key: 'users_count', width: 80 },
  {
    title: '操作',
    key: 'actions',
    width: 100,
    render: (row: any) => {
      if (row.name === 'admin') return null
      return h(NSpace, { size: 4 }, () => [
        h(NButton, {
          size: 'tiny', quaternary: true, type: 'primary',
          onClick: () => openEdit(row),
        }, { icon: () => h(NIcon, null, () => h(CreateOutline)) }),
        h(NButton, {
          size: 'tiny', quaternary: true, type: 'error',
          onClick: () => confirmDelete(row),
        }, { icon: () => h(NIcon, null, () => h(TrashOutline)) }),
      ])
    },
  },
]

async function loadRoles() {
  loading.value = true
  try {
    const res = await getRoles()
    roles.value = res.data?.data || res.data || []
  } catch {
    message.error('加载失败')
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingRole.value = null
  formData.value = { name: '', display_name: '', description: '', permissions: [] }
  showModal.value = true
}

function openEdit(role: any) {
  editingRole.value = role
  const perms = role.permissions || []
  formData.value = {
    name: role.name,
    display_name: role.display_name || '',
    description: role.description || '',
    permissions: Array.isArray(perms) ? perms : Object.keys(perms),
  }
  showModal.value = true
}

async function handleSave() {
  try {
    if (editingRole.value) {
      await updateRole(editingRole.value.id, formData.value)
      message.success('已更新')
    } else {
      await createRole(formData.value)
      message.success('已创建')
    }
    showModal.value = false
    loadRoles()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

function confirmDelete(role: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除角色 "${role.display_name || role.name}" 吗？`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await deleteRole(role.id)
        message.success('已删除')
        loadRoles()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(loadRoles)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        角色管理
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadRoles" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton type="primary" @click="openCreate">
          <template #icon><NIcon><AddOutline /></NIcon></template>
          创建角色
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
        :data="roles"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingRole ? '编辑角色' : '创建角色'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 560px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="标识"><NInput v-model:value="formData.name" :disabled="!!editingRole" placeholder="如 editor" /></NFormItem>
        <NFormItem label="显示名"><NInput v-model:value="formData.display_name" placeholder="如 编辑者" /></NFormItem>
        <NFormItem label="描述"><NInput v-model:value="formData.description" /></NFormItem>
        <NFormItem label="权限">
          <NCheckboxGroup v-model:value="formData.permissions">
            <div class="grid grid-cols-2 gap-1">
              <NCheckbox v-for="p in allPermissions" :key="p" :value="p" :label="p" />
            </div>
          </NCheckboxGroup>
        </NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
