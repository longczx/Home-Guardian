import type { MetricDefinition } from '@/api/metricDefinition';

export interface MetricField {
  key: string;
  label: string;
  unit: string;
  icon: string;
}

export interface MetricMeta {
  label: string;
  unit: string;
  icon: string;
}

type MetricLookupFn = (key: string) => MetricMeta;

const DEFAULT_META: MetricMeta = { label: '', unit: '', icon: '📊' };

/**
 * 构建指标查找函数
 *
 * 查找优先级：设备配置 → 全局定义 → 原始 key 兜底
 */
export function buildMetricLookup(
  deviceMetricFields: MetricField[] | null | undefined,
  globalDefinitions: MetricDefinition[],
): MetricLookupFn {
  // 构建全局定义的 map
  const globalMap = new Map<string, MetricMeta>();
  for (const def of globalDefinitions) {
    globalMap.set(def.metric_key, { label: def.label, unit: def.unit, icon: def.icon });
  }

  // 构建设备配置的 map
  const deviceMap = new Map<string, MetricMeta>();
  if (Array.isArray(deviceMetricFields)) {
    for (const field of deviceMetricFields) {
      deviceMap.set(field.key, { label: field.label, unit: field.unit, icon: field.icon });
    }
  }

  return (key: string): MetricMeta => {
    // 优先设备配置
    const fromDevice = deviceMap.get(key);
    if (fromDevice) return fromDevice;

    // 全局定义
    const fromGlobal = globalMap.get(key);
    if (fromGlobal) return fromGlobal;

    // 兜底
    return { ...DEFAULT_META, label: key };
  };
}
