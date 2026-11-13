<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue';
import { useServerStore } from '@/stores/server';
import { onLoad, onShow, onHide } from '@dcloudio/uni-app';
import { provisioningStage } from '@/utils/provisioning-stage';
import { createProvisionCode, getProvisionStatus } from '@/api/provisioning';
import { toast, ensureReady } from '@/utils/guard';
import { getDevices } from '@/api/device';
import type { Device } from '@/api/types';
import RoomPicker from '@/components/RoomPicker.vue';
import { useAuthStore } from '@/stores/auth';
import { goHome } from '@/utils/navigation';

const server = useServerStore();
const platformUrl = computed(() => server.current?.url || '');
const mode = ref<'choose' | 'gateway' | 'module'>('choose');
const gateways = ref<Device[]>([]);
const gatewaysLoading = ref(false);
const gatewaysError = ref('');
const gatewaysPage = ref(0);
const gatewaysHasMore = ref(false);
let gatewayRequest = 0;
const location = ref('');
const rebindId = ref<number>();
const progress = ref('等待设备注册');
const connectionError = ref('');
const busy = ref(false);
let failures = 0;
let generation = 0;

const code = ref('');
const expiresIn = ref(0);
const step = ref(1); let expiresAt = 0; let checking = false;
const auth = useAuthStore();
const pairingKey = () => `hg_pairing_${server.currentId}_${auth.user?.id || 0}`;
let previousPairingKey = pairingKey();
const status = ref<'idle' | 'pending' | 'registered' | 'expired'>('idle');
const deviceName = ref('');
let poller: ReturnType<typeof setInterval> | null = null;
let ticker: ReturnType<typeof setInterval> | null = null;

function stop() {
  if (poller) { clearInterval(poller); poller = null; }
  if (ticker) { clearInterval(ticker); ticker = null; }
}

async function generate() {
  if (busy.value || mode.value !== 'gateway' || !ensureReady()) return;
  busy.value = true;
  stop();
  const attempt = ++generation;
  const targetId = rebindId.value || registeredId.value;
  registeredId.value = 0;
  try {
    const res = await createProvisionCode(location.value.trim() || undefined, targetId || undefined);
    if (attempt !== generation) return;
    code.value = res.provision_code;
    expiresIn.value = res.expires_in;
    status.value = 'pending';
    connectionError.value = ''; failures = 0; progress.value = '等待设备注册';

    // 生成即自动复制，方便粘贴到设备配网页
    copyCode(true);

    expiresAt = Date.now() + res.expires_in * 1000;
    step.value = 1;
    persistPairing(); startPolling(attempt);

  } catch (e) {
    if (attempt === generation) toast((e as Error).message);
  } finally { if (attempt === generation) busy.value = false; }
}
function persistPairing() {
  if (status.value === 'pending') uni.setStorageSync(pairingKey(), { code: code.value, expiresAt, platform: platformUrl.value, rebind: rebindId.value, step: step.value });
}
async function checkStatus(attempt = generation) {
  if (checking || !code.value || attempt !== generation || status.value !== 'pending') return;
  checking = true;
  try {
    const s = await getProvisionStatus(code.value);
    if (attempt !== generation) return;
    failures = 0; connectionError.value = '';
    registeredId.value = s.device?.id || 0;
    const stage = provisioningStage(s); progress.value = stage.label;
    if (stage.ready) {
      status.value = 'registered'; deviceName.value = s.device?.name || '新设备';
      stop(); uni.removeStorageSync(pairingKey());
    } else if (s.status === 'expired') {
      status.value = 'expired'; stop(); uni.removeStorageSync(pairingKey());
    }
  } catch { if (attempt === generation && ++failures >= 3) connectionError.value = '连接设备热点时无法访问平台属于正常现象；提交配网后切回原网络，再点击检查进度。'; }
  finally { checking = false; }
}
function startPolling(attempt: number) {
  stop();
  const tick = () => {
    expiresIn.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
    if (!expiresIn.value) {
      status.value = 'expired'; stop(); uni.removeStorageSync(pairingKey());
      connectionError.value = '配对码已过期。若已提交配网，可先查看设备列表，确认未加入后再生成新码。';
    }
  };
  tick();
  if (status.value !== 'pending') return;
  ticker = setInterval(tick, 1000);
  poller = setInterval(() => { void checkStatus(attempt); }, 3000);
}
function nextStep(value: number) { step.value = value; persistPairing(); }
function copyPairingNotes() {
  if (!code.value) return;
  uni.setClipboardData({ data: `平台地址：${platformUrl.value}\n配对码：${code.value}\n设备配网页：http://192.168.4.1/`, success: () => toast('已复制整份配网信息，可保存到备忘录'), fail: () => toast('复制失败，请手动记录') });
}
function restorePairing() {
  if (status.value !== 'idle') return;
  const saved = uni.getStorageSync(pairingKey());
  if (saved && saved.expiresAt <= Date.now()) { uni.removeStorageSync(pairingKey()); return; }
  if (!saved || saved.platform !== platformUrl.value || saved.expiresAt <= Date.now() || typeof saved.code !== 'string') return;
  if (rebindId.value && saved.rebind !== rebindId.value) return;
  code.value = saved.code; expiresAt = saved.expiresAt; rebindId.value = saved.rebind || undefined;
  step.value = saved.step || 1; mode.value = 'gateway'; status.value = 'pending';
  startPolling(++generation); void checkStatus();
}
onLoad(q => {
  if (!ensureReady()) return;
  const target = Number(q?.rebind);
  if (Number.isInteger(target) && target > 0) { rebindId.value = target; mode.value = 'gateway'; }
  if (q?.mode === 'module' && !rebindId.value) { void chooseMode('module'); return; }
  restorePairing();
});
onShow(() => {
  if (status.value === 'pending') { startPolling(generation); void checkStatus(); }
});
function openRegisteredDevice() { if (code.value) uni.redirectTo({ url: '/pages/device/detail?id=' + registeredId.value }); }
const registeredId = ref(0);

function copyCode(silent = false) {
  if (!code.value) return;
  uni.setClipboardData({
    data: code.value,
    success: () => toast(silent ? '配对码已复制' : '已复制配对码', 'success'),
    fail: () => toast('复制失败，请手动记录配对码'),
  });
}

function copyPlatformUrl() {
  if (!platformUrl.value) return;
  uni.setClipboardData({
    data: platformUrl.value,
    success: () => toast('平台地址已复制', 'success'),
    fail: () => toast('复制失败'),
  });
}

// 配对码与设备 ID 属于生成它们的服务器，切换时必须一起作废。
watch(() => server.connectionRevision, () => {
  uni.removeStorageSync(previousPairingKey); previousPairingKey = pairingKey();
  generation++; stop(); busy.value = false;
  code.value = ''; expiresIn.value = 0; status.value = 'idle';
  registeredId.value = 0; rebindId.value = undefined; deviceName.value = '';
  mode.value = 'choose';
  gatewayRequest++; gateways.value = []; gatewaysLoading.value = false;
  gatewaysPage.value = 0; gatewaysHasMore.value = false; gatewaysError.value = '';
  connectionError.value = '服务器已切换，请重新选择添加方式';
}, { flush: 'sync' });

function configureDevice() { uni.navigateTo({ url: '/pages/manage/device-edit?id=' + registeredId.value }); }
function configureModules() {
  if (registeredId.value > 0) uni.navigateTo({ url: '/pages/manage/gateway-modules?id=' + registeredId.value });
}
function done() {
  uni.navigateBack({ fail: goHome });
}

async function chooseMode(next: 'gateway' | 'module') {
  if (busy.value || !ensureReady() || rebindId.value) return;
  resetPairing();
  mode.value = next;
  if (next === 'module') await loadGateways();
}
function resetPairing() {
  uni.removeStorageSync(pairingKey()); step.value = 1;
  generation++; stop(); code.value = ''; expiresIn.value = 0;
  status.value = 'idle'; registeredId.value = 0; deviceName.value = ''; connectionError.value = '';
}
function backToChoices() {
  if (busy.value || rebindId.value) return;
  resetPairing(); mode.value = 'choose'; gatewayRequest++;
  gateways.value = []; gatewaysLoading.value = false; gatewaysError.value = '';
  gatewaysPage.value = 0; gatewaysHasMore.value = false;
}
async function loadGateways(more = false) {
  if (mode.value !== 'module' || gatewaysLoading.value || !ensureReady()) return;
  gatewaysLoading.value = true; gatewaysError.value = '';
  const attempt = ++gatewayRequest;
  const page = more ? gatewaysPage.value + 1 : 1;
  try {
    const result = await getDevices({ type: 'gateway', per_page: 100, page });
    if (attempt !== gatewayRequest || mode.value !== 'module') return;
    const list = (result.items || []).filter(d => d.type === 'gateway' && !d.gateway_uid);
    gateways.value = [...new Map((more ? [...gateways.value, ...list] : list).map(d => [d.id, d])).values()];
    gatewaysPage.value = page; gatewaysHasMore.value = page < result.last_page;
  } catch (e) {
    if (attempt === gatewayRequest) gatewaysError.value = (e as Error).message;
  } finally { if (attempt === gatewayRequest) gatewaysLoading.value = false; }
}
function openModules(id: number) {
  if (mode.value !== 'module' || !ensureReady()) return;
  const gateway = gateways.value.find(d => d.id === id);
  if (!gateway) return;
  if (!gateway.is_online) return toast('网关离线，请先恢复联网后添加模块');
  uni.navigateTo({ url: '/pages/manage/gateway-modules?id=' + gateway.id });
}
onHide(stop);
onUnmounted(() => { generation++; gatewayRequest++; stop(); });
</script>

<template>
  <view class="page">
    <template v-if="mode === 'choose'">
      <text class="page-title">
        你要添加什么？
      </text>
      <text class="intro">
        按实际接线方式选择，后续操作不同。
      </text>
      <view class="card choice-card">
        <text class="choice-title">
          新增 ESP32 网关
        </text>
        <text class="hint">
          添加一块尚未加入当前家庭的 ESP32，让它连接家中 WiFi 并注册到当前平台。
        </text>
        <button
          class="btn btn-primary"
          @tap="chooseMode('gateway')"
        >
          开始新网关配网
        </button>
      </view>
      <view class="card choice-card">
        <text class="choice-title">
          给已有网关添加模块
        </text>
        <text class="hint">
          在已联网的 ESP32 上增加温湿度、声音或红外模块，选择模块类型和实际 GPIO。
        </text>
        <text class="note">
          无需配对码，也不用重新配置 WiFi。
        </text>
        <button
          class="btn btn-ghost"
          @tap="chooseMode('module')"
        >
          选择已有网关
        </button>
      </view>
      <text
        v-if="connectionError"
        class="expired"
      >
        {{ connectionError }}
      </text>
    </template>

    <template v-else-if="mode === 'module'">
      <button
        class="back-link"
        @tap="backToChoices"
      >
        返回选择添加方式
      </button>
      <text class="page-title">
        给已有网关添加模块
      </text>
      <text class="intro">
        选择连接模块的那块 ESP32，进入模块管理后添加模块并设置 GPIO。请先断电调整接线，再上电操作。
      </text>
      <text
        v-if="gatewaysError"
        class="expired"
      >
        {{ gatewaysError }}
      </text>
      <text
        v-if="gatewaysLoading"
        class="hint"
      >
        正在读取当前平台的网关…
      </text>
      <view
        v-for="gateway in gateways"
        :key="gateway.id"
        class="card gateway-row"
      >
        <text class="choice-title">
          {{ gateway.name }}
        </text>
        <text class="hint">
          {{ gateway.location || '未设置房间' }} · {{ gateway.device_uid }}
        </text>
        <text class="note">
          {{ gateway.is_online ? '在线，可以配置模块' : '离线，请先恢复网关联网' }}
        </text>
        <button
          class="btn btn-primary"
          :disabled="!gateway.is_online"
          @tap="openModules(gateway.id)"
        >
          进入模块管理
        </button>
      </view>
      <view
        v-if="!gatewaysLoading && !gatewaysError && !gateways.length"
        class="card"
      >
        <text class="choice-title">
          还没有 ESP32 网关
        </text>
        <text class="hint">
          请先将一块 ESP32 配网上线，再为它添加模块。
        </text>
        <button
          class="btn btn-primary"
          @tap="chooseMode('gateway')"
        >
          新增 ESP32 网关
        </button>
      </view>
      <button
        v-if="gatewaysHasMore"
        :loading="gatewaysLoading"
        :disabled="gatewaysLoading"
        @tap="loadGateways(true)"
      >
        加载更多网关
      </button>
      <button
        :disabled="gatewaysLoading"
        @tap="loadGateways()"
      >
        刷新网关列表
      </button>
    </template>

    <template v-else>
      <button
        v-if="!rebindId"
        class="back-link"
        :disabled="busy"
        @tap="backToChoices"
      >
        返回选择添加方式
      </button>
      <text class="page-title">
        {{ rebindId ? '原 ESP32 网关重新配网' : '新增 ESP32 网关' }}
      </text>
      <text class="intro">
        {{ rebindId ? '使用本次授权重新填写 WiFi，保留原网关身份和设备历史。' : '让一块新的 ESP32 连接家中 WiFi，并加入当前家庭。' }}
      </text>

      <view
        v-if="status !== 'registered' && step === 1"
        class="card"
      >
        <text class="section-title">
          1. 获取配对码，授权这块 ESP32 加入家庭
        </text>
        <text class="hint">
          配对码是一份临时授权，在设备配网页的「配对码」栏填写。它有有效期，只能和当前平台配套使用。
        </text>
        <view
          v-if="status === 'idle' && !rebindId"
          class="field"
        >
          <text class="label">
            网关放在哪个房间（可选）
          </text>
          <RoomPicker v-model="location" />
        </view>
        <button
          v-if="status === 'idle'"
          class="btn btn-primary"
          :loading="busy"
          :disabled="busy || !platformUrl"
          @tap="generate"
        >
          {{ rebindId ? '生成重新配网授权码' : '生成配对码' }}
        </button>
        <template v-else-if="status === 'pending'">
          <text
            class="code"
            @tap="copyCode()"
          >
            {{ code }}
          </text>
          <text class="hint">
            配对码有效期剩余 {{ expiresIn }} 秒
          </text>
          <button
            class="btn btn-copy"
            @tap="copyCode()"
          >
            复制配对码
          </button>
          <button
            class="btn btn-ghost"
            :loading="busy"
            :disabled="busy"
            @tap="generate"
          >
            重新生成配对码
          </button>
        </template>
        <template v-else-if="status === 'expired'">
          <text class="expired">
            配对码已过期，请重新生成。
          </text>
          <button
            class="btn btn-primary"
            :loading="busy"
            :disabled="busy"
            @tap="generate"
          >
            重新生成配对码
          </button>
        </template>
      </view>

      <view
        v-if="status !== 'registered' && step === 1"
        class="card"
      >
        <text class="section-title">
          平台地址：与配对码一起填入设备
        </text>
        <text class="hint">
          当前平台：{{ server.current?.name || '未选择' }}。下面是已选平台的地址，复制到设备配网页的「平台地址」栏即可。
        </text>
        <text class="platform-url">
          {{ platformUrl || '请先选择平台' }}
        </text>
        <button
          class="btn btn-copy"
          :disabled="!platformUrl"
          @tap="copyPlatformUrl"
        >
          复制平台地址（填到设备）
        </button>
        <text class="note">
          这里是在给 ESP32 配网，不需要新增服务器。平台地址负责指明连接位置，配对码负责授权加入家庭。
        </text>
      </view>

      <view
        v-if="status !== 'registered' && step >= 2"
        class="card"
      >
        <text class="section-title">
          {{ step === 2 ? '2. 连接设备热点并填写' : '3. 返回原网络，检查结果' }}
        </text>
        <text class="hint">
          先把配对码和平台地址分别记下，再到手机系统 WiFi 设置连接 HG-Setup-xxxx 热点。
        </text>
        <text class="hint">
          浏览器打开 http://192.168.4.1/，填写家中 2.4GHz WiFi、WiFi 密码、配对码及平台地址并提交。
        </text>
        <text class="hint">
          提交后手机切回原网络，再回到本页等待网关上线。
        </text>
        <text
          v-if="status === 'pending'"
          class="waiting"
        >
          {{ progress }}…
        </text>
      </view>
      <view
        v-if="status === 'pending'"
        class="card"
      >
        <text class="hint">
          步骤 {{ step }} / 3 · 临时切换网络后可回到本页继续。
        </text>
        <text
          selectable
          class="hint"
        >
          平台：{{ platformUrl }}
          配对码：{{ code }}
        </text>
        <button @tap="copyPairingNotes">
          复制整份配网信息（便于保存）
        </button>
        <text class="hint">
          整份信息用于备忘；设备表单需分别填写地址和配对码。
        </text>
        <button
          v-if="step < 3"
          @tap="nextStep(step + 1)"
        >
          {{ step === 1 ? '信息已记下，去连接设备热点' : '已提交配网，返回原网络检查' }}
        </button>
        <button
          v-if="step === 3"
          @tap="checkStatus()"
        >
          我已切回原网络，检查进度
        </button>
        <button
          v-if="step > 1"
          @tap="nextStep(step - 1)"
        >
          返回上一步
        </button>
      </view>
      <text
        v-if="connectionError"
        class="expired"
      >
        {{ connectionError }}
      </text>
      <button
        v-if="registeredId && status !== 'registered'"
        @tap="openRegisteredDevice"
      >
        查看已注册网关状态
      </button>
      <view
        v-if="status === 'registered'"
        class="card"
      >
        <text class="choice-title">
          ✓ {{ deviceName }} 已上线
        </text>
        <text class="hint">
          网关已加入家庭，可以设置名称与房间，也可以继续为它添加模块。
        </text>
        <button
          class="btn btn-primary"
          @tap="configureDevice"
        >
          设置名称与房间
        </button>
        <button
          class="btn btn-ghost"
          @tap="configureModules"
        >
          为这台网关添加模块
        </button>
        <button
          class="btn btn-ghost"
          @tap="done"
        >
          完成
        </button>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 32rpx; }
.page-title { display: block; font-size: 38rpx; font-weight: 700; color: $hg-fg; margin: 16rpx 0; }
.intro { display: block; font-size: 26rpx; color: $hg-muted; line-height: 1.7; margin-bottom: 28rpx; }
.card { background: $hg-card; border-radius: $hg-radius; padding: 32rpx 28rpx; box-shadow: $hg-shadow; margin-bottom: 24rpx; }
.choice-title, .section-title { display: block; font-weight: 600; color: $hg-fg; font-size: 30rpx; margin-bottom: 16rpx; }
.hint, .note { display: block; font-size: 25rpx; color: $hg-muted; line-height: 1.7; margin: 12rpx 0; }
.note { color: $hg-accent; font-size: 24rpx; }
.platform-url { display: block; word-break: break-all; font-size: 30rpx; color: $hg-fg; margin: 24rpx 0; }
.field { margin: 24rpx 0; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
.input { font-size: 30rpx; color: $hg-fg; border-bottom: 1rpx solid $hg-line; padding-bottom: 12rpx; }
.ph { color: #b6bccb; }
.code { display: block; font-size: 64rpx; font-weight: 700; letter-spacing: 8rpx; color: $hg-accent; font-family: monospace; margin: 24rpx 0; }
.waiting { display: block; color: $hg-accent; font-size: 26rpx; margin-top: 24rpx; }
.expired { display: block; font-size: 26rpx; color: $hg-crit; margin: 24rpx 0; }
.btn { border-radius: $hg-radius-s; font-size: 28rpx; margin-top: 20rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
.btn-copy { background: $hg-accent-soft; color: $hg-accent; }
.btn-ghost { background: $hg-card-2; color: $hg-accent; }
.back-link { font-size: 24rpx; color: $hg-accent; background: transparent; margin-bottom: 20rpx; }
</style>
