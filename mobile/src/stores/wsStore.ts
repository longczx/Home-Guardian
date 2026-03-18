import { create } from 'zustand';

type WSHandler = (msg: WSMessage) => void;

export interface WSMessage {
  type: string;
  data: unknown;
}

interface WSState {
  connected: boolean;
  setConnected: (v: boolean) => void;
  handlers: Map<string, Set<WSHandler>>;
  subscribe: (type: string, handler: WSHandler) => () => void;
  dispatch: (msg: WSMessage) => void;
}

export const useWSStore = create<WSState>((set, get) => ({
  connected: false,
  setConnected: (v) => set({ connected: v }),
  handlers: new Map(),

  subscribe: (type, handler) => {
    const { handlers } = get();
    if (!handlers.has(type)) handlers.set(type, new Set());
    handlers.get(type)!.add(handler);
    return () => {
      handlers.get(type)?.delete(handler);
    };
  },

  dispatch: (msg) => {
    const { handlers } = get();
    handlers.get(msg.type)?.forEach((h) => h(msg));
    handlers.get('*')?.forEach((h) => h(msg));
  },
}));
