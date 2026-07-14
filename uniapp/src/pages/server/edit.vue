<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useServerStore } from '@/stores/server';
import { toast } from '@/utils/guard';

const { t } = useI18n();
const server = useServerStore();
const name = ref('');
const url = ref('');

function save() {
  const u = url.value.trim();
  if (!/^https?:\/\/.+/i.test(u)) {
    toast(t('server.urlPlaceholder'));
    return;
  }
  server.upsert(name.value.trim() || u, u);
  toast(t('common.save'), 'success');
  uni.reLaunch({ url: '/pages/auth/login' });
}
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="field">
        <text class="label">{{ t('server.name') }}</text>
        <input v-model="name" class="input" :placeholder="t('server.namePh')" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">{{ t('server.url') }}</text>
        <input
          v-model="url"
          class="input"
          :placeholder="t('server.urlPlaceholder')"
          placeholder-class="ph"
        />
      </view>
      <text class="hint">{{ t('server.urlHint') }}</text>
    </view>
    <button class="btn btn-primary" @tap="save">{{ t('server.saveLogin') }}</button>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 40rpx 32rpx;
}
.card {
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 12rpx 28rpx;
  box-shadow: $hg-shadow;
}
.field {
  padding: 28rpx 0;
  border-bottom: 1rpx solid $hg-line;
}
.field:last-of-type {
  border-bottom: none;
}
.label {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-bottom: 12rpx;
}
.input {
  font-size: 30rpx;
  color: $hg-fg;
}
.ph {
  color: #b6bccb;
}
.hint {
  display: block;
  padding: 0 0 24rpx;
  font-size: 22rpx;
  color: $hg-muted;
}
.btn {
  margin-top: 48rpx;
  border-radius: $hg-radius-s;
  height: 88rpx;
  line-height: 88rpx;
  font-size: 30rpx;
}
.btn-primary {
  background: $hg-accent;
  color: #fff;
}
</style>
