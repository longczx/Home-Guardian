<script setup lang="ts">
import { h, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NLayout,
  NLayoutSider,
  NLayoutHeader,
  NLayoutContent,
  NMenu,
  NButton,
  NDropdown,
  NIcon,
  NBadge,
  NSpace,
  NAvatar,
  useMessage,
  type MenuOption,
} from 'naive-ui'
import {
  HomeOutline,
  HardwareChipOutline,
  StatsChartOutline,
  NotificationsOutline,
  GitBranchOutline,
  PeopleOutline,
  MailOutline,
  DocumentTextOutline,
  GridOutline,
  MenuOutline,
  SunnyOutline,
  MoonOutline,
  LogOutOutline,
  PersonOutline,
  ChevronDownOutline,
  ShieldCheckmarkOutline,
} from '@vicons/ionicons5'
import { useAppStore } from '@/stores/app'
import { useAuthStore } from '@/stores/auth'
import { logoutApi } from '@/api/auth'

const route = useRoute()
const router = useRouter()
const message = useMessage()
const appStore = useAppStore()
const authStore = useAuthStore()

const renderIcon = (icon: any) => () => h(NIcon, null, { default: () => h(icon) })

const menuOptions = computed<MenuOption[]>(() => {
  const items: MenuOption[] = [
    { label: '仪表盘', key: 'dashboard', icon: renderIcon(HomeOutline) },
    { label: '设备管理', key: 'devices', icon: renderIcon(HardwareChipOutline) },
    { label: '数据图表', key: 'telemetry', icon: renderIcon(StatsChartOutline) },
    { type: 'divider', key: 'd1' },
    { label: '告警规则', key: 'alert-rules', icon: renderIcon(NotificationsOutline) },
    { label: '告警日志', key: 'alert-logs', icon: renderIcon(DocumentTextOutline) },
    { label: '自动化', key: 'automations', icon: renderIcon(GitBranchOutline) },
    { type: 'divider', key: 'd2' },
    { label: '自定义面板', key: 'dashboard-editor', icon: renderIcon(GridOutline) },
  ]

  if (authStore.hasPermission('users.view')) {
    items.push(
      { type: 'divider', key: 'd3' },
      { label: '用户管理', key: 'users', icon: renderIcon(PeopleOutline) },
      { label: '角色管理', key: 'roles', icon: renderIcon(ShieldCheckmarkOutline) },
    )
  }

  items.push(
    { type: 'divider', key: 'd4' },
    { label: '通知渠道', key: 'notification-channels', icon: renderIcon(MailOutline) },
    { label: '审计日志', key: 'audit-logs', icon: renderIcon(DocumentTextOutline) },
  )

  return items
})

const activeKey = computed(() => {
  const name = route.name as string
  if (name === 'device-detail') return 'devices'
  return name || 'dashboard'
})

function handleMenuUpdate(key: string) {
  router.push({ name: key })
}

const userDropdownOptions = [
  { label: '退出登录', key: 'logout', icon: renderIcon(LogOutOutline) },
]

async function handleUserAction(key: string) {
  if (key === 'logout') {
    try {
      await logoutApi()
    } catch {}
    authStore.clearAuth()
    message.success('已退出登录')
    router.push('/login')
  }
}
</script>

<template>
  <NLayout has-sider position="absolute">
    <NLayoutSider
      bordered
      :collapsed="appStore.sidebarCollapsed"
      collapse-mode="width"
      :collapsed-width="64"
      :width="220"
      show-trigger="bar"
      @collapse="appStore.sidebarCollapsed = true"
      @expand="appStore.sidebarCollapsed = false"
      :native-scrollbar="false"
      :style="{
        backgroundColor: appStore.isDark ? '#18181c' : '#fff',
      }"
    >
      <div
        class="flex items-center gap-2 px-4 py-4"
        :class="appStore.sidebarCollapsed ? 'justify-center' : ''"
      >
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center text-sm shrink-0"
          style="background: linear-gradient(135deg, #18a058, #2080f0)"
        >
          🏠
        </div>
        <span
          v-if="!appStore.sidebarCollapsed"
          class="font-semibold text-base whitespace-nowrap"
          :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
        >
          Home Guardian
        </span>
      </div>

      <NMenu
        :collapsed="appStore.sidebarCollapsed"
        :collapsed-width="64"
        :collapsed-icon-size="20"
        :options="menuOptions"
        :value="activeKey"
        @update:value="handleMenuUpdate"
      />
    </NLayoutSider>

    <NLayout>
      <NLayoutHeader
        bordered
        class="flex items-center justify-between px-4"
        :style="{
          height: '56px',
          backgroundColor: appStore.isDark ? '#18181c' : '#fff',
        }"
      >
        <div class="flex items-center gap-2">
          <NButton quaternary circle @click="appStore.toggleSidebar">
            <template #icon>
              <NIcon><MenuOutline /></NIcon>
            </template>
          </NButton>
          <span
            class="text-base font-medium"
            :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.82)' : 'rgba(0,0,0,0.82)' }"
          >
            {{ (route.meta.title as string) || 'Home Guardian' }}
          </span>
        </div>

        <NSpace :size="8" align="center">
          <NButton quaternary circle @click="appStore.toggleTheme">
            <template #icon>
              <NIcon>
                <MoonOutline v-if="appStore.isDark" />
                <SunnyOutline v-else />
              </NIcon>
            </template>
          </NButton>

          <NBadge :value="appStore.alertCount" :max="99" :offset="[-4, 4]">
            <NButton quaternary circle @click="router.push({ name: 'alert-logs' })">
              <template #icon>
                <NIcon><NotificationsOutline /></NIcon>
              </template>
            </NButton>
          </NBadge>

          <NDropdown
            :options="userDropdownOptions"
            trigger="click"
            @select="handleUserAction"
          >
            <NButton quaternary size="small" class="ml-2">
              <template #icon>
                <NAvatar :size="24" round>
                  <NIcon :size="14"><PersonOutline /></NIcon>
                </NAvatar>
              </template>
              <span class="ml-1">{{ authStore.user?.username }}</span>
            </NButton>
          </NDropdown>
        </NSpace>
      </NLayoutHeader>

      <NLayoutContent
        :native-scrollbar="false"
        content-class="p-6"
        :content-style="{
          backgroundColor: appStore.isDark ? '#101014' : '#f5f5f5',
          minHeight: 'calc(100vh - 56px)',
        }"
      >
        <RouterView />
      </NLayoutContent>
    </NLayout>
  </NLayout>
</template>
