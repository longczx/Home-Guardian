import { create } from 'zustand';
import { getAlertLogs } from '@/api/telemetry';

interface AlertState {
  unreadCount: number;
  fetchUnreadCount: () => Promise<void>;
  increment: () => void;
  decrement: () => void;
}

export const useAlertStore = create<AlertState>((set) => ({
  unreadCount: 0,

  fetchUnreadCount: async () => {
    try {
      const { data: res } = await getAlertLogs({ status: 'triggered', per_page: 1 });
      if (res.code === 0) {
        set({ unreadCount: res.data.total });
      }
    } catch { /* ignore */ }
  },

  increment: () => set((s) => ({ unreadCount: s.unreadCount + 1 })),
  decrement: () => set((s) => ({ unreadCount: Math.max(0, s.unreadCount - 1) })),
}));
