<script setup lang="ts">
import { ref, h, onMounted } from 'vue'
import {
  NCard, NDataTable, NButton, NIcon, NSpace, NTag, NInput,
  NModal, NForm, NFormItem, NSelect, NTransfer,
  useMessage, useDialog,
} from 'naive-ui'
import { AddOutline, RefreshOutline, TrashOutline, CreateOutline } from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import { getUsers, createUser, updateUser, deleteUser } from '@/api/user'
import { getRoles } from '@/api/user'

const message = useMessage()
const dialog = useDialog()
const appStore = useAppStore()

const loading = ref(false)
const users = ref<any[]>([])
const roleOptions = ref<Array<{ label: string; value: number }>>([])
const search = ref('')

const showModal = ref(false)
const editingUser = ref<any>(null)
const formData = ref({
  username: '',
  password: '',
  nickname: '',
  email: '',
  phone: '',
  status: 'active',
  role_ids: [] as number[],
  allowed_locations: [] as string[],
})

const statusOptions = [
  { label: '活跃', value: 'active' },
  { label: '禁用', value: 'disabled' },
]

const columns = [
  { title: 'ID', key: 'id', width: 60 },
  { title: '用户名', key: 'username', width: 120 },
  { title: '昵称', key: 'nickname', width: 120, ellipsis: true },
  { title: '邮箱', key: 'email', ellipsis: true },
  {
    title: '角色',
    key: 'roles',
    width: 150,
    render: (row: any) => {
      const roles = row.roles || []
      return h(NSpace, { size: 4 }, () =>
        roles.map((r: any) => h(NTag, { size: 'small', bordered: false, key: r.id || r }, () => r.display_name || r.name || r))
      )
    },
  },
  {
    title: '状态',
    key: 'status',
    width: 80,
    render: (row: any) => h(NTag, {
      size: 'small', bordered: false,
      type: row.status === 'active' ? 'success' : 'error',
    }, () => row.status === 'active' ? '活跃' : '禁用'),
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

async function loadUsers() {
  loading.value = true
  try {
    const params: Record<string, any> = { per_page: 100 }
    if (search.value) params.search = search.value
    const res = await getUsers(params)
    users.value = res.data?.data || res.data || []
  } catch {
    message.error('加载失败')
  } finally {
    loading.value = false
  }
}

async function loadRoles() {
  try {
    const res = await getRoles()
    const list = res.data?.data || res.data || []
    roleOptions.value = list.map((r: any) => ({ label: r.display_name || r.name, value: r.id }))
  } catch {}
}

function openCreate() {
  editingUser.value = null
  formData.value = {
    username: '', password: '', nickname: '', email: '', phone: '',
    status: 'active', role_ids: [], allowed_locations: [],
  }
  showModal.value = true
}

function openEdit(user: any) {
  editingUser.value = user
  formData.value = {
    username: user.username,
    password: '',
    nickname: user.nickname || '',
    email: user.email || '',
    phone: user.phone || '',
    status: user.status,
    role_ids: (user.roles || []).map((r: any) => r.id),
    allowed_locations: user.allowed_locations || [],
  }
  showModal.value = true
}

async function handleSave() {
  try {
    const payload = { ...formData.value }
    if (editingUser.value && !payload.password) {
      delete (payload as any).password
    }
    if (editingUser.value) {
      await updateUser(editingUser.value.id, payload)
      message.success('用户已更新')
    } else {
      await createUser(payload)
      message.success('用户已创建')
    }
    showModal.value = false
    loadUsers()
  } catch (err: any) {
    message.error(err.response?.data?.message || '操作失败')
  }
}

function confirmDelete(user: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除用户 "${user.username}" 吗？`,
    positiveText: '删除',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await deleteUser(user.id)
        message.success('已删除')
        loadUsers()
      } catch {
        message.error('删除失败')
      }
    },
  })
}

onMounted(() => {
  loadUsers()
  loadRoles()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h2
        class="text-lg font-semibold"
        :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
      >
        用户管理
      </h2>
      <NSpace>
        <NButton quaternary circle @click="loadUsers" :loading="loading">
          <template #icon><NIcon><RefreshOutline /></NIcon></template>
        </NButton>
        <NButton type="primary" @click="openCreate">
          <template #icon><NIcon><AddOutline /></NIcon></template>
          添加用户
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
      <NSpace class="mb-4">
        <NInput
          v-model:value="search"
          placeholder="搜索用户..."
          clearable
          style="width: 240px"
          @update:value="loadUsers"
        />
      </NSpace>

      <NDataTable
        :columns="columns"
        :data="users"
        :loading="loading"
        :bordered="false"
        striped
        size="small"
      />
    </NCard>

    <NModal
      v-model:show="showModal"
      preset="dialog"
      :title="editingUser ? '编辑用户' : '添加用户'"
      positive-text="保存"
      negative-text="取消"
      @positive-click="handleSave"
      style="width: 520px"
    >
      <NForm :model="formData" label-placement="left" label-width="80">
        <NFormItem label="用户名"><NInput v-model:value="formData.username" :disabled="!!editingUser" /></NFormItem>
        <NFormItem :label="editingUser ? '新密码' : '密码'">
          <NInput v-model:value="formData.password" type="password" show-password-on="click" :placeholder="editingUser ? '留空则不修改' : '输入密码'" />
        </NFormItem>
        <NFormItem label="昵称"><NInput v-model:value="formData.nickname" /></NFormItem>
        <NFormItem label="邮箱"><NInput v-model:value="formData.email" /></NFormItem>
        <NFormItem label="手机"><NInput v-model:value="formData.phone" /></NFormItem>
        <NFormItem label="状态"><NSelect v-model:value="formData.status" :options="statusOptions" /></NFormItem>
        <NFormItem label="角色">
          <NSelect v-model:value="formData.role_ids" :options="roleOptions" multiple placeholder="选择角色" />
        </NFormItem>
      </NForm>
    </NModal>
  </div>
</template>
