<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useServerStore } from '@/stores/server';
import { toast } from '@/utils/guard';

const { t } = useI18n();
const server = useServerStore();
const name = ref('');
const url = ref('');
const testing = ref(false);
const tested = ref<'' | 'ok' | 'fail'>('');

function normalizedUrl(): string {
  return url.value.trim().replace(/\/+$/, '').replace(/\/api$/, '');
}

/** ping /api/health，返回是否可达 */
function ping(u: string): Promise<{ ok: boolean; msg: string }> {
  return new Promise((resolve) => {
    uni.request({
      url: `${u}/api/health`,
      method: 'GET',
      timeout: 8000,
      success: (res) => {
        const body = res.data as { code?: number } | undefined;
        if (res.statusCode === 200 && body?.code === 0) resolve({ ok: true, msg: '' });
        else resolve({ ok: false, msg: `服务器返回异常 (${res.statusCode})` });
      },
      fail: (e) => resolve({ ok: false, msg: (e as { errMsg?: string }).errMsg || '无法连接' }),
    });
  });
}

async function test(): Promise<boolean> {
  const u = normalizedUrl();
  if (!/^https?:\/\/.+/i.test(u)) {
    toast('请输入完整地址，如 ' + t('server.urlPlaceholder'));
    return false;
  }
  testing.value = true;
  tested.value = '';
  const r = await ping(u);
  testing.value = false;
  tested.value = r.ok ? 'ok' : 'fail';
  toast(r.ok ? '✓ 服务器可连接' : `连不上：${r.msg}`, r.ok ? 'success' : 'none');
  return r.ok;
}

async function save() {
  // 保存前必须连通，拦住漏端口 / http-https 写错 / 多写 /api 等
  const ok = await test();
  if (!ok) return;
  const u = normalizedUrl();
  server.upsert(name.value.trim() || u, u);
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
      <view v-if="tested" class="status" :class="tested">
        {{ tested === 'ok' ? '✓ 已连接' : '✕ 连不上，请检查地址/端口/网络' }}
      </view>
    </view>
    <button class="btn btn-ghost" :loading="testing" @tap="test">测试连接</button>
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
  padding: 0 0 16rpx;
  font-size: 22rpx;
  color: $hg-muted;
}
.status {
  padding: 0 0 20rpx;
  font-size: 24rpx;
}
.status.ok { color: $hg-ok; }
.status.fail { color: $hg-crit; }
.btn {
  margin-top: 24rpx;
  border-radius: $hg-radius-s;
  height: 88rpx;
  line-height: 88rpx;
  font-size: 30rpx;
}
.btn-ghost {
  background: $hg-card;
  color: $hg-accent;
  border: 1rpx solid $hg-line;
}
.btn-primary {
  background: $hg-accent;
  color: #fff;
}
</style>
