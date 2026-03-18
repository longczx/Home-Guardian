import { create } from 'zustand';
import { type Device, getDevices } from '@/api/device';
import { type LatestMetric } from '@/api/telemetry';

interface DeviceState {
  devices: Device[];
  metricsMap: Record<number, LatestMetric[]>;
  loading: boolean;
  fetchDevices: () => Promise<void>;
  updateDeviceStatus: (deviceUid: string, online: boolean) => void;
  setDeviceMetrics: (deviceId: number, metrics: LatestMetric[]) => void;
  updateMetric: (deviceId: number, key: string, value: unknown, ts: string) => void;
}

export const useDeviceStore = create<DeviceState>((set, get) => ({
  devices: [],
  metricsMap: {},
  loading: false,

  fetchDevices: async () => {
    set({ loading: true });
    try {
      const { data: res } = await getDevices({ per_page: 200 });
      if (res.code === 0) {
        set({ devices: res.data.items });
      }
    } finally {
      set({ loading: false });
    }
  },

  updateDeviceStatus: (deviceUid, online) => {
    set({
      devices: get().devices.map((d) =>
        d.device_uid === deviceUid ? { ...d, is_online: online, last_seen: new Date().toISOString() } : d
      ),
    });
  },

  setDeviceMetrics: (deviceId, metrics) => {
    set({ metricsMap: { ...get().metricsMap, [deviceId]: metrics } });
  },

  updateMetric: (deviceId, key, value, ts) => {
    const current = get().metricsMap[deviceId] || [];
    const exists = current.findIndex((m) => m.metric_key === key);
    const updated = exists >= 0
      ? current.map((m, i) => (i === exists ? { ...m, value, ts } : m))
      : [...current, { metric_key: key, value, ts }];
    set({ metricsMap: { ...get().metricsMap, [deviceId]: updated } });
  },
}));
