<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad, onShow } from '@dcloudio/uni-app';
import { getDevice, getDevices, sendCommand } from '@/api/device';
import type { Device } from '@/api/types';
import { toast } from '@/utils/guard';

/**
 * 网关子设备热插拔
 *
 * 增删的是【网关固件里的模块清单】，不是后台记录：下发 add_module / remove_module
 * 给网关，固件存进 NVS 并上报新的子设备清单，平台据此自动增补/下线设备。
 * 全程无需改 config.h 重新烧录，也无需重新配网。
 */

const id = ref(0);
const gateway = ref<Device | null>(null);
const children = ref<Device[]>([]);
const busy = ref(false);

// 可挂载的模块类型：与固件 ModuleStore::isSupportedType 一一对应
const MODULE_TYPES = [
  { value: 'dht11', label: 'DHT11 温湿度', defaultPin: 4, hint: '数字口，任意 GPIO' },
  { value: 'sound', label: '声音传感器', defaultPin: 34, hint: '模拟口 AO，需 ADC1（32-39）' },
  { value: 'ac_ir', label: '红外空调', defaultPin: 25, hint: '发射管需经三极管驱动，勿用 34-39' },
];

const form = ref({ type: '', pin: 0, name: '' });
const typeLabel = computed(
  () => MODULE_TYPES.find((t) => t.value === form.value.type)?.label ?? '请选择 ›',
);
const typeHint = computed(() => MODULE_TYPES.find((t) => t.value === form.value.type)?.hint ?? '');

function chooseType() {
  uni.showActionSheet({
    itemList: MODULE_TYPES.map((t) => t.label),
    success: (r) => {
      const t = MODULE_TYPES[r.tapIndex];
      if (!t) return;
      form.value.type = t.value;
      form.value.pin = t.defaultPin;
      if (!form.value.name) form.value.name = t.label;
    },
  });
}

async function load() {
  try {
    const gw = await getDevice(id.value);
    gateway.value = gw;
    const page = await getDevices({ per_page: 200 });
    children.value = (page.items || []).filter((d) => d.gateway_uid === gw.device_uid);
  } catch (e) {
    toast((e as Error).message);
  }
}

async function addModule() {
  if (!form.value.type) return toast('请选择模块类型');
  const pin = Number(form.value.pin);
  if (!Number.isInteger(pin) || pin < 0 || pin > 39) return toast('GPIO 需在 0-39 之间');
  if (form.value.type === 'ac_ir' && pin >= 34) return toast('34-39 为仅输入引脚，无法驱动红外管');
  if (!gateway.value?.is_online) return toast('网关离线，无法下发');

  busy.value = true;
  try {
    await sendCommand(id.value, {
      action: 'add_module',
      params: { type: form.value.type, pin, name: form.value.name.trim() || undefined },
    });
    // 指令是异步下发的，固件执行并上报清单后平台才会出现新设备
    toast('已下发，稍候刷新查看', 'success');
    form.value = { type: '', pin: 0, name: '' };
    setTimeout(load, 2500);
  } catch (e) {
    toast((e as Error).message);
  } finally {
    busy.value = false;
  }
}

function removeModule(d: Device) {
  if (!gateway.value?.is_online) return toast('网关离线，无法下发');
  uni.showModal({
    title: '拔除模块？',
    content: `将从网关上移除「${d.name}」。设备记录与历史数据会保留，只是转为离线；要彻底清除请在后台删除。`,
    success: async (m) => {
      if (!m.confirm) return;
      busy.value = true;
      try {
        await sendCommand(id.value, { action: 'remove_module', params: { uid: d.device_uid } });
        toast('已下发', 'success');
        setTimeout(load, 2500);
      } catch (e) {
        toast((e as Error).message);
      } finally {
        busy.value = false;
      }
    },
  });
}

onLoad((q) => {
  id.value = Number(q?.id || 0);
});
onShow(load);
</script>

<template>
  <view class="page">
    <view v-if="gateway" class="card info">
      <view class="irow"><text class="ik">网关</text><text class="iv">{{ gateway.name }}</text></view>
      <view class="irow">
        <text class="ik">状态</text>
        <text class="iv" :style="{ color: gateway.is_online ? '#2fb56b' : '#7a8299' }">
          {{ gateway.is_online ? '在线' : '离线（无法下发指令）' }}
        </text>
      </view>
    </view>

    <!-- 已挂载 -->
    <view class="card">
      <view class="ct">已挂载模块（{{ children.length }}）</view>
      <view v-if="!children.length" class="empty">暂无子设备</view>
      <view v-for="d in children" :key="d.id" class="row">
        <view class="mid">
          <text class="nm">{{ d.name }}</text>
          <text class="sub">{{ d.type }} · {{ d.device_uid }}</text>
        </view>
        <text class="dot" :style="{ color: d.is_online ? '#2fb56b' : '#7a8299' }">●</text>
        <text class="del" @tap="removeModule(d)">拔除</text>
      </view>
    </view>

    <!-- 新增 -->
    <view class="card">
      <view class="ct">插入新模块</view>
      <view class="field">
        <text class="label">模块类型</text>
        <view class="picker" @tap="chooseType">{{ typeLabel }}</view>
      </view>
      <view class="field">
        <text class="label">GPIO 引脚</text>
        <input v-model="form.pin" class="input" type="number" placeholder="如 4" placeholder-class="ph" />
        <text v-if="typeHint" class="hint">{{ typeHint }}</text>
      </view>
      <view class="field">
        <text class="label">名称（可空）</text>
        <input v-model="form.name" class="input" placeholder="如 客厅温湿度" placeholder-class="ph" />
      </view>
      <button class="btn" :disabled="busy" @tap="addModule">插入并同步</button>
      <text class="tip">模块清单存在网关的 NVS 里，掉电重启沿用，无需重新烧录固件。</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 20rpx; }
.card {
  background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx 28rpx;
  box-shadow: $hg-shadow; margin-bottom: 20rpx;
}
.info { padding: 20rpx 28rpx; }
.irow { display: flex; justify-content: space-between; padding: 12rpx 0; }
.ik { font-size: 26rpx; color: $hg-muted; }
.iv { font-size: 26rpx; color: $hg-fg; }
.ct { font-size: 26rpx; font-weight: 600; color: $hg-fg; padding: 22rpx 0 4rpx; }
.empty { font-size: 26rpx; color: $hg-muted; padding: 24rpx 0; }
.row { display: flex; align-items: center; padding: 22rpx 0; border-bottom: 1rpx solid $hg-line; }
.row:last-child { border-bottom: none; }
.mid { flex: 1; }
.nm { display: block; font-size: 30rpx; color: $hg-fg; }
.sub { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 6rpx; }
.dot { font-size: 22rpx; margin-right: 20rpx; }
.del { font-size: 26rpx; color: #e5484d; }
.field { padding: 26rpx 0; border-bottom: 1rpx solid $hg-line; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
.input { font-size: 30rpx; color: $hg-fg; }
.picker { font-size: 30rpx; color: $hg-fg; }
.ph { color: #b6bccb; }
.hint { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 10rpx; }
.btn {
  margin-top: 26rpx; background: $hg-accent; color: #fff; font-size: 30rpx;
  border-radius: $hg-radius-s; border: none;
}
.tip { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 18rpx; line-height: 1.6; }
</style>
