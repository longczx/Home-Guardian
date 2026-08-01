import request from './request';

/**
 * 遥测指标字典（metric_definitions）
 *
 * 全局可维护的字段定义，作为"设备上报字段"的唯一事实来源。
 * 设备编辑时从这里勾选，key 自动对齐，避免手打拼错导致图表/告警对不上。
 */
export interface MetricDefinition {
  id: number;
  metric_key: string;
  label: string;
  unit: string;
  icon: string;
  description: string | null;
  sort_order: number;
}

export interface MetricDefinitionInput {
  metric_key: string;
  label: string;
  unit?: string;
  icon?: string;
  description?: string;
  sort_order?: number;
}

/** 全部指标定义（按 sort_order 排序，不分页） */
export function getMetricDefinitions() {
  return request.get<MetricDefinition[]>('/metric-definitions');
}

/** 新增指标定义（需 devices.create 权限） */
export function createMetricDefinition(data: MetricDefinitionInput) {
  return request.post<MetricDefinition>('/metric-definitions', data as Record<string, unknown>);
}
