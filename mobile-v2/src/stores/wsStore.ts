import { create } from 'zustand';

export interface WsMessage {
  type: 'device_status' | 'telemetry' | 'alert' | 'notification';
  data: Record<string, unknown>;
}

interface WsState {
  connected: boolean;
  unreadAlerts: number;
  deviceStatuses: Record<number, boolean>;
  latestTelemetry: Record<string, { value: unknown; ts: string }>;
  messages: WsMessage[];
  setConnected: (v: boolean) => void;
  incrementUnreadAlerts: () => void;
  clearUnreadAlerts: () => void;
  setDeviceStatus: (deviceId: number, online: boolean) => void;
  setLatestTelemetry: (deviceId: number, metricKey: string, value: unknown, ts: string) => void;
  addMessage: (msg: WsMessage) => void;
}

export const useWsStore = create<WsState>((set) => ({
  connected: false,
  unreadAlerts: 0,
  deviceStatuses: {},
  latestTelemetry: {},
  messages: [],
  setConnected: (v) => set({ connected: v }),
  incrementUnreadAlerts: () => set((s) => ({ unreadAlerts: s.unreadAlerts + 1 })),
  clearUnreadAlerts: () => set({ unreadAlerts: 0 }),
  setDeviceStatus: (deviceId, online) =>
    set((s) => ({ deviceStatuses: { ...s.deviceStatuses, [deviceId]: online } })),
  setLatestTelemetry: (deviceId, metricKey, value, ts) =>
    set((s) => ({
      latestTelemetry: {
        ...s.latestTelemetry,
        [`${deviceId}:${metricKey}`]: { value, ts },
      },
    })),
  addMessage: (msg) =>
    set((s) => ({ messages: [msg, ...s.messages].slice(0, 50) })),
}));
