import { computed } from 'vue'
import { useAppStore } from '@/stores/app'

export function useTheme() {
  const appStore = useAppStore()

  const isDark = computed(() => appStore.isDark)
  const toggleTheme = () => appStore.toggleTheme()

  const cardStyle = computed(() => ({
    backgroundColor: appStore.cardBg,
    backdropFilter: 'blur(12px)',
    borderRadius: '12px',
    border: `1px solid ${appStore.isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)'}`,
  }))

  return { isDark, toggleTheme, cardStyle }
}
