import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';

/**
 * 页面级访问守卫：在受保护页面的 onShow 里调用。
 * 无服务器 → 服务器列表；有服务器但未登录 → 登录页。
 * 返回 true 表示可继续渲染。
 */
export function ensureReady(): boolean {
  const server = useServerStore();
  if (!server.hasServer) {
    uni.reLaunch({ url: '/pages/server/list' });
    return false;
  }
  if (!useAuthStore().isLoggedIn) {
    uni.reLaunch({ url: '/pages/auth/login' });
    return false;
  }
  return true;
}

export function toast(title: string, icon: 'success' | 'error' | 'none' = 'none') {
  uni.showToast({ title, icon, duration: 2000 });
}
