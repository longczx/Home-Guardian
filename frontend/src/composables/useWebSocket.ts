import { ref, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useDeviceStore } from '@/stores/device'
import { useAppStore } from '@/stores/app'
import { useNotification } from 'naive-ui'

export function useWebSocket() {
  const ws = ref<WebSocket | null>(null)
  const connected = ref(false)
  const authStore = useAuthStore()
  const deviceStore = useDeviceStore()
  const appStore = useAppStore()

  let reconnectTimer: ReturnType<typeof setTimeout> | null = null
  let retryCount = 0
  const maxRetryDelay = 30000
  let notification: ReturnType<typeof useNotification> | null = null

  try {
    notification = useNotification()
  } catch {}

  function getWsUrl(): string {
    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:'
    return `${protocol}//${location.host}/ws?token=${authStore.accessToken}`
  }

  function connect() {
    if (ws.value?.readyState === WebSocket.OPEN) return
    if (!authStore.accessToken) return

    const socket = new WebSocket(getWsUrl())

    socket.onopen = () => {
      connected.value = true
      retryCount = 0
    }

    socket.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data)
        handleMessage(msg)
      } catch {}
    }

    socket.onclose = () => {
      connected.value = false
      scheduleReconnect()
    }

    socket.onerror = () => {
      socket.close()
    }

    ws.value = socket
  }

  function handleMessage(msg: any) {
    switch (msg.type) {
      case 'telemetry_update':
        deviceStore.updateDeviceTelemetry(msg.data?.device_id, msg.data)
        break
      case 'device_status':
        deviceStore.updateDeviceStatus(msg.data?.device_id, msg.data?.status)
        break
      case 'alert_triggered':
        appStore.setAlertCount(appStore.alertCount + 1)
        notification?.warning({
          title: '告警触发',
          content: msg.data?.message || '设备告警',
          duration: 5000,
        })
        break
      case 'pong':
        break
    }
  }

  function scheduleReconnect() {
    if (reconnectTimer) return
    const delay = Math.min(1000 * Math.pow(2, retryCount), maxRetryDelay)
    retryCount++
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null
      if (authStore.isLoggedIn) connect()
    }, delay)
  }

  function disconnect() {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
    ws.value?.close()
    ws.value = null
    connected.value = false
  }

  function sendPing() {
    if (ws.value?.readyState === WebSocket.OPEN) {
      ws.value.send(JSON.stringify({ type: 'ping' }))
    }
  }

  const pingInterval = setInterval(sendPing, 30000)

  onUnmounted(() => {
    clearInterval(pingInterval)
    disconnect()
  })

  return { connected, connect, disconnect }
}
