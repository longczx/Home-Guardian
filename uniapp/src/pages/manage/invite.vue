<script setup lang="ts">
import { ref, computed } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getInvites, createInvite, deleteInvite, type InviteCode } from '@/api/home';
import { useServerStore } from '@/stores/server';
import QrCode from '@/components/QrCode.vue';
import { ensureReady, toast } from '@/utils/guard';

const server = useServerStore();
const invites = ref<InviteCode[]>([]);
const role = ref<'admin' | 'member'>('member');
const creating = ref(false);
const active = ref<InviteCode | null>(null);

// 邀请二维码内容：带上服务器地址，家人扫码即可一步到位（先配服务器再落到注册）
const qrValue = computed(() =>
  active.value
    ? `hg://invite?code=${active.value.code}&url=${encodeURIComponent(server.current?.url || '')}&name=${encodeURIComponent(server.current?.name || '')}`
    : '',
);

async function load() {
  if (!ensureReady()) return;
  try {
    invites.value = await getInvites();
  } catch (e) {
    toast((e as Error).message);
  }
}

async function gen() {
  creating.value = true;
  try {
    const inv = await createInvite(role.value);
    active.value = inv;
    await load();
  } catch (e) {
    toast((e as Error).message);
  } finally {
    creating.value = false;
  }
}

function copyCode(code: string) {
  uni.setClipboardData({ data: code, success: () => toast('邀请码已复制', 'success') });
}

function revoke(inv: InviteCode) {
  uni.showModal({
    title: '作废邀请码',
    content: `确定作废 ${inv.code}？`,
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await deleteInvite(inv.id);
        if (active.value?.id === inv.id) active.value = null;
        await load();
      } catch (e) {
        toast((e as Error).message);
      }
    },
  });
}

const STATUS_LABEL: Record<string, string> = { pending: '待使用', used: '已使用', expired: '已过期' };
onShow(load);
</script>

<template>
  <view class="page">
    <view class="card">
      <text class="ct">生成邀请码</text>
      <view class="seg">
        <view class="seg-btn" :class="{ on: role === 'member' }" @tap="role = 'member'">成员</view>
        <view class="seg-btn" :class="{ on: role === 'admin' }" @tap="role = 'admin'">管理员</view>
      </view>
      <button class="btn btn-primary" :loading="creating" @tap="gen">生成</button>
    </view>

    <!-- 当前生成的码 + 二维码 -->
    <view v-if="active" class="card center">
      <QrCode :value="qrValue" :size="200" canvas-id="invite-qr" />
      <text class="code" @tap="copyCode(active.code)">{{ active.code }}</text>
      <text class="hint">家人用 App 注册页填此码，或扫码一步加入 · 点码可复制</text>
    </view>

    <!-- 历史 -->
    <view class="list">
      <view v-for="inv in invites" :key="inv.id" class="row">
        <view class="mid">
          <text class="c">{{ inv.code }}</text>
          <text class="meta">{{ inv.role === 'admin' ? '管理员' : '成员' }} · {{ STATUS_LABEL[inv.status] }}</text>
        </view>
        <text v-if="inv.status === 'pending'" class="del" @tap="revoke(inv)">作废</text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 60rpx; }
.card {
  background: $hg-card; border-radius: $hg-radius; padding: 28rpx;
  box-shadow: $hg-shadow; margin-bottom: 20rpx;
}
.card.center { text-align: center; }
.ct { display: block; font-size: 28rpx; font-weight: 600; color: $hg-fg; margin-bottom: 20rpx; }
.seg { display: flex; gap: 12rpx; margin-bottom: 20rpx; }
.seg-btn {
  flex: 1; text-align: center; padding: 16rpx 0; border-radius: $hg-radius-s;
  border: 1rpx solid $hg-line; background: $hg-card-2; color: $hg-muted; font-size: 26rpx;
}
.seg-btn.on { background: $hg-accent-soft; border-color: $hg-accent; color: $hg-accent; font-weight: 600; }
.code {
  display: block; font-size: 56rpx; font-weight: 700; letter-spacing: 8rpx;
  color: $hg-accent; font-family: monospace; margin: 20rpx 0 8rpx;
}
.hint { font-size: 22rpx; color: $hg-muted; }
.btn { border-radius: $hg-radius-s; height: 84rpx; line-height: 84rpx; font-size: 30rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
.list { display: flex; flex-direction: column; gap: 14rpx; margin-top: 8rpx; }
.row {
  display: flex; align-items: center;
  background: $hg-card; border-radius: $hg-radius-s; padding: 22rpx 24rpx; box-shadow: $hg-shadow;
}
.mid { flex: 1; }
.c { font-size: 28rpx; font-weight: 600; color: $hg-fg; font-family: monospace; letter-spacing: 2rpx; }
.meta { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.del { font-size: 24rpx; color: $hg-crit; }
</style>
