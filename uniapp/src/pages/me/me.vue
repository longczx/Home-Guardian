<script setup lang="ts">
import { computed } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import PageHeader from '@/components/PageHeader.vue';
import { useAuthStore } from '@/stores/auth';
import { useServerStore } from '@/stores/server';
import { ensureReady, toast } from '@/utils/guard';

const auth = useAuthStore();
const server = useServerStore();

onShow(() => {
  ensureReady();
});

const roleLabel = computed(() => {
  const r = auth.user?.home_role;
  return r === 'owner' ? '户主' : r === 'admin' ? '管理员' : '成员';
});

function goServers() {
  uni.navigateTo({ url: '/pages/server/list' });
}

function todo() {
  toast('该功能将在后续版本提供');
}

function onLogout() {
  uni.showModal({
    title: '退出登录',
    content: '确定退出当前账号？',
    success: (r) => {
      if (r.confirm) {
        auth.logout();
        uni.reLaunch({ url: '/pages/auth/login' });
      }
    },
  });
}
</script>

<template>
  <view class="page">
    <PageHeader title="我的" />

    <view class="profile">
      <view class="avatar">{{ (auth.user?.username || 'U').slice(0, 1).toUpperCase() }}</view>
      <view class="pinfo">
        <text class="pname">{{ auth.user?.username || '未登录' }}</text>
        <text class="prole">{{ roleLabel }}</text>
      </view>
    </view>

    <view class="server-card" @tap="goServers">
      <view class="sc-dot" />
      <view class="sc-main">
        <text class="sc-name">{{ server.current?.name || '未选择服务器' }}</text>
        <text class="sc-url">{{ server.current?.url }}</text>
      </view>
      <text class="sc-switch">切换 ›</text>
    </view>

    <view class="menu">
      <view v-if="auth.canManage" class="item" @tap="todo">
        <text class="t">邀请家人</text><text class="arrow">›</text>
      </view>
      <view class="item" @tap="todo">
        <text class="t">推送设置</text><text class="arrow">›</text>
      </view>
      <view class="item" @tap="todo">
        <text class="t">修改密码</text><text class="arrow">›</text>
      </view>
      <view class="item" @tap="todo">
        <text class="t">关于</text><text class="v">Home Guardian v1.0.0</text>
      </view>
    </view>

    <button class="logout" @tap="onLogout">退出登录</button>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding-bottom: 40rpx;
}
.profile {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin: 8rpx 32rpx 24rpx;
  padding: 32rpx 28rpx;
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
}
.avatar {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, $hg-accent, #7fa9f0);
  color: #fff;
  font-size: 40rpx;
  font-weight: 700;
  text-align: center;
  line-height: 96rpx;
}
.pname {
  font-size: 32rpx;
  font-weight: 700;
  color: $hg-fg;
}
.prole {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-top: 6rpx;
}
.server-card {
  display: flex;
  align-items: center;
  margin: 0 32rpx 24rpx;
  padding: 28rpx 26rpx;
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
}
.sc-dot {
  width: 16rpx;
  height: 16rpx;
  border-radius: 50%;
  background: $hg-ok;
  margin-right: 20rpx;
}
.sc-main {
  flex: 1;
  min-width: 0;
}
.sc-name {
  font-size: 28rpx;
  font-weight: 600;
  color: $hg-fg;
}
.sc-url {
  display: block;
  font-size: 22rpx;
  color: $hg-muted;
  margin-top: 4rpx;
}
.sc-switch {
  font-size: 24rpx;
  color: $hg-accent;
}
.menu {
  margin: 0 32rpx;
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
  overflow: hidden;
}
.item {
  display: flex;
  align-items: center;
  padding: 30rpx 28rpx;
  border-bottom: 1rpx solid $hg-line;
}
.item:last-child {
  border-bottom: none;
}
.t {
  flex: 1;
  font-size: 30rpx;
  color: $hg-fg;
}
.v {
  font-size: 24rpx;
  color: $hg-muted;
}
.arrow {
  color: $hg-muted;
  font-size: 36rpx;
}
.logout {
  margin: 48rpx 32rpx 0;
  background: $hg-card;
  color: $hg-crit;
  border-radius: $hg-radius-s;
  height: 88rpx;
  line-height: 88rpx;
  font-size: 30rpx;
}
</style>
