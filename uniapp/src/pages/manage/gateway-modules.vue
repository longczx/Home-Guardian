<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue';
import { onLoad, onShow } from '@dcloudio/uni-app';
import { getDevice } from '@/api/device';
import type { Device } from '@/api/types';
import { useServerStore } from '@/stores/server';
import { readGatewayModules, changeGatewayModule, modulePinError } from '@/utils/gateway-modules';
import type { GatewayModule, ModuleSnapshot, ModuleType } from '@/utils/gateway-modules';
import { toast } from '@/utils/guard';

const id = ref(0);
const gateway = ref<Device | null>(null);
const snapshot = ref<ModuleSnapshot>({ modules: [] });
const ready = ref(false);
const busy = ref(false);
const message = ref('');
const editing = ref('');
const form = ref<{ type: ModuleType | ''; pin: number | ''; name: string }>({ type: '', pin: '', name: '' });
const server = useServerStore();
let generation = 0;
let active = true;
const types = [
  { value: 'dht11' as const, label: 'DHT11 温湿度' },
  { value: 'sound' as const, label: '声音传感器' },
  { value: 'ac_ir' as const, label: '红外空调' },
];
const editable = computed(() => ready.value && snapshot.value.module_config_version === 1 && !!snapshot.value.pins && !!gateway.value?.is_online);
const typeLabel = computed(() => types.find(t => t.value === form.value.type)?.label || '请选择模块类型');
const availablePins = computed(() => !form.value.type ? [] : (snapshot.value.pins?.[form.value.type] || []).filter(
  pin => !snapshot.value.modules.some(m => m.uid !== editing.value && m.pin === pin),
));
function resetForm() { editing.value = ''; form.value = { type: '', pin: '', name: '' }; }
function chooseType() {
  if (editing.value || busy.value || !editable.value) return;
  uni.showActionSheet({ itemList: types.map(t => t.label), success: r => {
    form.value.type = types[r.tapIndex].value; form.value.pin = '';
  } });
}
const pinLabels = computed(() => ['请选择 GPIO', ...availablePins.value.map(pin => `GPIO ${pin}`)]);
const pinIndex = computed(() => form.value.pin === '' ? 0 : availablePins.value.indexOf(Number(form.value.pin)) + 1);
function choosePin(event: { detail: { value: string | number } }) {
  if (busy.value || !editable.value) return;
  const pin = availablePins.value[Number(event.detail.value) - 1];
  form.value.pin = pin ?? '';
}
function editModule(module: GatewayModule) {
  if (busy.value || !editable.value) return;
  editing.value = module.uid;
  form.value = { type: module.type, pin: module.pin, name: module.name };
}
async function load() {
  if (busy.value || !active) return;
  busy.value = true; ready.value = false; message.value = '';
  const attempt = ++generation;
  try {
    const device = await getDevice(id.value);
    if (!active || attempt !== generation) return;
    gateway.value = device;
    if (!device.is_online) throw new Error('网关离线，请连接后读取设备配置');
    const result = await readGatewayModules(id.value);
    if (!active || attempt !== generation) return;
    snapshot.value = result; ready.value = true; resetForm();
    if (result.module_config_version !== 1 || !result.pins) message.value = '当前固件只支持查看，请升级到 1.4.0 或更新版本后管理模块';
  } catch (e) { if (active && attempt === generation) message.value = (e as Error).message; }
  finally { if (attempt === generation) busy.value = false; }
}
async function apply(action: 'add_module' | 'update_module' | 'remove_module', params: Record<string, unknown>) {
  if (busy.value || !editable.value || !active) return;
  busy.value = true; message.value = '等待设备执行确认并读回配置…';
  const attempt = ++generation;
  try {
    const result = await changeGatewayModule(id.value, action, params);
    if (!active || attempt !== generation) return;
    snapshot.value = result; ready.value = true;
    resetForm(); message.value = '设备已执行，配置读回确认成功';
    toast('设备已确认', 'success');
  } catch (e) {
    if (active && attempt === generation) {
      ready.value = false; message.value = (e as Error).message + '；请刷新读取设备实际配置后再操作';
    }
  } finally { if (attempt === generation) busy.value = false; }
}
async function saveModule() {
  if (!form.value.type) return toast('请选择模块类型');
  if (form.value.pin === '') return toast('请选择 GPIO');
  const pin = Number(form.value.pin);
  const error = modulePinError(snapshot.value, form.value.type, pin, editing.value);
  if (error) return toast(error);
  if (!editing.value && snapshot.value.modules.length >= (snapshot.value.max_modules || 8)) return toast('模块数量已达上限');
  if (encodeURIComponent(form.value.name.trim()).replace(/%[0-9A-F]{2}/g, 'x').length > 64) return toast('名称过长，请缩短');
  await apply(editing.value ? 'update_module' : 'add_module', editing.value
    ? { uid: editing.value, pin }
    : { type: form.value.type, pin, name: form.value.name.trim() || undefined });
}
function removeModule(module: GatewayModule) {
  if (busy.value || !editable.value) return;
  const revision = server.connectionRevision;
  uni.showModal({ title: '移除模块？', content: `移除「${module.name}」并释放 GPIO ${module.pin}。设备历史记录会保留。`, success: result => {
    if (result.confirm && active && server.connectionRevision === revision) void apply('remove_module', { uid: module.uid });
  } });
}
watch(() => server.connectionRevision, () => {
  active = false; generation++; busy.value = false; ready.value = false;
  gateway.value = null; snapshot.value = { modules: [] }; resetForm();
  message.value = '服务器已切换，请返回设备列表重新进入';
}, { flush: 'sync' });
onLoad(q => { id.value = Number(q?.id || 0); });
onShow(load);
onUnmounted(() => { active = false; generation++; });
</script>

<template>
  <view class="page">
    <view class="card">
      <text class="title">
        {{ gateway?.name || '网关模块管理' }}
      </text>
      <text class="hint">
        {{ gateway?.is_online ? '网关在线' : '等待网关连接' }} · 配置从设备实际读取
      </text>
      <button
        :disabled="busy || !active"
        :loading="busy"
        @tap="load"
      >
        刷新设备配置
      </button>
      <text
        v-if="message"
        class="message"
      >
        {{ message }}
      </text>
    </view>
    <view class="card">
      <text class="title">
        已挂载模块（{{ ready ? snapshot.modules.length : '待确认' }}）
      </text>
      <text
        v-if="ready && !snapshot.modules.length"
        class="hint"
      >
        暂无模块，可在下方添加。清空后重启仍保持为空。
      </text>
      <view
        v-for="module in snapshot.modules"
        :key="module.uid"
        class="row"
      >
        <view class="module-info">
          <text class="name">
            {{ module.name }}
          </text>
          <text class="hint">
            {{ module.type }} · GPIO {{ module.pin }}
          </text>
          <text class="hint">
            {{ module.uid }}
          </text>
        </view>
        <button
          size="mini"
          :disabled="busy || !editable"
          @tap="editModule(module)"
        >
          改引脚
        </button>
        <button
          size="mini"
          :disabled="busy || !editable"
          @tap="removeModule(module)"
        >
          移除
        </button>
      </view>
    </view>
    <view class="card">
      <text class="title">
        {{ editing ? '修改模块引脚' : '添加模块' }}
      </text>
      <text class="hint">
        断电调整接线后再上电保存。修改引脚保留设备身份、历史记录及红外协议。
      </text>
      <view
        class="field"
        @tap="chooseType"
      >
        <text class="label">
          模块类型
        </text><text>{{ typeLabel }}</text>
      </view>
      <picker
        :range="pinLabels"
        :value="pinIndex"
        :disabled="busy || !editable || !form.type || !availablePins.length"
        @change="choosePin"
      >
        <view class="field">
          <text class="label">
            GPIO 引脚
          </text><text>{{ form.pin === '' ? '选择可用引脚' : 'GPIO ' + form.pin }}</text>
        </view>
      </picker>
      <text class="hint">
        仅显示设备允许且未被占用的引脚；系统保留引脚不能选择。
      </text>
      <view
        v-if="!editing"
        class="field"
      >
        <text class="label">
          名称（可空）
        </text><input
          v-model="form.name"
          :disabled="busy || !editable"
          placeholder="如 客厅温湿度"
        >
      </view>
      <button
        class="primary"
        :disabled="busy || !editable"
        :loading="busy"
        @tap="saveModule"
      >
        {{ editing ? '保存引脚并确认' : '添加并确认' }}
      </button>
      <button
        v-if="editing"
        :disabled="busy"
        @tap="resetForm"
      >
        取消修改
      </button>
      <text class="hint">
        设备确认保存并应用后才显示成功；传感器读数和红外响应仍需结合实际接线检查。
      </text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx; }
.card { background: $hg-card; border-radius: $hg-radius; padding: 28rpx; margin-bottom: 24rpx; box-shadow: $hg-shadow; }
.title { display: block; font-size: 30rpx; font-weight: 600; color: $hg-fg; margin-bottom: 16rpx; }
.hint { display: block; font-size: 24rpx; color: $hg-muted; line-height: 1.6; margin: 12rpx 0; word-break: break-all; }
.message { display: block; font-size: 26rpx; color: $hg-accent; margin-top: 20rpx; }
.row { display: flex; flex-wrap: wrap; align-items: center; gap: 12rpx; padding: 20rpx 0; border-bottom: 1rpx solid $hg-line; }
.module-info { flex: 1; min-width: 240rpx; }
.name { font-size: 28rpx; color: $hg-fg; }
.field { padding: 24rpx 0; border-bottom: 1rpx solid $hg-line; color: $hg-fg; font-size: 28rpx; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
button { margin-top: 16rpx; font-size: 26rpx; }
.primary { background: $hg-accent; color: #fff; }
</style>
