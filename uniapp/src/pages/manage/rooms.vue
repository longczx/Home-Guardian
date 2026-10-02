<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getRooms, saveRoom, deleteRoom, type Room } from '@/api/experience';
import { toast } from '@/utils/guard';
const rooms = ref<Room[]>([]); const name = ref(''); const order = ref(0); const busy = ref(false);
async function load() { try { rooms.value = await getRooms(); } catch (e) { toast((e as Error).message); } }
async function save(roomName = name.value, sortOrder = order.value) { if (busy.value) return; busy.value = true; try { await saveRoom(roomName, Number(sortOrder)); name.value = ''; await load(); } catch (e) { toast((e as Error).message); } finally { busy.value = false; } }
function remove(room: Room) { uni.showModal({ title: '删除房间', content: `删除「${room.name}」？房间内有设备时不能删除。`, success: async (r) => { if (!r.confirm) return; try { await deleteRoom(room.id); await load(); } catch (e) { toast((e as Error).message); } } }); }
onShow(load);
</script>
<template>
  <view class="page">
    <text class="hint">
      排序数字越小，首页越靠前。设备编辑时填写对应房间名即可归入；改变房间名称请先调整设备，避免影响成员权限。
    </text><view class="card">
      <input
        v-model="name"
        placeholder="新房间名称"
      ><input
        v-model.number="order"
        type="number"
        placeholder="排序"
      ><button
        :disabled="busy"
        @tap="save()"
      >
        添加房间
      </button>
    </view><view
      v-for="room in rooms"
      :key="room.id"
      class="card"
    >
      <text>{{ room.name }}</text><input
        v-model.number="room.sort_order"
        type="number"
      ><button
        size="mini"
        :disabled="busy"
        @tap="save(room.name, room.sort_order)"
      >
        保存排序
      </button><button
        size="mini"
        @tap="remove(room)"
      >
        删除空房间
      </button>
    </view>
  </view>
</template>
<style scoped lang="scss">.page { padding: 28rpx; }.card { background: $hg-card; border-radius: 20rpx; padding: 26rpx; margin: 20rpx 0; }.hint { color: $hg-muted; font-size: 24rpx; line-height: 1.6; } input { margin: 18rpx 0; }</style>
