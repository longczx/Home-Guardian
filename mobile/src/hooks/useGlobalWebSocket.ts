import { useEffect, useRef, useCallback } from 'react';
import { Toast } from 'antd-mobile';
import { useAuthStore } from '@/stores/authStore';
import { useWSStore } from '@/stores/wsStore';
import { useDeviceStore } from '@/stores/deviceStore';
import { useAlertStore } from '@/stores/alertStore';
import { normalizeBackendTimestamp } from '@/utils/dateTime';

export function useGlobalWebSocket() {
  const token = useAuthStore((s) => s.accessToken);

  const dispatch = useCallback((msg: { type: string; data: unknown }) => useWSStore.getState().dispatch(msg), []);
  const setConnected = useCallback((v: boolean) => useWSStore.getState().setConnected(v), []);
  const updateDeviceStatus = useCallback((uid: string, online: boolean, seenAt?: string) => useDeviceStore.getState().updateDeviceStatus(uid, online, seenAt), []);
  const updateMetric = useCallback((id: number, key: string, value: unknown, ts?: string) => useDeviceStore.getState().updateMetric(id, key, value, ts), []);
  const alertIncrement = useCallback(() => useAlertStore.getState().increment(), []);
  const wsRef = useRef<WebSocket | null>(null);
  const reconnectRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => {
    if (!token) return;

    let disposed = false;

    const clearReconnect = () => {
      if (reconnectRef.current) {
        clearTimeout(reconnectRef.current);
        reconnectRef.current = undefined;
      }
    };

    const scheduleReconnect = () => {
      if (disposed || reconnectRef.current || navigator.onLine === false) {
        return;
      }
      reconnectRef.current = setTimeout(() => {
        reconnectRef.current = undefined;
        connect();
      }, 3000);
    };

    const connect = () => {
      if (disposed || navigator.onLine === false) return;
      if (wsRef.current && (wsRef.current.readyState === WebSocket.OPEN || wsRef.current.readyState === WebSocket.CONNECTING)) {
        return;
      }

      const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const host = window.location.host;
      const ws = new WebSocket(`${protocol}//${host}/ws?token=${token}`);

      ws.onopen = () => {
        clearReconnect();
        setConnected(true);
      };

      ws.onmessage = (event) => {
        try {
          const msg = JSON.parse(event.data);
          dispatch(msg);

          if (msg.type === 'device_status') {
            updateDeviceStatus(
              msg.device_uid as string,
              msg.is_online as boolean,
              normalizeBackendTimestamp(msg.ts),
            );
          }
          if (msg.type === 'telemetry') {
            const dId = msg.device_id as number;
            const data = msg.data as Record<string, unknown>;
            const ts = normalizeBackendTimestamp(msg.ts);
            if (data && typeof data === 'object') {
              for (const [key, value] of Object.entries(data)) {
                updateMetric(dId, key, value, ts);
              }
            }
          }
          if (msg.type === 'alert') {
            alertIncrement();
          }
          if (msg.type === 'notification') {
            const d = msg.data as { title?: string; content?: string } | undefined;
            if (d?.title) {
              Toast.show({ content: d.title, duration: 3000 });
            }
            alertIncrement();
          }
        } catch { /* ignore */ }
      };

      ws.onclose = () => {
        setConnected(false);
        wsRef.current = null;
        scheduleReconnect();
      };

      ws.onerror = () => ws.close();
      wsRef.current = ws;
    };

    const handleOnline = () => {
      clearReconnect();
      connect();
    };

    const handleOffline = () => {
      clearReconnect();
      setConnected(false);
      wsRef.current?.close();
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible' && !useWSStore.getState().connected) {
        clearReconnect();
        connect();
      }
    };

    connect();

    // Fetch initial unread count
    useAlertStore.getState().fetchUnreadCount();

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      disposed = true;
      clearReconnect();
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      wsRef.current?.close();
    };
  }, [token, dispatch, setConnected, updateDeviceStatus, updateMetric, alertIncrement]);
}
