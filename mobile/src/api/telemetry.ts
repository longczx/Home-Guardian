import request from './request';

export interface TelemetryPoint {
  ts: string;
  device_id: number;
  metric_key: string;
  value: unknown;
}

export interface LatestMetric {
  metric_key: string;
  value: unknown;
  ts: string;
}

export interface AggregatedPoint {
  bucket: string;
  avg_value: number;
  min_value: number;
  max_value: number;
  sample_count: number;
}

export interface AlertLog {
  id: number;
  rule: { id: number; name: string } | null;
  device: { id: number; name: string; location: string } | null;
  status: string;
  triggered_at: string;
  resolved_at: string | null;
  acknowledged_at: string | null;
  triggered_value: unknown;
  message: string | null;
}

export function getLatestTelemetry(deviceId: number) {
  return request.get<{ code: number; data: LatestMetric[] }>('/telemetry/latest', {
    params: { device_id: deviceId },
  });
}

export function getRawTelemetry(params: Record<string, string | number>) {
  return request.get<{ code: number; data: { items: TelemetryPoint[]; total: number; current_page: number; last_page: number } }>(
    '/telemetry', { params }
  );
}

export function getAggregatedTelemetry(
  deviceId: number,
  metricKey: string,
  start: string,
  end: string
) {
  return request.get<{ code: number; data: AggregatedPoint[] }>('/telemetry/aggregated', {
    params: { device_id: deviceId, metric_key: metricKey, start, end },
  });
}

export interface GroupedAlert {
  rule_id: number;
  device_id: number;
  status: string;
  rule_name: string;
  device_name: string;
  device_location: string;
  alert_count: number;
  latest_triggered_at: string;
  earliest_triggered_at: string;
}

export function getAlertLogs(params?: Record<string, string | number>) {
  return request.get<{
    code: number;
    data: { items: AlertLog[]; total: number; current_page: number; last_page: number };
  }>('/alert-logs', { params });
}

export function getGroupedAlertLogs(params?: Record<string, string | number>) {
  return request.get<{ code: number; data: GroupedAlert[] }>('/alert-logs/grouped', { params });
}

export function getAlertLog(id: number) {
  return request.get<{ code: number; data: AlertLog }>(`/alert-logs/${id}`);
}

export function acknowledgeAlert(id: number) {
  return request.patch(`/alert-logs/${id}/acknowledge`);
}

export function batchAcknowledgeAlerts(ruleId: number, deviceId: number) {
  return request.patch('/alert-logs/batch-acknowledge', { rule_id: ruleId, device_id: deviceId });
}

export function batchResolveAlerts(ruleId: number, deviceId: number) {
  return request.patch('/alert-logs/batch-resolve', { rule_id: ruleId, device_id: deviceId });
}

export function resolveAlert(id: number) {
  return request.patch(`/alert-logs/${id}/resolve`);
}
