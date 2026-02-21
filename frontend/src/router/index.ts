import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/Login.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/',
      component: () => import('@/layouts/DefaultLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/Dashboard.vue'),
          meta: { title: '仪表盘', icon: 'HomeOutline' },
        },
        {
          path: 'devices',
          name: 'devices',
          component: () => import('@/views/devices/DeviceList.vue'),
          meta: { title: '设备管理', icon: 'HardwareChipOutline', permission: 'devices.view' },
        },
        {
          path: 'devices/:id',
          name: 'device-detail',
          component: () => import('@/views/devices/DeviceDetail.vue'),
          meta: { title: '设备详情', permission: 'devices.view' },
        },
        {
          path: 'telemetry',
          name: 'telemetry',
          component: () => import('@/views/telemetry/TelemetryChart.vue'),
          meta: { title: '数据图表', icon: 'StatsChartOutline', permission: 'devices.view' },
        },
        {
          path: 'alert-rules',
          name: 'alert-rules',
          component: () => import('@/views/alerts/AlertRules.vue'),
          meta: { title: '告警规则', icon: 'NotificationsOutline', permission: 'alerts.view' },
        },
        {
          path: 'alert-logs',
          name: 'alert-logs',
          component: () => import('@/views/alerts/AlertLogs.vue'),
          meta: { title: '告警日志', permission: 'alerts.view' },
        },
        {
          path: 'automations',
          name: 'automations',
          component: () => import('@/views/automations/AutomationList.vue'),
          meta: { title: '自动化', icon: 'GitBranchOutline', permission: 'alerts.view' },
        },
        {
          path: 'users',
          name: 'users',
          component: () => import('@/views/users/UserList.vue'),
          meta: { title: '用户管理', icon: 'PeopleOutline', permission: 'users.view' },
        },
        {
          path: 'roles',
          name: 'roles',
          component: () => import('@/views/users/RoleList.vue'),
          meta: { title: '角色管理', permission: 'users.view' },
        },
        {
          path: 'notification-channels',
          name: 'notification-channels',
          component: () => import('@/views/settings/NotificationChannels.vue'),
          meta: { title: '通知渠道', icon: 'MailOutline', permission: 'alerts.view' },
        },
        {
          path: 'audit-logs',
          name: 'audit-logs',
          component: () => import('@/views/settings/AuditLogs.vue'),
          meta: { title: '审计日志', icon: 'DocumentTextOutline' },
        },
        {
          path: 'dashboard-editor',
          name: 'dashboard-editor',
          component: () => import('@/views/dashboards/DashboardEditor.vue'),
          meta: { title: '自定义仪表盘', icon: 'GridOutline', permission: 'dashboards.view' },
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach((to, _from, next) => {
  const authStore = useAuthStore()
  if (to.meta.requiresAuth !== false && !authStore.isLoggedIn) {
    next('/login')
    return
  }
  if (to.path === '/login' && authStore.isLoggedIn) {
    next('/')
    return
  }
  const permission = to.meta.permission as string | undefined
  if (permission && !authStore.hasPermission(permission)) {
    next('/')
    return
  }
  next()
})

export default router
