import { useEffect, useRef, useCallback } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useWSStore } from '@/stores/wsStore';
import { useDeviceStore } from '@/stores/deviceStore';
import { useAlertStore } from '@/stores/alertStore';

export function useGlobalWebSocket() {
  const token = useAuthStore((s) => s.accessToken);

  const dispatch = useCallback((msg: { type: string; data: unknown }) => useWSStore.getState().dispatch(msg), []);
  const setConnected = useCallback((v: boolean) => useWSStore.getState().setConnected(v), []);
  const updateDeviceStatus = useCallback((uid: string, online: boolean) => useDeviceStore.getState().updateDeviceStatus(uid, online), []);
  const updateMetric = useCallback((id: number, key: string, value: unknown, ts: string) => useDeviceStore.getState().updateMetric(id, key, value, ts), []);
  const alertIncrement = useCallback(() => useAlertStore.getState().increment(), []);
  const wsRef = useRef<WebSocket | null>(null);
  const reconnectRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => {
    if (!token) return;

    let disposed = false;

    const connect = () => {
      if (disposed) return;
      const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const host = window.location.host;
      const ws = new WebSocket(`${protocol}//${host}/ws?token=${token}`);

      ws.onopen = () => {
        setConnected(true);
      };

      ws.onmessage = (event) => {
        try {
          const msg = JSON.parse(event.data);
          dispatch(msg);

          if (msg.type === 'device_status') {
            updateDeviceStatus(msg.device_uid as string, msg.is_online as boolean);
          }
          if (msg.type === 'telemetry') {
            const dId = msg.device_id as number;
            const data = msg.data as Record<string, unknown>;
            const ts = (msg.ts as string) || new Date().toISOString();
            if (data && typeof data === 'object') {
              for (const [key, value] of Object.entries(data)) {
                updateMetric(dId, key, value, ts);
              }
            }
          }
          if (msg.type === 'alert') {
            alertIncrement();
          }
        } catch { /* ignore */ }
      };

      ws.onclose = () => {
        setConnected(false);
        if (!disposed) {
          reconnectRef.current = setTimeout(connect, 3000);
        }
      };

      ws.onerror = () => ws.close();
      wsRef.current = ws;
    };

    connect();

    // Fetch initial unread count
    useAlertStore.getState().fetchUnreadCount();

    return () => {
      disposed = true;
      clearTimeout(reconnectRef.current);
      wsRef.current?.close();
    };
  }, [token, dispatch, setConnected, updateDeviceStatus, updateMetric, alertIncrement]);
}
