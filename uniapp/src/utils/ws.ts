import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';

/**
 * WebSocket 实时管理器（单例）
 *
 * 连接当前服务器的 /ws（JWT 走 query），按消息 type 分发给订阅者。
 * 断线自动重连（指数退避，封顶）；25s 心跳保活。
 * 仅作为实时增强层——各页面仍有 onShow 拉取 + 下拉刷新兜底，WS 连不上不影响可用性。
 *
 * 后端广播类型：telemetry / device_status / device_state / alert / alert_resolved / notification
 */

type WsHandler = (data: Record<string, unknown>) => void;

const listeners: Record<string, Set<WsHandler>> = {};
let socketTask: UniApp.SocketTask | null = null;
let connected = false;
let manualClose = false;
let reconnectAttempts = 0;
let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
let pingTimer: ReturnType<typeof setInterval> | null = null;

function wsUrl(): string | null {
  const server = useServerStore();
  const auth = useAuthStore();
  if (!server.current || !auth.accessToken) return null;
  // http(s)://host[:port]  →  ws(s)://host[:port]/ws
  const base = server.current.url.replace(/^http/i, 'ws').replace(/\/+$/, '');
  return `${base}/ws?token=${encodeURIComponent(auth.accessToken)}`;
}

function dispatch(type: string, data: Record<string, unknown>) {
  listeners[type]?.forEach((h) => {
    try { h(data); } catch { /* 单个订阅者异常不影响其他 */ }
  });
}

function startPing() {
  stopPing();
  pingTimer = setInterval(() => {
    if (connected && socketTask) {
      try { socketTask.send({ data: JSON.stringify({ type: 'ping' }) }); } catch { /* ignore */ }
    }
  }, 25000);
}
function stopPing() {
  if (pingTimer) { clearInterval(pingTimer); pingTimer = null; }
}

function scheduleReconnect() {
  if (manualClose) return;
  if (reconnectTimer) return;
  const delay = Math.min(1000 * 2 ** reconnectAttempts, 30000); // 1s,2s,4s… 封顶 30s
  reconnectAttempts++;
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    connectWs();
  }, delay);
}

export function connectWs(): void {
  const url = wsUrl();
  if (!url) return;
  if (socketTask && connected) return;

  manualClose = false;
  const task = uni.connectSocket({ url, complete: () => { /* noop */ } });
  socketTask = task;

  task.onOpen(() => {
    connected = true;
    reconnectAttempts = 0;
    startPing();
  });

  task.onMessage((res) => {
    try {
      const msg = JSON.parse(res.data as string) as { type?: string } & Record<string, unknown>;
      if (msg.type && msg.type !== 'pong' && msg.type !== 'connected') {
        dispatch(msg.type, msg);
      }
    } catch { /* 非 JSON 忽略 */ }
  });

  task.onClose(() => {
    connected = false;
    stopPing();
    socketTask = null;
    scheduleReconnect();
  });

  task.onError(() => {
    connected = false;
    stopPing();
    // onError 后通常紧跟 onClose；此处不重复调度
  });
}

export function closeWs(): void {
  manualClose = true;
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
  stopPing();
  if (socketTask) {
    try { socketTask.close({}); } catch { /* ignore */ }
    socketTask = null;
  }
  connected = false;
}

/** 订阅某类型消息，返回取消订阅函数 */
export function onWs(type: string, handler: WsHandler): () => void {
  (listeners[type] ??= new Set()).add(handler);
  // 有订阅者时确保连接已建立
  connectWs();
  return () => listeners[type]?.delete(handler);
}
