import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useAppStore = defineStore('app', () => {
  const isDark = ref(localStorage.getItem('theme') !== 'light')
  const sidebarCollapsed = ref(false)
  const alertCount = ref(0)

  function toggleTheme() {
    isDark.value = !isDark.value
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light')
  }

  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  function setAlertCount(count: number) {
    alertCount.value = count
  }

  const contentBg = computed(() => (isDark.value ? '#101014' : '#f5f5f5'))
  const cardBg = computed(() => (isDark.value ? 'rgba(36, 36, 40, 0.85)' : 'rgba(255, 255, 255, 0.85)'))

  return {
    isDark,
    sidebarCollapsed,
    alertCount,
    toggleTheme,
    toggleSidebar,
    setAlertCount,
    contentBg,
    cardBg,
  }
})
