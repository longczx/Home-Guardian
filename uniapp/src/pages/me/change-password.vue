<script setup lang="ts">
import { ref, watch } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { apiRequest } from '@/api/request';
import { useAuthStore } from '@/stores/auth';
import { useServerStore } from '@/stores/server';
import { closeWs } from '@/utils/ws';
import { ensureReady, toast } from '@/utils/guard';
const oldPassword = ref(''); const newPassword = ref(''); const confirmPassword = ref('');
const busy = ref(false); const error = ref('');
const auth = useAuthStore(); const server = useServerStore();
let generation = 0;
watch(() => server.connectionRevision, () => { generation++; oldPassword.value = ''; newPassword.value = ''; confirmPassword.value = ''; busy.value = false; error.value = '服务器已切换，请重新输入'; });
onShow(ensureReady);
async function save() {
  if (busy.value || !ensureReady()) return;
  error.value = '';
  if (!oldPassword.value) { error.value = '请输入当前密码'; return; }
  if (Array.from(newPassword.value).length < 6) { error.value = '新密码至少需要 6 个字符'; return; }
  if (encodeURIComponent(newPassword.value).replace(/%[0-9A-F]{2}/g, 'x').length > 72) { error.value = '新密码过长，请缩短'; return; }
  if (newPassword.value === oldPassword.value) { error.value = '新密码不能与当前密码相同'; return; }
  if (newPassword.value !== confirmPassword.value) { error.value = '两次输入的新密码不一致'; return; }
  busy.value = true;
  const attempt = ++generation;
  const sid = server.currentId;
  try {
    await apiRequest('/auth/change-password', { method: 'POST', data: { old_password: oldPassword.value, new_password: newPassword.value }, business401Code: 1003 });
    if (attempt !== generation) return;
    oldPassword.value = ''; newPassword.value = ''; confirmPassword.value = '';
    closeWs(); auth.logout(sid);
    toast('密码已修改，请重新登录', 'success');
    uni.reLaunch({ url: '/pages/auth/login' });
  } catch (e) { if (attempt === generation) error.value = (e as Error).message; }
  finally { if (attempt === generation) busy.value = false; }
}
</script>
<template>
  <view class="page">
    <text class="title">
      修改登录密码
    </text>
    <text class="hint">
      修改当前服务器账户的密码。成功后所有已登录设备都需重新登录。
    </text>
    <view class="field">
      <text>当前密码</text><input
        v-model="oldPassword"
        password
        :maxlength="128"
        :disabled="busy"
        placeholder="请输入当前密码"
      >
    </view>
    <view class="field">
      <text>新密码</text><input
        v-model="newPassword"
        password
        :maxlength="72"
        :disabled="busy"
        placeholder="6–72 个字符"
      >
    </view>
    <view class="field">
      <text>确认新密码</text><input
        v-model="confirmPassword"
        password
        :maxlength="72"
        :disabled="busy"
        placeholder="再次输入新密码"
      >
    </view>
    <text
      v-if="error"
      class="error"
    >
      {{ error }}
    </text>
    <button
      :loading="busy"
      :disabled="busy"
      @tap="save"
    >
      修改密码并重新登录
    </button>
  </view>
</template>
<style scoped lang="scss">
.page { padding: 36rpx; }.title { display: block; font-size: 36rpx; font-weight: 600; }.hint { display: block; font-size: 25rpx; color: $hg-muted; line-height: 1.7; margin: 20rpx 0 36rpx; }.field { padding: 24rpx 0; border-bottom: 1rpx solid $hg-line; font-size: 26rpx; }input { padding: 22rpx 0; }.error { display: block; color: $hg-crit; margin: 24rpx 0; font-size: 26rpx; }button { margin-top: 40rpx; }
</style>
