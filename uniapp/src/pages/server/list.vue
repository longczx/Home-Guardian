<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';
import { parseServerQr, parseInviteQr } from '@/utils/qrcode';
import { toast } from '@/utils/guard';

const server = useServerStore();
const auth = useAuthStore();
const servers = ref(server.servers);

onShow(() => {
  server.restore();
  servers.value = server.servers;
});

function afterPick(id: string) {
  server.switchTo(id);
  // 该服务器已登录直接进首页，否则去登录
  if (auth.isLoggedIn) {
    uni.reLaunch({ url: '/pages/index/index' });
  } else {
    uni.reLaunch({ url: '/pages/auth/login' });
  }
}

function onScan() {
  // #ifdef H5
  toast('H5 端请使用手动添加');
  goManual();
  return;
  // #endif
  // eslint-disable-next-line no-unreachable
  uni.scanCode({
    success: (r) => {
      // 邀请二维码：带服务器地址 + 邀请码，扫码即配服务器并进注册页
      const invite = parseInviteQr(r.result);
      if (invite) {
        if (invite.url) server.upsert(invite.name || invite.url, invite.url);
        uni.navigateTo({ url: `/pages/auth/register?code=${invite.code}` });
        return;
      }
      const parsed = parseServerQr(r.result);
      if (!parsed) {
        toast('二维码格式无法识别');
        return;
      }
      const id = server.upsert(parsed.name || parsed.url, parsed.url);
      afterPick(id);
    },
    fail: () => toast('已取消扫码'),
  });
}

function goManual() {
  uni.navigateTo({ url: '/pages/server/edit' });
}

function onSwitch(id: string) {
  afterPick(id);
}

function onRemove(id: string) {
  uni.showModal({
    title: '删除服务器',
    content: '确定移除该服务器配置？本地登录态一并清除。',
    success: (r) => {
      if (r.confirm) {
        server.remove(id);
        servers.value = server.servers;
      }
    },
  });
}
</script>

<template>
  <view class="page">
    <view class="head">
      <text class="title">选择服务器</text>
      <text class="sub">Home Guardian 支持连接多台自托管服务器</text>
    </view>

    <view v-if="servers.length" class="list">
      <view v-for="s in servers" :key="s.id" class="srv" @tap="onSwitch(s.id)">
        <view class="srv-dot" :class="{ on: s.id === server.currentId }" />
        <view class="srv-main">
          <text class="srv-name">{{ s.name }}</text>
          <text class="srv-url">{{ s.url }}</text>
        </view>
        <text v-if="s.id === server.currentId" class="srv-cur">当前</text>
        <text class="srv-del" @tap.stop="onRemove(s.id)">删除</text>
      </view>
    </view>

    <view v-else class="empty">
      <text>还没有服务器，先添加一个</text>
    </view>

    <view class="actions">
      <button class="btn btn-primary" @tap="onScan">扫码添加</button>
      <button class="btn btn-ghost" @tap="goManual">手动添加</button>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 60rpx 32rpx;
}
.head {
  margin-bottom: 40rpx;
}
.title {
  display: block;
  font-size: 44rpx;
  font-weight: 700;
  color: $hg-fg;
}
.sub {
  display: block;
  margin-top: 10rpx;
  font-size: 26rpx;
  color: $hg-muted;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.srv {
  display: flex;
  align-items: center;
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 28rpx 24rpx;
  box-shadow: $hg-shadow;
}
.srv-dot {
  width: 16rpx;
  height: 16rpx;
  border-radius: 50%;
  background: $hg-line;
  margin-right: 20rpx;
  flex: none;
}
.srv-dot.on {
  background: $hg-ok;
}
.srv-main {
  flex: 1;
  min-width: 0;
}
.srv-name {
  display: block;
  font-size: 30rpx;
  font-weight: 600;
  color: $hg-fg;
}
.srv-url {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-top: 4rpx;
}
.srv-cur {
  font-size: 22rpx;
  color: $hg-ok;
  border: 1rpx solid $hg-ok;
  border-radius: 999rpx;
  padding: 4rpx 14rpx;
  margin-right: 16rpx;
}
.srv-del {
  font-size: 24rpx;
  color: $hg-crit;
}
.empty {
  padding: 80rpx 0;
  text-align: center;
  color: $hg-muted;
  font-size: 28rpx;
}
.actions {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
  margin-top: 60rpx;
}
.btn {
  border-radius: $hg-radius-s;
  font-size: 30rpx;
  height: 88rpx;
  line-height: 88rpx;
}
.btn-primary {
  background: $hg-accent;
  color: #fff;
}
.btn-ghost {
  background: $hg-card;
  color: $hg-accent;
  border: 1rpx solid $hg-line;
}
</style>
