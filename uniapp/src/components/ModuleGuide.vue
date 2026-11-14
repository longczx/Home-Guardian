<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue';
import request from '@/api/request';
import { getDevice, sendCommand, getCommandResult } from '@/api/device';
import { withCommandResult } from '@/utils/command-result';
import { useServerStore } from '@/stores/server';
import type { Capability } from '@/api/types';
import type { GatewayModule } from '@/utils/gateway-modules';
const props = defineProps<{ gatewayId: number; module: GatewayModule }>();
interface Check { registered: boolean; server_time: string; device_id?: number; is_online?: boolean; gateway_online?: boolean; sensor_ready?: boolean; capability?: Capability;
  message?: string; readings?: { key: string; label: string; value: unknown; unit: string; ts: string | null; valid: boolean; fresh: boolean }[] }
const result = ref<Check | null>(null); const message = ref(''); const checking = ref(false); const passed = ref(false); const infraredSent = ref(false); const observed = ref(false);
const server = useServerStore(); let generation = 0; let timer: ReturnType<typeof setTimeout> | undefined; let deadline = 0; let baseline = 0;
const infrared = computed(() => props.module.type === 'ac_ir');
const guide = computed(() => props.module.type === 'dht11' ? ['VCC → 模块额定供电；确认购买模块的电压标识','GND → ESP32 GND，共地','DATA / S → GPIO ' + props.module.pin,'裸 DHT11 的引脚排列与带板模块不同；按模块丝印接线，必要时按模块说明加上拉电阻。'] : props.module.type === 'sound' ? ['VCC → 模块额定供电；确认输出电压适合 ESP32','GND → ESP32 GND，共地','AO（模拟输出）→ GPIO ' + props.module.pin,'使用 AO，不要把 DO 当成连续声音强度读数；当前读数为原始采样值，不是分贝。'] : ['VCC / 驱动供电 → 按红外发射模块额定值连接','GND → ESP32 GND，共地','IN / S → GPIO ' + props.module.pin,'大功率红外灯需使用驱动电路或成品驱动模块，避免 GPIO 直接带负载；发射头对准空调接收窗。']);
function stop() { if (timer) clearTimeout(timer); timer = undefined; checking.value = false; }
function reset() { generation++; stop(); result.value = null; message.value = ''; passed.value = false; infraredSent.value = false; observed.value = false; }
async function query() { return request.get<Check>(`/devices/${props.gatewayId}/module-check`, { uid: props.module.uid }); }
async function inspect(attempt: number) {
  try {
    const data = await query(); if (attempt !== generation) return; result.value = data;
    if (!baseline) baseline = Date.parse(data.server_time);
    const newReadings = data.readings?.length && data.readings.every(r => r.valid && r.fresh && r.ts && Date.parse(r.ts) > baseline);
    if (data.sensor_ready && newReadings) { passed.value = true; message.value = '已收到本次检查开始后的有效新采样'; stop(); return; }
    message.value = !data.registered ? data.message || '等待模块注册' : !data.gateway_online || !data.is_online ? '网关或模块离线，请检查供电与连接' : '正在等待新的有效采样；旧读数不会作为本次通过依据';
    if (Date.now() >= deadline) { message.value = '检查超时。请核对接线、供电和上报周期后重试'; stop(); return; }
    timer = setTimeout(() => { void inspect(attempt); }, 2000);
  } catch (e) { if (attempt === generation) { message.value = (e as Error).message; stop(); } }
}
function checkSensor() { if (checking.value) return; reset(); checking.value = true; baseline = 0; deadline = Date.now() + 180000; void inspect(generation); }
async function testInfrared() {
  if (checking.value) return; reset(); checking.value = true; const attempt = generation;
  try {
    const info = await query(); if (attempt !== generation) return; result.value = info;
    if (!info.device_id || !info.is_online || !info.gateway_online) throw new Error('红外模块尚未注册或离线，请先恢复联网');
    const device = await getDevice(info.device_id); if (attempt !== generation) return;
    const control = device.capability?.controls.find(c => c.value_type === 'bool' && (c.param === 'power' || c.key === 'power'));
    if (!control) throw new Error('该设备未声明电源控制，请先检查控制能力模板');
    const value = !device.state?.[control.state_key || control.key];
    checking.value = false;
    uni.showModal({ title: '发送一次红外' + (value ? '开机' : '关机') + '测试？', content: '沿用当前协议和型号，只改变电源期望值。请对准空调并观察响应；相关设备自动化暂停 30 分钟。', success: async r => {
      if (!r.confirm || attempt !== generation) return; checking.value = true; observed.value = false; infraredSent.value = false;
      try {
        await withCommandResult(() => sendCommand(info.device_id!, { action: control.command, params: { [control.param]: value } }), 65000, getCommandResult);
        if (attempt !== generation) return;
        infraredSent.value = true; message.value = '设备已确认红外发送。请观察空调实际响应，再选择下方结果';
      } catch (e) { if (attempt === generation) message.value = (e as Error).message; }
      finally { if (attempt === generation) checking.value = false; }
    } });
  } catch (e) { if (attempt === generation) message.value = (e as Error).message; }
  finally { if (attempt === generation) checking.value = false; }
}
function confirmResponse(ok: boolean) { observed.value = ok; message.value = ok ? '本次人工确认：空调有响应。此结果不代表之后每次指令都能确认实际状态' : '红外已发送但空调无响应：核对供电、发射方向、距离和协议 / 遥控器型号；设备回执不能证明空调执行'; }
watch(() => `${props.gatewayId}:${props.module.uid}:${props.module.pin}`, reset);
watch(() => server.connectionRevision, reset);
onUnmounted(reset);
</script>
<template>
  <view class="guide">
    <text class="title">
      {{ module.name }} · 接线与功能验收
    </text><text class="hint">
      配置已从网关读回：{{ module.type }} · GPIO {{ module.pin }}。下面继续检查实际接线和功能。
    </text>
    <view class="wires">
      <text
        v-for="(line, i) in guide"
        :key="i"
      >
        {{ i + 1 }}. {{ line }}
      </text>
    </view><text class="hint">
      先断电再接线；GPIO 编号不是开发板排针序号。ESP32 GPIO 不可直接接入 5V 信号，供电与电平以实际模块规格为准。
    </text>
    <button
      :disabled="checking"
      :loading="checking"
      @tap="infrared ? testInfrared() : checkSensor()"
    >
      {{ infrared ? '发送一次红外电源测试' : '检查一次新的传感器上报' }}
    </button>
    <text
      v-if="message"
      class="message"
      :class="{ passed: passed || observed }"
    >
      {{ message }}
    </text>
    <view
      v-for="reading in result?.readings || []"
      :key="reading.key"
      class="reading"
    >
      <text>{{ reading.label }}：{{ reading.value ?? '尚无读数' }} {{ reading.unit }}</text><text class="hint">
        {{ reading.ts ? new Date(reading.ts).toLocaleString() : '尚未上报' }} · {{ reading.valid && reading.fresh ? '有效读数' : '无效或已过期' }}
      </text>
    </view>
    <view v-if="infraredSent">
      <button
        size="mini"
        @tap="confirmResponse(true)"
      >
        我观察到空调有响应
      </button><button
        size="mini"
        @tap="confirmResponse(false)"
      >
        空调没有响应
      </button><text class="hint">
        人工确认仅记录在当前验收页面，不会将红外设备标记成有真实状态反馈。
      </text>
    </view>
    <text
      v-if="!infrared"
      class="hint"
    >
      检查最多等待 3 分钟，需要出现检查开始后的新采样；声音传感器可在旁边拍手观察变化，但数值不是专业噪声计读数。
    </text>
  </view>
</template>
<style scoped lang="scss">.guide { background: $hg-card-2; border-radius: 16rpx; padding: 24rpx; margin-top: 24rpx; }.title { display: block; font-size: 29rpx; font-weight: 600; }.hint { display: block; color: $hg-muted; font-size: 23rpx; line-height: 1.8; margin: 16rpx 0; }.wires text { display: block; padding: 16rpx 0; border-bottom: 1rpx solid $hg-line; font-size: 25rpx; line-height: 1.7; }.message { display: block; font-size: 25rpx; margin: 22rpx 0; color: $hg-warn; }.passed { color: $hg-ok; }.reading { font-size: 26rpx; padding: 14rpx 0; }button { margin: 18rpx 0; }</style>
