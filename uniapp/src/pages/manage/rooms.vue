<script setup lang="ts">
import { ref, watch } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getRooms, saveRoom, deleteRoom, type Room } from '@/api/experience';
import request from '@/api/request';
import { getDevices } from '@/api/device';
import type { Device } from '@/api/types';
import { useServerStore } from '@/stores/server';
import { toast, ensureReady } from '@/utils/guard';
import { goHome } from '@/utils/navigation';
const rooms = ref<Room[]>([]); const name = ref(''); const busy = ref(false); const error = ref('');
const target = ref<Room | null>(null); const devices = ref<Device[]>([]); const selected = ref<number[]>([]); const keyword = ref('');
const server = useServerStore(); let generation = 0; let dragIndex = -1; let rects: { top: number; height: number }[] = [];
async function load() {
  if (!ensureReady()) return; const attempt = ++generation; error.value = '';
  try { const list = await getRooms(); if (attempt === generation) rooms.value = list; } catch (e) { if (attempt === generation) error.value = (e as Error).message; }
}
async function add() {
  if (busy.value || !name.value.trim()) return;
  if (rooms.value.some(room => room.name === name.value.trim())) return toast('房间名称已存在');
  busy.value = true;
  try { await saveRoom(name.value.trim(), rooms.value.length); name.value = ''; await load(); } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}
function rename(room: Room) {
  const revision = server.connectionRevision;
  uni.showModal({ title: '修改房间名称', editable: true, content: room.name, placeholderText: '新房间名称', success: async r => {
    if (!r.confirm || busy.value || revision !== server.connectionRevision) return;
    busy.value = true;
    try { const result = await request.patch<{ affected_devices: number; affected_members: number }>(`/rooms/${room.id}`, { name: r.content || '' }); await load(); toast(`已同步 ${result.affected_devices} 台设备及 ${result.affected_members} 位成员权限`); }
    catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
  } });
}
async function reorder(from: number, to: number) {
  if (busy.value || from === to || to < 0 || to >= rooms.value.length) return;
  const previous = [...rooms.value]; const list = [...previous]; list.splice(to, 0, list.splice(from, 1)[0]); rooms.value = list; busy.value = true;
  try { await request.put('/rooms/order', { ids: list.map(room => room.id) }); toast('排序已保存', 'success'); }
  catch (e) { rooms.value = previous; error.value = (e as Error).message; } finally { busy.value = false; }
}
function startDrag(index: number) {
  if (busy.value) return; dragIndex = index; rects = [];
  uni.createSelectorQuery().selectAll('.room-row').boundingClientRect(result => { rects = result as { top: number; height: number }[]; }).exec();
}
function endDrag(event: unknown) {
  const e = event as { changedTouches?: ArrayLike<{ clientY?: number }> }; const y = e.changedTouches?.[0]?.clientY;
  if (dragIndex >= 0 && y !== undefined && rects.length) { const index = rects.reduce((best, r, i, all) => Math.abs(r.top + r.height / 2 - y) < Math.abs(all[best].top + all[best].height / 2 - y) ? i : best, 0); void reorder(dragIndex, index); }
  dragIndex = -1;
}
function remove(room: Room) {
  const revision = server.connectionRevision;
  uni.showModal({ title: '删除空房间', content: `删除「${room.name}」？有设备时请先批量移到其他房间。`, success: async r => { if (!r.confirm || revision !== server.connectionRevision) return; try { await deleteRoom(room.id); await load(); } catch (e) { error.value = (e as Error).message; } } });
}
async function openMove(room: Room) {
  if (busy.value) return; busy.value = true; error.value = ''; const attempt = generation;
  try { const first = await getDevices({ per_page: 100 }); const list = first.items;
    for (let page = 2; page <= first.last_page; page++) list.push(...(await getDevices({ per_page: 100, page })).items);
    if (attempt !== generation) return; devices.value = list; target.value = room; selected.value = []; keyword.value = '';
  } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}
function select(id: number) { selected.value = selected.value.includes(id) ? selected.value.filter(v => v !== id) : [...selected.value, id]; }
function move() {
  if (!target.value || !selected.value.length || busy.value) return;
  const revision = server.connectionRevision; const room = target.value; const ids = [...selected.value];
  uni.showModal({ title: '确认批量移动？', content: `将 ${ids.length} 台设备移入「${room.name}」。这会改变按房间授权成员能访问的设备，不改变接线及设备历史。`, success: async r => {
    if (!r.confirm || revision !== server.connectionRevision) return; busy.value = true;
    try { await request.put(`/rooms/${room.id}/devices`, { device_ids: ids }); target.value = null; selected.value = []; await load(); toast('设备已移入', 'success'); } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
  } });
}
watch(() => server.connectionRevision, () => { generation++; rooms.value = []; target.value = null; devices.value = []; selected.value = []; busy.value = false; error.value = '服务器已切换，请返回重新进入'; });
onShow(load);
</script>
<template>
  <view class="page">
    <button
      size="mini"
      @tap="goHome"
    >
      返回首页
    </button><text
      v-if="error"
      class="error"
      @tap="load"
    >
      {{ error }} · 点击刷新
    </text>
    <template v-if="!target">
      <text class="hint">
        拖动左侧手柄调整顺序，也可使用上移 / 下移。改名会同步设备和成员的位置权限。
      </text><view class="create">
        <input
          v-model="name"
          :maxlength="100"
          placeholder="新房间名称"
        ><button
          size="mini"
          :disabled="busy || !name.trim()"
          @tap="add"
        >
          添加房间
        </button>
      </view>
      <view
        v-for="(room, index) in rooms"
        :key="room.id"
        class="room-row"
      >
        <text
          class="handle"
          @touchstart="startDrag(index)"
          @touchmove.stop.prevent
          @touchend="endDrag"
        >
          ☰
        </text><view class="room-content">
          <text class="name">
            {{ room.name }}
          </text><view class="actions">
            <text @tap="rename(room)">
              改名
            </text><text @tap="openMove(room)">
              移入设备
            </text><text @tap="remove(room)">
              删除
            </text>
          </view><view class="arrows">
            <button
              size="mini"
              :disabled="busy || index === 0"
              @tap="reorder(index, index - 1)"
            >
              上移
            </button><button
              size="mini"
              :disabled="busy || index === rooms.length - 1"
              @tap="reorder(index, index + 1)"
            >
              下移
            </button>
          </view>
        </view>
      </view>
      <text
        v-if="!rooms.length"
        class="hint"
      >
        还没有房间，先添加「客厅」或「卧室」。
      </text>
    </template>
    <template v-else>
      <text class="name">
        移入「{{ target.name }}」
      </text><text class="hint">
        选择需要移动的设备；网关和其下模块分别选择。最多 200 台。
      </text><input
        v-model="keyword"
        placeholder="搜索设备或当前房间"
      ><view
        v-for="d in devices.filter(d => d.location !== target?.name && (!keyword || (d.name + (d.location || '')).includes(keyword)))"
        :key="d.id"
        class="device"
        @tap="select(d.id)"
      >
        <checkbox :checked="selected.includes(d.id)" /><text>{{ d.name }} · {{ d.location || '未分配房间' }}</text>
      </view><button
        :disabled="busy || !selected.length || selected.length > 200"
        @tap="move"
      >
        移入所选 {{ selected.length }} 台设备
      </button><button
        :disabled="busy"
        @tap="target = null"
      >
        取消
      </button>
    </template>
  </view>
</template>
<style scoped lang="scss">.page { padding: 28rpx; }.hint { display: block; font-size: 24rpx; color: $hg-muted; line-height: 1.8; margin: 24rpx 0; }.create { display: flex; gap: 16rpx; align-items: center; margin: 24rpx 0; }.create input { flex: 1; }input { padding: 22rpx; background: $hg-card; border-radius: 12rpx; }.room-row { display: flex; align-items: center; background: $hg-card; padding: 28rpx 20rpx; border-radius: 20rpx; margin: 18rpx 0; }.handle { padding: 35rpx 24rpx 35rpx 8rpx; color: $hg-muted; font-size: 36rpx; touch-action: none; }.room-content { flex: 1; }.name { font-size: 30rpx; font-weight: 600; }.actions,.arrows { display: flex; gap: 24rpx; margin-top: 20rpx; }.actions { color: $hg-accent; font-size: 25rpx; }.arrows button { margin: 0; }.device { display: flex; align-items: center; padding: 24rpx 0; border-bottom: 1rpx solid $hg-line; font-size: 26rpx; }.error { display: block; color: $hg-crit; font-size: 25rpx; }</style>
