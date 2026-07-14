import { defineStore } from 'pinia';

/**
 * 多服务器配置
 *
 * 自托管场景：一个 App 可能要连自己家、爸妈家等多台服务器。
 * 每台服务器独立持有 baseURL + token 对（token 存于 auth store，按 serverId 分桶）。
 */
export interface ServerConfig {
  id: string;
  name: string;
  /** 形如 http://192.168.1.10:8787，不含末尾斜杠、不含 /api */
  url: string;
}

const STORAGE_KEY = 'hg_servers';
const CURRENT_KEY = 'hg_current_server';

interface ServerState {
  servers: ServerConfig[];
  currentId: string;
}

export const useServerStore = defineStore('server', {
  state: (): ServerState => ({
    servers: [],
    currentId: '',
  }),

  getters: {
    current(state): ServerConfig | undefined {
      return state.servers.find((s) => s.id === state.currentId);
    },
    /** 当前服务器的 API 基址（含 /api），无当前服务器返回空串 */
    apiBase(): string {
      return this.current ? `${this.current.url}/api` : '';
    },
    hasServer(state): boolean {
      return state.servers.length > 0;
    },
  },

  actions: {
    restore() {
      this.servers = uni.getStorageSync(STORAGE_KEY) || [];
      this.currentId = uni.getStorageSync(CURRENT_KEY) || (this.servers[0]?.id ?? '');
    },

    persist() {
      uni.setStorageSync(STORAGE_KEY, this.servers);
      uni.setStorageSync(CURRENT_KEY, this.currentId);
    },

    /** 新增或按 url 去重更新；返回该配置 id 并切为当前 */
    upsert(name: string, url: string): string {
      const normalized = url.trim().replace(/\/+$/, '').replace(/\/api$/, '');
      const existing = this.servers.find((s) => s.url === normalized);
      if (existing) {
        existing.name = name || existing.name;
        this.currentId = existing.id;
        this.persist();
        return existing.id;
      }
      // App/小程序无 crypto.randomUUID，用时间戳+序号足够本地唯一
      const id = `srv_${this.servers.length}_${Date.now().toString(36)}`;
      this.servers.push({ id, name: name || normalized, url: normalized });
      this.currentId = id;
      this.persist();
      return id;
    },

    switchTo(id: string) {
      if (this.servers.some((s) => s.id === id)) {
        this.currentId = id;
        this.persist();
      }
    },

    remove(id: string) {
      this.servers = this.servers.filter((s) => s.id !== id);
      if (this.currentId === id) {
        this.currentId = this.servers[0]?.id ?? '';
      }
      this.persist();
    },
  },
});
