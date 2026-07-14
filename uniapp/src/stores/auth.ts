import { defineStore } from 'pinia';
import { useServerStore } from './server';

/**
 * 登录态 —— 按服务器分桶
 *
 * 不同服务器各有独立的 token 对与用户信息，切换服务器即切换登录上下文。
 * 持久化结构：{ [serverId]: AuthEntry }。
 */
export interface AuthUser {
  id: number;
  username: string;
  home_id: number;
  home_role: 'owner' | 'admin' | 'member';
}

interface AuthEntry {
  accessToken: string;
  refreshToken: string;
  user: AuthUser | null;
}

const STORAGE_KEY = 'hg_auth';

interface AuthState {
  byServer: Record<string, AuthEntry>;
}

function emptyEntry(): AuthEntry {
  return { accessToken: '', refreshToken: '', user: null };
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    byServer: {},
  }),

  getters: {
    entry(state): AuthEntry {
      const sid = useServerStore().currentId;
      return state.byServer[sid] ?? emptyEntry();
    },
    accessToken(): string {
      return this.entry.accessToken;
    },
    refreshToken(): string {
      return this.entry.refreshToken;
    },
    user(): AuthUser | null {
      return this.entry.user;
    },
    isLoggedIn(): boolean {
      return !!this.entry.accessToken;
    },
    /** 家庭内是否具管理能力（owner/admin），用于「管理」Tab 显隐 */
    canManage(): boolean {
      const role = this.entry.user?.home_role;
      return role === 'owner' || role === 'admin';
    },
  },

  actions: {
    restore() {
      this.byServer = uni.getStorageSync(STORAGE_KEY) || {};
    },

    persist() {
      uni.setStorageSync(STORAGE_KEY, this.byServer);
    },

    setSession(accessToken: string, refreshToken: string, user: AuthUser | null) {
      const sid = useServerStore().currentId;
      this.byServer[sid] = { accessToken, refreshToken, user };
      this.persist();
    },

    setTokens(accessToken: string, refreshToken: string) {
      const sid = useServerStore().currentId;
      const prev = this.byServer[sid] ?? emptyEntry();
      this.byServer[sid] = { ...prev, accessToken, refreshToken };
      this.persist();
    },

    logout() {
      const sid = useServerStore().currentId;
      delete this.byServer[sid];
      this.persist();
    },
  },
});
