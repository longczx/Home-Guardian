<script setup lang="ts">
import { ref, watch } from 'vue';
import { switchValue } from '@/utils/events';
import { onShow, onHide, onUnload } from '@dcloudio/uni-app';
import { getHomeOverview, setHomeMode, modes, type HomeMode } from '@/api/experience';
import { getAutomations, type Automation } from '@/api/automation';
import { getDevices } from '@/api/device';
import type { Device } from '@/api/types';
import { getScenes, executeScene, deleteScene, getSceneRuns, modeScene, describeAction, type Scene, type SceneRun } from '@/api/scenes';
import { statusLabel } from '@/api/operations';
import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';
import { ensureReady, toast } from '@/utils/guard';
const current = ref<HomeMode>('home'); const rules = ref<Automation[]>([]); const scenes = ref<Scene[]>([]); const devices = ref<Device[]>([]); const history = ref<SceneRun[]>([]);
const busy = ref(false); const loading = ref(false); const error = ref(''); const runBound = ref(true); const run = ref<SceneRun | null>(null);
const server = useServerStore(); const auth = useAuthStore(); let generation = 0; let poller: ReturnType<typeof setInterval> | undefined;
const storageKey = () => `hg_scene_pending_${server.currentId}_${auth.user?.id}`;
const pending = ref<{ id: number; key: string; mode?: HomeMode; runId?: number } | null>(null);
function stop() { if (poller) clearInterval(poller); poller = undefined; }
function applies(rule: Automation, mode: HomeMode) { const allowed = rule.trigger_config.modes as string[] | undefined; return !allowed?.length || allowed.includes(mode); }
async function load() {
  if (!ensureReady()) return; const attempt = ++generation; loading.value = true; error.value = '';
  try {
    const [overview, page, list, first, recent] = await Promise.all([getHomeOverview(), getAutomations({ per_page: 100 }), getScenes(), getDevices({ per_page: 100 }), getSceneRuns()]);
    const allRules = page.items; const allDevices = first.items;
    for (let p = 2; p <= page.last_page; p++) allRules.push(...(await getAutomations({ per_page: 100, page: p })).items);
    for (let p = 2; p <= first.last_page; p++) allDevices.push(...(await getDevices({ per_page: 100, page: p })).items);
    if (attempt !== generation) return;
    current.value = overview.mode; rules.value = allRules; scenes.value = list.items; devices.value = allDevices; history.value = recent.items;
    pending.value = uni.getStorageSync(storageKey()) || null;
    if (pending.value?.runId) { await refreshRun(); startPoll(); }
  } catch (e) { if (attempt === generation) error.value = (e as Error).message; }
  finally { if (attempt === generation) loading.value = false; }
}
function accept(result: SceneRun) {
  run.value = result;
  history.value = [result, ...history.value.filter(item => item.id !== result.id)].slice(0, 20);
  if (pending.value) { pending.value.runId = result.id; uni.setStorageSync(storageKey(), pending.value); }
  if (result.status !== 'running') { stop(); uni.removeStorageSync(storageKey()); pending.value = null; }
}
async function refreshRun() {
  if (!pending.value?.runId || busy.value) return;
    try { const result = (await getSceneRuns(pending.value.runId, pending.value.key)).items[0]; if (result) accept(result); else { stop(); error.value = '本次执行记录不存在，请先核对设备实际状态，再清除本地记录'; } }
  catch (e) { error.value = (e as Error).message; }
}
function startPoll() { stop(); if (pending.value?.runId) poller = setInterval(() => { void refreshRun(); }, 2500); }
async function submit() {
  if (!pending.value || busy.value) return; busy.value = true; error.value = '';
  try {
    const context = pending.value;
    const result = context.mode ? (await modeScene(context.mode, context.key)).scene_run : await executeScene(context.id, context.key);
    if (context.mode) current.value = context.mode;
    accept(result); startPoll();
  } catch (e) { error.value = (e as Error).message + '；重试会沿用本次执行标识'; }
  finally { busy.value = false; }
}
function begin(scene: Scene, mode?: HomeMode) {
  if (busy.value || pending.value) return;
  const lines = scene.actions.map(a => describeAction(a, devices.value)).join('；');
  const revision = server.connectionRevision;
  uni.showModal({ title: mode ? `切换模式并执行「${scene.name}」？` : `执行「${scene.name}」？`, content: `${lines}。逐台等待设备确认；红外实际响应需人工核对。相关设备自动化暂停 30 分钟。`, success: r => {
    if (!r.confirm || revision !== server.connectionRevision) return;
    pending.value = { id: scene.id, key: 'scene_' + Date.now() + '_' + Math.random().toString(36).slice(2), mode };
    uni.setStorageSync(storageKey(), pending.value); void submit();
  } });
}
function change(mode: HomeMode) {
  if (busy.value || pending.value || mode === current.value) return;
  const scene = scenes.value.find(s => s.enter_mode === mode);
  if (runBound.value && scene) { begin(scene, mode); return; }
  const revision = server.connectionRevision;
  uni.showModal({ title: '仅切换为' + modes.find(m => m.value === mode)?.label, content: '此次只改变后续规则的适用模式，不发送设备指令。', success: async r => {
    if (!r.confirm || revision !== server.connectionRevision) return; busy.value = true;
    try { await setHomeMode(mode); current.value = mode; toast('规则适用模式已切换', 'success'); } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
  } });
}
function edit(scene?: Scene) { uni.navigateTo({ url: '/pages/manage/scene-edit' + (scene ? '?id=' + scene.id : '') }); }
function remove(scene: Scene) {
  const revision = server.connectionRevision;
  uni.showModal({ title: '删除场景？', content: '仅删除配置及模式绑定，保留执行记录。', success: async r => { if (!r.confirm || revision !== server.connectionRevision) return; try { await deleteScene(scene.id); await load(); } catch (e) { error.value = (e as Error).message; } } });
}
function forget() { uni.showModal({ title: '清除本地待确认记录？', content: '这不会撤回已经提交的设备指令。请先核对设备实际状态。', success: r => { if (r.confirm) { stop(); pending.value = null; uni.removeStorageSync(storageKey()); } } }); }
watch(() => server.connectionRevision, () => { generation++; stop(); pending.value = null; run.value = null; scenes.value = []; devices.value = []; rules.value = []; history.value = []; error.value = '服务器已切换，请返回重新进入'; });
onShow(load); onHide(stop); onUnload(() => { generation++; stop(); });
</script>
<template>
  <view class="page">
    <text class="title">
      家庭模式
    </text><text class="hint">
      模式决定后续哪些自动化规则适用；一键场景用于立即执行设备动作。
    </text>
    <view class="setting">
      <text>切换模式时执行已绑定场景</text><switch
        :checked="runBound"
        @change="runBound = switchValue($event)"
      />
    </view>
    <view class="modes">
      <button
        v-for="m in modes"
        :key="m.value"
        :class="{ active: current === m.value }"
        :disabled="loading || busy || !!pending"
        @tap="change(m.value)"
      >
        {{ m.label }}{{ current === m.value ? ' ✓' : '' }}
      </button>
    </view>
    <text
      v-for="m in modes"
      :key="m.value"
      class="hint"
    >
      {{ m.label }}：{{ scenes.find(s => s.enter_mode === m.value)?.name || '未绑定进入场景，仅切换规则范围' }}
    </text>
    <text
      v-if="error"
      class="error"
    >
      {{ error }}
    </text>
    <view
      v-if="pending"
      class="section"
    >
      <text>上次执行尚待确认</text><button
        :disabled="busy"
        @tap="pending?.runId ? refreshRun() : submit()"
      >
        {{ pending.runId ? '刷新执行结果' : '恢复上次提交（沿用原标识）' }}
      </button><text
        class="hint"
        @tap="forget"
      >
        仅清除本地记录
      </text>
    </view>
    <view
      v-if="run"
      class="section"
    >
      <text class="title">
        {{ run.scene_name }} · {{ statusLabel(run.status) }}
      </text><text
        v-for="result in run.action_results"
        :key="result.index"
        class="result"
      >
        {{ result.device_name || '设备 #' + result.device_id }}：{{ result.infrared_unverified && result.status === 'success' ? '红外已发送，实际响应待核对' : statusLabel(result.status) }}{{ result.error ? ' · ' + result.error : '' }}
      </text><text class="hint">
        动作独立执行，部分失败不会撤回其他设备已执行的动作。
      </text>
    </view>
    <view class="heading">
      <text class="title">
        一键场景
      </text><button
        size="mini"
        :disabled="!!pending"
        @tap="edit()"
      >
        新增场景
      </button>
    </view>
    <text
      v-if="!scenes.length && !loading"
      class="hint"
    >
      先创建一个场景，例如「离家关空调」，再选择是否绑定离家模式。
    </text>
    <view
      v-for="scene in scenes"
      :key="scene.id"
      class="section"
    >
      <text class="title">
        {{ scene.name }}
      </text><text class="hint">
        {{ scene.enter_mode ? '进入' + modes.find(m => m.value === scene.enter_mode)?.label + '时可执行' : '仅手动执行' }}
      </text><text
        v-for="(action, i) in scene.actions"
        :key="i"
        class="result"
      >
        {{ describeAction(action, devices) }}
      </text><view class="buttons">
        <button
          size="mini"
          :disabled="busy || !!pending"
          @tap="begin(scene)"
        >
          执行场景
        </button><button
          size="mini"
          :disabled="!!pending"
          @tap="edit(scene)"
        >
          编辑
        </button><button
          size="mini"
          :disabled="!!pending"
          @tap="remove(scene)"
        >
          删除
        </button>
      </view>
    </view>
    <view class="heading">
      <text class="title">
        最近 20 次场景执行
      </text><button
        size="mini"
        :disabled="loading"
        @tap="load"
      >
        刷新
      </button>
    </view>
    <text
      v-if="!history.length"
      class="hint"
    >
      还没有执行记录。
    </text>
    <view
      v-for="item in history"
      :key="item.id"
      class="section"
      @tap="run = item"
    >
      <text>{{ item.scene_name }} · {{ statusLabel(item.status) }}</text><text class="hint">
        {{ new Date(item.started_at).toLocaleString() }} · 点击查看逐项结果
      </text>
    </view>
    <text class="title">
      规则适用范围
    </text><text
      v-for="rule in rules"
      :key="rule.id"
      class="hint"
    >
      {{ rule.name }} · {{ rule.is_enabled ? '已启用' : '已停用' }} · {{ modes.filter(m => applies(rule, m.value)).map(m => m.label).join('、') }}
    </text>
    <button
      v-if="error"
      @tap="load"
    >
      重新加载
    </button>
  </view>
</template>
<style scoped lang="scss">.page { padding: 28rpx; }.title { font-size: 30rpx; font-weight: 600; }.hint { display: block; color: $hg-muted; font-size: 24rpx; line-height: 1.8; margin: 18rpx 0; }.setting,.heading { display: flex; align-items: center; justify-content: space-between; font-size: 26rpx; margin: 28rpx 0; }.modes { display: flex; gap: 12rpx; }.modes button { flex: 1; font-size: 28rpx; }.active { color: $hg-accent; }.section { background: $hg-card; padding: 28rpx; margin: 24rpx 0; border-radius: 20rpx; }.result { display: block; font-size: 25rpx; margin: 14rpx 0; }.buttons { display: flex; gap: 8rpx; margin-top: 22rpx; }.error { display: block; color: $hg-crit; }</style>
