import { useEffect, useRef, useCallback } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useWsStore } from '@/stores/wsStore';

const WS_URL = `${window.location.protocol === 'https:' ? 'wss:' : 'ws:'}//${window.location.host}/ws`;
const RECONNECT_DELAY = 3000;
const MAX_RECONNECT_ATTEMPTS = 10;

export function useWebSocket() {
  const wsRef = useRef<WebSocket | null>(null);
  const reconnectAttempts = useRef(0);
  const reconnectTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const { accessToken, isAuthenticated } = useAuthStore();
  const { setConnected, setDeviceStatus, setLatestTelemetry, incrementUnreadAlerts, addMessage } = useWsStore();

  const connect = useCallback(() => {
    if (!isAuthenticated || !accessToken) return;
    if (wsRef.current?.readyState === WebSocket.OPEN) return;

    const url = `${WS_URL}?token=${encodeURIComponent(accessToken)}`;
    const ws = new WebSocket(url);
    wsRef.current = ws;

    ws.onopen = () => {
      setConnected(true);
      reconnectAttempts.current = 0;
    };

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data);
        addMessage(msg);
        switch (msg.type) {
          case 'device_status':
            setDeviceStatus(msg.data.device_id, msg.data.is_online);
            break;
          case 'telemetry':
            setLatestTelemetry(msg.data.device_id, msg.data.metric_key, msg.data.value, msg.data.ts);
            break;
          case 'alert':
            incrementUnreadAlerts();
            break;
        }
      } catch { /* ignore parse errors */ }
    };

    ws.onclose = () => {
      setConnected(false);
      if (reconnectAttempts.current < MAX_RECONNECT_ATTEMPTS && isAuthenticated) {
        reconnectAttempts.current++;
        reconnectTimer.current = setTimeout(connect, RECONNECT_DELAY);
      }
    };

    ws.onerror = () => {
      ws.close();
    };
  }, [accessToken, isAuthenticated, setConnected, setDeviceStatus, setLatestTelemetry, incrementUnreadAlerts, addMessage]);

  useEffect(() => {
    connect();
    return () => {
      if (reconnectTimer.current) clearTimeout(reconnectTimer.current);
      wsRef.current?.close();
    };
  }, [connect]);

  return { connected: useWsStore((s) => s.connected) };
}
