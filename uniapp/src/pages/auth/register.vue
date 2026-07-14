<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { onLoad } from '@dcloudio/uni-app';
import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';
import { register, toAuthUser } from '@/api/auth';
import { registerPush } from '@/utils/push';
import { toast } from '@/utils/guard';

const { t } = useI18n();
const server = useServerStore();
const auth = useAuthStore();
const form = ref({ invite_code: '', username: '', password: '', full_name: '' });
const loading = ref(false);

// 扫邀请码进来时预填
onLoad((q) => {
  if (q?.code) form.value.invite_code = String(q.code).toUpperCase();
});

async function onRegister() {
  const { invite_code, username, password } = form.value;
  if (!invite_code || !username || !password) {
    toast('邀请码、用户名、密码为必填');
    return;
  }
  if (password.length < 6) {
    toast('密码长度不能少于 6 位');
    return;
  }
  loading.value = true;
  try {
    const res = await register({
      invite_code: invite_code.trim().toUpperCase(),
      username: username.trim(),
      password,
      full_name: form.value.full_name.trim() || undefined,
    });
    // 注册即登录：后端直接返回 token
    auth.setSession(res.access_token, res.refresh_token, toAuthUser(res.user));
    registerPush();
    toast(t('auth.registerSuccess'), 'success');
    uni.reLaunch({ url: '/pages/index/index' });
  } catch (e) {
    toast((e as Error).message || '注册失败');
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view class="tip">
      <text>{{ t('auth.inviteTip', { name: server.current?.name || t('me.noServer') }) }}</text>
    </view>

    <view class="card">
      <view class="field">
        <text class="label">{{ t('auth.inviteCode') }}</text>
        <input v-model="form.invite_code" class="input code" placeholder="ABCD2345" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">{{ t('auth.username') }}</text>
        <input v-model="form.username" class="input" :placeholder="t('auth.usernamePh')" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">{{ t('auth.password') }}</text>
        <input v-model="form.password" class="input" password :placeholder="t('auth.passwordPh')" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">{{ t('auth.nickname') }}</text>
        <input v-model="form.full_name" class="input" :placeholder="t('auth.nicknamePh')" placeholder-class="ph" />
      </view>
    </view>

    <button class="btn btn-primary" :loading="loading" @tap="onRegister">{{ t('auth.registerBtn') }}</button>
    <view class="links">
      <text class="link" @tap="uni.navigateBack()">{{ t('auth.toLogin') }}</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 40rpx 48rpx 0;
}
.tip {
  font-size: 26rpx;
  color: $hg-muted;
  line-height: 1.6;
  margin-bottom: 30rpx;
}
.card {
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 0 28rpx;
  box-shadow: $hg-shadow;
}
.field {
  padding: 26rpx 0;
  border-bottom: 1rpx solid $hg-line;
}
.field:last-child {
  border-bottom: none;
}
.label {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-bottom: 10rpx;
}
.input {
  font-size: 30rpx;
  color: $hg-fg;
}
.code {
  letter-spacing: 4rpx;
  text-transform: uppercase;
}
.ph {
  color: #b6bccb;
}
.btn {
  margin-top: 50rpx;
  border-radius: $hg-radius-s;
  height: 90rpx;
  line-height: 90rpx;
  font-size: 32rpx;
}
.btn-primary {
  background: $hg-accent;
  color: #fff;
}
.links {
  text-align: center;
  margin-top: 36rpx;
}
.link {
  font-size: 26rpx;
  color: $hg-accent;
}
</style>
