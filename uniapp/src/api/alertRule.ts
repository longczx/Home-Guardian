import request from './request';
import type { Paginated } from './types';

export interface AlertRule {
  id: number;
  name: string;
  description: string | null;
  device_id: number;
  device?: { id: number; name: string; location: string | null };
  trigger_type: 'telemetry' | 'offline';
  telemetry_key: string | null;
  condition: string | null;
  threshold_value: number[] | null;
  trigger_duration_sec: number;
  offline_timeout_sec: number;
  severity: 'info' | 'warning' | 'critical';
  notify_cooldown_sec: number;
  notify_on_recovery: boolean;
  notification_channel_ids: number[];
  is_enabled: boolean;
}

export type AlertRuleInput = Partial<AlertRule>;

export function getAlertRules(params?: Record<string, string | number>) {
  return request.get<Paginated<AlertRule>>('/alert-rules', params);
}

export function getAlertRule(id: number) {
  return request.get<AlertRule>(`/alert-rules/${id}`);
}

export function createAlertRule(data: AlertRuleInput) {
  return request.post<AlertRule>('/alert-rules', data as Record<string, unknown>);
}

export function updateAlertRule(id: number, data: AlertRuleInput) {
  return request.put<AlertRule>(`/alert-rules/${id}`, data as Record<string, unknown>);
}

export function deleteAlertRule(id: number) {
  return request.del(`/alert-rules/${id}`);
}

export const CONDITIONS = [
  { value: 'GREATER_THAN', label: '大于 (>)' },
  { value: 'LESS_THAN', label: '小于 (<)' },
  { value: 'EQUALS', label: '等于 (=)' },
  { value: 'NOT_EQUALS', label: '不等于 (≠)' },
  { value: 'BETWEEN', label: '区间内' },
  { value: 'NOT_BETWEEN', label: '区间外' },
];

export const SEVERITIES = [
  { value: 'info', label: '提醒' },
  { value: 'warning', label: '警告' },
  { value: 'critical', label: '严重' },
];
