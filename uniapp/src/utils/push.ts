import { registerPushDevice, unregisterPushDevice } from '@/api/push';

/**
 * 推送接入（仅 App 端有效；H5 / 小程序为 no-op）
 *
 * 登录成功后调用 registerPush() 上报个推 cid；App.vue 启动时装 setupPushListener()
 * 处理点击推送跳转。cid 持久化到本地，便于退出时注销。
 */

const CID_KEY = 'hg_push_cid';

export function currentPlatform(): string {
  // #ifdef APP-PLUS
  return 'app';
  // #endif
  // #ifdef MP-WEIXIN
  return 'mp-weixin';
  // #endif
  // #ifdef H5
  return 'h5';
  // #endif
  // eslint-disable-next-line no-unreachable
  return 'unknown';
}

export function registerPush(): void {
  // #ifdef APP-PLUS
  uni.getPushClientId({
    success: async (res) => {
      const cid = res.cid;
      if (!cid) return;
      try {
        await registerPushDevice(cid, currentPlatform());
        uni.setStorageSync(CID_KEY, cid);
      } catch {
        /* 上报失败不阻塞主流程，下次启动重试 */
      }
    },
    fail: () => {
      /* 未开通推送 / 非 App 环境，忽略 */
    },
  });
  // #endif
}

export async function unregisterPush(): Promise<void> {
  const cid = uni.getStorageSync(CID_KEY);
  if (cid) {
    try {
      await unregisterPushDevice(cid);
    } catch {
      /* ignore */
    }
    uni.removeStorageSync(CID_KEY);
  }
}

/** 装配点击推送 → 跳告警中心 */
export function setupPushListener(): void {
  // #ifdef APP-PLUS
  uni.onPushMessage((res) => {
    // 前台收到时 type=receive 不跳转；点击通知栏 type=click 才跳
    if (res.type === 'click') {
      uni.switchTab({ url: '/pages/alerts/alerts' });
    }
  });
  // #endif
}
