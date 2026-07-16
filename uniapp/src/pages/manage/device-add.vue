<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { createProvisionCode, getProvisionStatus } from '@/api/provisioning';
import { toast } from '@/utils/guard';

const location = ref('');
const code = ref('');
const expiresIn = ref(0);
const status = ref<'idle' | 'pending' | 'registered' | 'expired'>('idle');
const deviceName = ref('');
let poller: ReturnType<typeof setInterval> | null = null;
let ticker: ReturnType<typeof setInterval> | null = null;

function stop() {
  if (poller) { clearInterval(poller); poller = null; }
  if (ticker) { clearInterval(ticker); ticker = null; }
}

async function generate() {
  stop();
  try {
    const res = await createProvisionCode(location.value.trim() || undefined);
    code.value = res.provision_code;
    expiresIn.value = res.expires_in;
    status.value = 'pending';

    // 生成即自动复制，方便粘贴到设备配网页
    copyCode(true);

    ticker = setInterval(() => {
      expiresIn.value = Math.max(0, expiresIn.value - 1);
      if (expiresIn.value === 0) { status.value = 'expired'; stop(); }
    }, 1000);

    poller = setInterval(async () => {
      try {
        const s = await getProvisionStatus(code.value);
        if (s.status === 'registered') {
          status.value = 'registered';
          deviceName.value = s.device?.name || '新设备';
          stop();
        } else if (s.status === 'expired') {
          status.value = 'expired';
          stop();
        }
      } catch { /* 忽略单次轮询失败 */ }
    }, 3000);
  } catch (e) {
    toast((e as Error).message);
  }
}

function copyCode(silent = false) {
  if (!code.value) return;
  uni.setClipboardData({
    data: code.value,
    success: () => { if (!silent) toast('已复制', 'success'); },
    fail: () => { if (!silent) toast('复制失败'); },
  });
  // 部分平台 setClipboardData 自带提示；这里统一给一次「已复制」
  if (silent) toast('配对码已复制', 'success');
}

function done() {
  uni.navigateBack();
}

onUnmounted(stop);
</script>

<template>
  <view class="page">
    <view class="steps">
      <text class="step">1. 生成配对码 → 2. 设备通电连 WiFi 时填入 → 3. 自动上线</text>
    </view>

    <view v-if="status === 'idle'" class="card">
      <view class="field">
        <text class="label">预设位置（可选）</text>
        <input v-model="location" class="input" placeholder="如 客厅" placeholder-class="ph" />
      </view>
      <button class="btn btn-primary" @tap="generate">生成配对码</button>
    </view>

    <view v-else class="card center">
      <template v-if="status === 'pending'">
        <text class="code" @tap="copyCode()">{{ code }}</text>
        <text class="hint">在设备配网页填入此码 · {{ expiresIn }}s 后过期</text>
        <button class="btn btn-copy" @tap="copyCode()">复制配对码</button>
        <view class="waiting">
          <text class="spinner-txt">等待设备上线…</text>
        </view>
        <button class="btn btn-ghost" @tap="generate">重新生成</button>
      </template>

      <template v-else-if="status === 'registered'">
        <view class="ok">✓</view>
        <text class="ok-title">{{ deviceName }} 已上线</text>
        <button class="btn btn-primary" @tap="done">完成</button>
      </template>

      <template v-else>
        <text class="expired">配对码已过期</text>
        <button class="btn btn-primary" @tap="generate">重新生成</button>
      </template>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 32rpx;
}
.steps {
  margin-bottom: 24rpx;
}
.step {
  font-size: 24rpx;
  color: $hg-muted;
  line-height: 1.6;
}
.card {
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 32rpx 28rpx;
  box-shadow: $hg-shadow;
}
.card.center {
  text-align: center;
}
.field {
  margin-bottom: 24rpx;
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
  border-bottom: 1rpx solid $hg-line;
  padding-bottom: 12rpx;
}
.ph {
  color: #b6bccb;
}
.code {
  display: block;
  font-size: 72rpx;
  font-weight: 700;
  letter-spacing: 12rpx;
  color: $hg-accent;
  font-family: monospace;
  margin: 20rpx 0;
}
.hint {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
}
.waiting {
  margin: 40rpx 0;
}
.spinner-txt {
  font-size: 26rpx;
  color: $hg-muted;
}
.ok {
  width: 120rpx;
  height: 120rpx;
  margin: 20rpx auto;
  border-radius: 50%;
  background: $hg-ok;
  color: #fff;
  font-size: 64rpx;
  line-height: 120rpx;
}
.ok-title {
  display: block;
  font-size: 32rpx;
  font-weight: 700;
  color: $hg-fg;
  margin-bottom: 30rpx;
}
.expired {
  display: block;
  font-size: 30rpx;
  color: $hg-crit;
  margin: 30rpx 0;
}
.btn {
  border-radius: $hg-radius-s;
  height: 88rpx;
  line-height: 88rpx;
  font-size: 30rpx;
  margin-top: 20rpx;
}
.btn-primary {
  background: $hg-accent;
  color: #fff;
}
.btn-copy {
  background: $hg-accent-soft;
  color: $hg-accent;
}
.btn-ghost {
  background: $hg-card-2;
  color: $hg-accent;
}
</style>
