import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

interface UserInfo {
  id: number
  username: string
  roles: string[]
  permissions: Record<string, boolean>
  locations: number[]
}

function parseJwtPayload(token: string): any {
  try {
    const base64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')
    return JSON.parse(atob(base64))
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const accessToken = ref(localStorage.getItem('access_token') || '')
  const refreshToken = ref(localStorage.getItem('refresh_token') || '')
  const user = ref<UserInfo | null>(null)

  const isLoggedIn = computed(() => !!accessToken.value)
  const isAdmin = computed(() => user.value?.roles.includes('admin') ?? false)

  function setTokens(access: string, refresh: string) {
    accessToken.value = access
    refreshToken.value = refresh
    localStorage.setItem('access_token', access)
    localStorage.setItem('refresh_token', refresh)

    const payload = parseJwtPayload(access)
    if (payload) {
      user.value = {
        id: payload.sub,
        username: payload.username,
        roles: payload.roles || [],
        permissions: payload.permissions || {},
        locations: payload.locations || [],
      }
    }
  }

  function clearAuth() {
    accessToken.value = ''
    refreshToken.value = ''
    user.value = null
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
  }

  function hasPermission(permission: string): boolean {
    if (isAdmin.value) return true
    return user.value?.permissions?.[permission] === true
  }

  // Restore user info from existing token on load
  if (accessToken.value) {
    const payload = parseJwtPayload(accessToken.value)
    if (payload && payload.exp * 1000 > Date.now()) {
      user.value = {
        id: payload.sub,
        username: payload.username,
        roles: payload.roles || [],
        permissions: payload.permissions || {},
        locations: payload.locations || [],
      }
    } else {
      clearAuth()
    }
  }

  return {
    accessToken,
    refreshToken,
    user,
    isLoggedIn,
    isAdmin,
    setTokens,
    clearAuth,
    hasPermission,
  }
})
