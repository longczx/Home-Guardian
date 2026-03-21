import request from './request';

export interface MetricDefinition {
  id: number;
  metric_key: string;
  label: string;
  unit: string;
  icon: string;
  description: string | null;
  sort_order: number;
}

export function getMetricDefinitions() {
  return request.get<{ code: number; data: MetricDefinition[] }>('/metric-definitions');
}
