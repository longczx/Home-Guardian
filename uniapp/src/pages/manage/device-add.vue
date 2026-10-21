<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { provisioningStage } from '@/utils/provisioning-stage';
import { createProvisionCode, getProvisionStatus } from '@/api/provisioning';
import { toast } from '@/utils/guard';

const location = ref('');
const rebindId = ref<number>();
const progress = ref('等待设备注册');
const connectionError = ref('');
const busy = ref(false);
let failures = 0;
let generation = 0;
onLoad((q) => { if (q?.rebind) rebindId.value = Number(q.rebind); });
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
  if (busy.value) return;
  busy.value = true;
  stop();
  const attempt = ++generation;
  const targetId = rebindId.value || registeredId.value;
  registeredId.value = 0;
  try {
    const res = await createProvisionCode(location.value.trim() || undefined, targetId || undefined);
    code.value = res.provision_code;
    expiresIn.value = res.expires_in;
    status.value = 'pending';
    connectionError.value = ''; failures = 0; progress.value = '等待设备注册';

    // 生成即自动复制，方便粘贴到设备配网页
    copyCode(true);

    ticker = setInterval(() => {
      expiresIn.value = Math.max(0, expiresIn.value - 1);
      if (expiresIn.value === 0) { connectionError.value = '等待超时，请检查 WiFi、平台地址和 MQTT 连接。已注册设备可从设备列表查看状态。'; stop(); }
    }, 1000);

    poller = setInterval(async () => {
      try {
        const s = await getProvisionStatus(code.value);
        if (attempt !== generation) return;
        failures = 0; connectionError.value = '';
        registeredId.value = s.device?.id || 0;
        const stage = provisioningStage(s);
        progress.value = stage.label;
        if (stage.ready) {
          status.value = 'registered';
          deviceName.value = s.device?.name || '新设备';
          stop();
        } else if (s.status === 'expired') {
          status.value = 'expired';
          stop();
        }
      } catch { if (attempt === generation && ++failures >= 3) connectionError.value = '连续无法联系服务器，请恢复手机网络后重试查询'; }
    }, 3000);
  } catch (e) {
    toast((e as Error).message);
  } finally { busy.value = false; }
}
function openRegisteredDevice() { if (code.value) uni.redirectTo({ url: '/pages/device/detail?id=' + registeredId.value }); }
const registeredId = ref(0);

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

function configureDevice() { uni.navigateTo({ url: '/pages/manage/device-edit?id=' + registeredId.value }); }
function done() {
  uni.navigateBack();
}

onUnmounted(() => { generation++; stop(); });
</script>

<template>
  <view class="page">
    <view class="steps">
      <text class="step">
        1. 生成配对码 → 2. 连接 HG-Setup 热点，打开 192.168.4.1 填 WiFi 和配对码 → 3. 回到手机原网络，等待设备连接和首报
      </text>
    </view>

    <view
      v-if="status === 'idle'"
      class="card"
    >
      <view class="field">
        <text class="label">
          预设位置（重新配网沿用原位置）
        </text>
        <input
          v-model="location"
          class="input"
          placeholder="如 客厅"
          placeholder-class="ph"
        >
      </view>
      <button
        class="btn btn-primary"
        :loading="busy"
        :disabled="busy"
        @tap="generate"
      >
        {{ rebindId ? '授权原网关重新配网' : '生成配对码' }}
      </button>
    </view>

    <text
      v-if="connectionError"
      class="expired"
    >
      {{ connectionError }}
    </text>
    <button
      v-if="registeredId"
      @tap="openRegisteredDevice"
    >
      查看设备状态
    </button>
    <view
      v-else
      class="hint"
    >
      首次配置后，可在管理 → 设备管理中确认名称和房间。
    </view>
    <view
      v-if="status !== 'idle'"
      class="card center"
    >
      <template v-if="status === 'pending'">
        <text
          class="code"
          @tap="copyCode()"
        >
          {{ code }}
        </text>
        <text class="hint">
          在设备配网页填入此码 · {{ expiresIn }}s 后过期
        </text>
        <button
          class="btn btn-copy"
          @tap="copyCode()"
        >
          复制配对码
        </button>
        <view class="waiting">
          <text class="spinner-txt">
            {{ progress }}…
          </text>
        </view>
        <button
          class="btn btn-ghost"
          @tap="generate"
        >
          重新生成
        </button>
      </template>

      <template v-else-if="status === 'registered'">
        <view class="ok">
          ✓
        </view>
        <text class="ok-title">
          {{ deviceName }} 已上线
        </text>
        <button
          class="btn btn-primary"
          @tap="configureDevice"
        >
          设置名称与房间
        </button>
        <button
          class="btn btn-ghost"
          @tap="done"
        >
          完成
        </button>
      </template>

      <template v-else>
        <text class="expired">
          配对码已过期
        </text>
        <button
          class="btn btn-primary"
          @tap="generate"
        >
          重新生成
        </button>
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
