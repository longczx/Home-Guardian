<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';
import { login, toAuthUser } from '@/api/auth';
import { toast } from '@/utils/guard';

const server = useServerStore();
const auth = useAuthStore();
const username = ref('');
const password = ref('');
const loading = ref(false);

onShow(() => {
  server.restore();
  auth.restore();
  if (!server.hasServer) {
    uni.reLaunch({ url: '/pages/server/list' });
  }
});

async function onLogin() {
  if (!username.value || !password.value) {
    toast('请输入用户名和密码');
    return;
  }
  loading.value = true;
  try {
    const res = await login(username.value.trim(), password.value);
    auth.setSession(res.access_token, res.refresh_token, toAuthUser(res.user));
    toast('登录成功', 'success');
    uni.reLaunch({ url: '/pages/index/index' });
  } catch (e) {
    toast((e as Error).message || '登录失败');
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view class="brand">
      <view class="logo">HG</view>
      <text class="app">Home Guardian</text>
      <text class="srv">{{ server.current?.name || server.current?.url }}</text>
    </view>

    <view class="card">
      <view class="field">
        <input v-model="username" class="input" placeholder="用户名" placeholder-class="ph" />
      </view>
      <view class="field">
        <input
          v-model="password"
          class="input"
          password
          placeholder="密码"
          placeholder-class="ph"
          @confirm="onLogin"
        />
      </view>
    </view>

    <button class="btn btn-primary" :loading="loading" @tap="onLogin">登 录</button>

    <view class="links">
      <text class="link" @tap="uni.navigateTo({ url: '/pages/auth/register' })">有邀请码？立即注册</text>
      <text class="link" @tap="uni.reLaunch({ url: '/pages/server/list' })">切换服务器</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 100rpx 48rpx 0;
}
.brand {
  text-align: center;
  margin-bottom: 70rpx;
}
.logo {
  width: 120rpx;
  height: 120rpx;
  margin: 0 auto 24rpx;
  border-radius: 30rpx;
  background: linear-gradient(135deg, $hg-accent, #7fa9f0);
  color: #fff;
  font-size: 48rpx;
  font-weight: 700;
  line-height: 120rpx;
}
.app {
  display: block;
  font-size: 40rpx;
  font-weight: 700;
  color: $hg-fg;
}
.srv {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: $hg-muted;
}
.card {
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 0 28rpx;
  box-shadow: $hg-shadow;
}
.field {
  padding: 30rpx 0;
  border-bottom: 1rpx solid $hg-line;
}
.field:last-child {
  border-bottom: none;
}
.input {
  font-size: 30rpx;
  color: $hg-fg;
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
  display: flex;
  justify-content: space-between;
  margin-top: 40rpx;
}
.link {
  font-size: 26rpx;
  color: $hg-accent;
}
</style>
