import request from './request';

export interface AlertRule {
  id: number;
  name: string;
  device_id: number;
  device?: { id: number; name: string; location: string };
  telemetry_key: string;
  condition: string;
  threshold_value: number[];
  trigger_duration_sec: number;
  notification_channel_ids: number[];
  is_enabled: boolean;
  created_at: string;
  updated_at: string;
}

export interface AlertRuleForm {
  name: string;
  device_id: number;
  telemetry_key: string;
  condition: string;
  threshold_value: number[];
  trigger_duration_sec: number;
  notification_channel_ids: number[];
  is_enabled?: boolean;
}

export function getAlertRules(params?: Record<string, string | number | boolean>) {
  return request.get<{ code: number; data: { items: AlertRule[]; total: number; current_page: number; last_page: number } }>(
    '/alert-rules', { params }
  );
}

export function getAlertRule(id: number) {
  return request.get<{ code: number; data: AlertRule }>(`/alert-rules/${id}`);
}

export function createAlertRule(data: AlertRuleForm) {
  return request.post<{ code: number; data: AlertRule }>('/alert-rules', data);
}

export function updateAlertRule(id: number, data: Partial<AlertRuleForm>) {
  return request.put<{ code: number; data: AlertRule }>(`/alert-rules/${id}`, data);
}

export function deleteAlertRule(id: number) {
  return request.delete(`/alert-rules/${id}`);
}
