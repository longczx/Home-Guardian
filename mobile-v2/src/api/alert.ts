import request from './request';

export interface AlertLog {
  id: number;
  alert_rule_id: number;
  rule_name: string;
  device_id: number;
  device_name: string;
  device_location: string | null;
  metric_key: string;
  triggered_value: unknown;
  message: string;
  status: 'triggered' | 'acknowledged' | 'resolved';
  triggered_at: string;
  acknowledged_at: string | null;
  resolved_at: string | null;
}

export interface AlertRule {
  id: number;
  name: string;
  device_id: number;
  device_name?: string;
  metric_key: string;
  condition: string;
  threshold_value: number;
  threshold_value2?: number;
  duration_seconds: number;
  is_enabled: boolean;
  notification_channel_ids: number[];
  created_at: string;
}

export function getAlertLogs(params?: Record<string, string | number>) {
  return request.get<{ code: number; data: { items: AlertLog[]; total: number } }>('/alert-logs', { params });
}
export function getAlertLog(id: number) {
  return request.get<{ code: number; data: AlertLog }>(`/alert-logs/${id}`);
}
export function acknowledgeAlert(id: number) {
  return request.put(`/alert-logs/${id}/acknowledge`);
}
export function resolveAlert(id: number) {
  return request.put(`/alert-logs/${id}/resolve`);
}
export function batchAcknowledgeAlerts(ids: number[]) {
  return request.post('/alert-logs/batch-acknowledge', { ids });
}
export function batchResolveAlerts(ids: number[]) {
  return request.post('/alert-logs/batch-resolve', { ids });
}

export function getAlertRules(params?: Record<string, string | number | boolean>) {
  return request.get<{ code: number; data: { items: AlertRule[]; total: number } }>('/alert-rules', { params });
}
export function getAlertRule(id: number) {
  return request.get<{ code: number; data: AlertRule }>(`/alert-rules/${id}`);
}
export function createAlertRule(data: Partial<AlertRule>) {
  return request.post<{ code: number; data: AlertRule }>('/alert-rules', data);
}
export function updateAlertRule(id: number, data: Partial<AlertRule>) {
  return request.put<{ code: number; data: AlertRule }>(`/alert-rules/${id}`, data);
}
export function deleteAlertRule(id: number) {
  return request.delete(`/alert-rules/${id}`);
}
export function toggleAlertRule(id: number, enabled: boolean) {
  return request.put(`/alert-rules/${id}/toggle`, { is_enabled: enabled });
}
export function batchToggleAlertRules(ids: number[], enabled: boolean) {
  return request.post('/alert-rules/batch-toggle', { ids, is_enabled: enabled });
}
