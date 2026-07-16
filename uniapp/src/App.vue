<script setup lang="ts">
import { onLaunch } from '@dcloudio/uni-app';
import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';
import { useLocaleStore } from '@/stores/locale';
import { registerPush, setupPushListener } from '@/utils/push';

// 启动即恢复本地持久化的服务器配置与登录态；无当前服务器或未登录时，
// 各页面 onShow 的守卫会把用户导向服务器列表 / 登录页。
onLaunch(() => {
  useLocaleStore().restore();
  useServerStore().restore();
  const auth = useAuthStore();
  auth.restore();

  setupPushListener();
  // 已登录则补登记推送 cid（App 端有效）
  if (auth.isLoggedIn) {
    registerPush();
  }
});
</script>

<style lang="scss">
@import '@/uni.scss';

/* 全局基础样式（页面级样式在各自 .vue 中） */
page {
  background-color: $hg-bg;
  color: $hg-fg;
  font-size: 28rpx;
  line-height: 1.5;
}

view,
text {
  box-sizing: border-box;
}

/* H5 端 tabBar 文字放大（pages.json 的 fontSize 主要对 App/小程序生效） */
/* #ifdef H5 */
.uni-tabbar__label {
  font-size: 16px !important;
}
/* #endif */
</style>
