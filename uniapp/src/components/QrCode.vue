<script setup lang="ts">
import { onMounted, watch, getCurrentInstance } from 'vue';
import UQRCode from '@/utils/uqrcode/uqrcode.js';

const props = withDefaults(defineProps<{ value: string; size?: number; canvasId?: string }>(), {
  size: 200,
  canvasId: 'qrcode-canvas',
});

const instance = getCurrentInstance();

function render() {
  if (!props.value) return;
  const qr = new UQRCode();
  qr.data = props.value;
  qr.size = props.size;
  qr.make();
  qr.canvasContext = uni.createCanvasContext(props.canvasId, instance);
  qr.drawCanvas();
}

onMounted(() => setTimeout(render, 50));
watch(() => props.value, () => setTimeout(render, 50));
</script>

<template>
  <canvas
    :canvas-id="canvasId"
    :id="canvasId"
    :style="{ width: size + 'px', height: size + 'px' }"
  />
</template>
