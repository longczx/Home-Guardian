<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { getDevices, getDevice } from '@/api/device';
import { modes, type HomeMode } from '@/api/experience';
import { getScenes, saveScene, type SceneAction, describeAction } from '@/api/scenes';
import type { Device } from '@/api/types';
import ActionParameters from '@/components/ActionParameters.vue';
import { ensureReady, toast } from '@/utils/guard';
import { useServerStore } from '@/stores/server';
const id = ref(0); const name = ref(''); const mode = ref<HomeMode | null>(null); const actions = ref<SceneAction[]>([]);
const devices = ref<Device[]>([]); const loading = ref(true); const saving = ref(false); const error = ref('');
const server = useServerStore(); let revision = server.connectionRevision;
const choices = computed(() => devices.value.filter(d => d.type !== 'gateway' && d.capability?.controls.length));
function commands(action: SceneAction) { return [...new Set(devices.value.find(d => d.id === action.device_id)?.capability?.controls.map(c => c.command) || [])]; }
function controls(action: SceneAction) { return devices.value.find(d => d.id === action.device_id)?.capability?.controls.filter(c => c.command === action.action && !['protocol','model'].includes(c.param)) || []; }
function seed(action: SceneAction) {
  const device = devices.value.find(d => d.id === action.device_id);
  action.params = Object.fromEntries(controls(action).map(c => [c.param, device?.state?.[c.state_key || c.key] ?? c.default ?? (c.value_type === 'bool' ? false : c.value_type === 'enum' ? c.options?.[0]?.value : c.min ?? 0)]));
}
async function chooseDevice(index: number, event: { detail: { value: number | string } }) {
  const d = choices.value[Number(event.detail.value)]; if (!d) return;
  try {
    const detail = await getDevice(d.id); if (revision !== server.connectionRevision) return;
    const existing = devices.value.findIndex(d => d.id === detail.id); devices.value[existing] = detail;
    actions.value[index] = { device_id: d.id, action: detail.capability?.controls[0]?.command || '', params: {} }; seed(actions.value[index]);
  } catch (e) { toast((e as Error).message); }
}
function chooseCommand(action: SceneAction, event: { detail: { value: number | string } }) { action.action = commands(action)[Number(event.detail.value)] || ''; seed(action); }
function add() { if (actions.value.length < 16) actions.value.push({ device_id: 0, action: '', params: {} }); }
async function save() {
  if (saving.value || loading.value || revision !== server.connectionRevision) return;
  if (!name.value.trim() || !actions.value.length || actions.value.some(a => !a.device_id || !a.action || !Object.keys(a.params).length)) return toast('请填写名称，并为每个动作选择设备和控制值');
  if (new Set(actions.value.map(a => a.device_id)).size !== actions.value.length) return toast('同一设备请合并为一个动作');
  saving.value = true; error.value = '';
  try { await saveScene({ id: id.value || undefined, name: name.value.trim(), enter_mode: mode.value, actions: actions.value }); toast('场景已保存，尚未执行', 'success'); uni.navigateBack(); }
  catch (e) { error.value = (e as Error).message; } finally { saving.value = false; }
}
watch(() => server.connectionRevision, () => { loading.value = true; error.value = '服务器已切换，请返回重新进入'; actions.value = []; devices.value = []; });
onLoad(async q => {
  if (!ensureReady()) return; id.value = Number(q?.id || 0); revision = server.connectionRevision;
  try {
    const first = await getDevices({ per_page: 100 }); devices.value = first.items;
    for (let page = 2; page <= first.last_page; page++) devices.value.push(...(await getDevices({ per_page: 100, page })).items);
    if (id.value) { const scene = (await getScenes()).items.find(s => s.id === id.value); if (!scene) throw new Error('场景已删除'); name.value = scene.name; mode.value = scene.enter_mode; actions.value = scene.actions; }
    else add();
  } catch (e) { error.value = (e as Error).message; } finally { if (revision === server.connectionRevision) loading.value = false; }
});
</script>
<template>
  <view class="page">
    <text class="intro">
      保存只修改配置，点击「执行场景」才发送指令。红外协议及型号沿用设备设置。
    </text>
    <text
      v-if="error"
      class="error"
    >
      {{ error }}
    </text>
    <view class="section">
      <text>场景名称</text><input
        v-model="name"
        :disabled="loading || saving"
        placeholder="如 离家关闭所有设备"
        :maxlength="100"
      >
      <text>进入家庭模式时执行（可选）</text><picker
        :range="['不绑定，仅手动执行', ...modes.map(m => m.label)]"
        :value="mode ? modes.findIndex(m => m.value === mode) + 1 : 0"
        @change="mode = modes[Number($event.detail.value) - 1]?.value || null"
      >
        <view class="picker">
          {{ modes.find(m => m.value === mode)?.label || '不绑定，仅手动执行' }} ›
        </view>
      </picker>
    </view>
    <view
      v-for="(action, index) in actions"
      :key="index"
      class="section"
    >
      <view class="row">
        <text>动作 {{ index + 1 }}</text><text @tap="actions.splice(index, 1)">
          移除
        </text>
      </view>
      <picker
        :range="choices.map(d => d.name)"
        :disabled="saving || loading"
        @change="chooseDevice(index, $event)"
      >
        <view class="picker">
          {{ devices.find(d => d.id === action.device_id)?.name || '选择设备' }} ›
        </view>
      </picker>
      <picker
        v-if="action.device_id"
        :range="commands(action)"
        :value="Math.max(0, commands(action).indexOf(action.action))"
        @change="chooseCommand(action, $event)"
      >
        <view class="picker">
          {{ action.action || '选择控制动作' }} ›
        </view>
      </picker>
      <ActionParameters
        v-model="action.params"
        :controls="controls(action)"
      /><text class="intro">
        {{ action.device_id ? describeAction(action, devices) : '请选择支持控制的设备' }}
      </text>
    </view>
    <button
      :disabled="loading || saving || actions.length >= 16"
      @tap="add"
    >
      增加设备动作
    </button><text class="intro">
      每台设备一个动作，最多 16 台。执行场景属于手动控制，会暂停相关设备自动化 30 分钟。
    </text>
    <button
      :disabled="loading || saving"
      :loading="saving"
      @tap="save"
    >
      保存场景
    </button>
  </view>
</template>
<style scoped lang="scss">.page { padding: 28rpx; }.section { padding: 28rpx; margin: 24rpx 0; background: $hg-card; border-radius: 20rpx; }.intro { display: block; font-size: 24rpx; color: $hg-muted; line-height: 1.8; margin: 18rpx 0; }.picker, input { padding: 24rpx 0; border-bottom: 1rpx solid $hg-line; }.row { display: flex; justify-content: space-between; }.row text:last-child { color: $hg-accent; }.error { color: $hg-crit; }</style>
