import { create } from 'zustand';
import { getMetricDefinitions, type MetricDefinition } from '@/api/metricDefinition';

interface MetricDefinitionState {
  definitions: MetricDefinition[];
  loaded: boolean;
  fetchDefinitions: () => Promise<void>;
}

export const useMetricDefinitionStore = create<MetricDefinitionState>((set, get) => ({
  definitions: [],
  loaded: false,

  fetchDefinitions: async () => {
    if (get().loaded) return;
    try {
      const { data: res } = await getMetricDefinitions();
      if (res.code === 0) {
        set({ definitions: res.data, loaded: true });
      }
    } catch {
      /* ignore */
    }
  },
}));
