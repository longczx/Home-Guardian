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

export function getLatestTelemetry(deviceId: number) {
  return request.get<{ code: number; data: LatestMetric[] }>('/telemetry/latest', {
    params: { device_id: deviceId },
  });
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

export interface AlertLog {
  id: number;
  rule: { id: number; name: string } | null;
  device: { id: number; name: string; location: string } | null;
  status: string;
  triggered_at: string;
  triggered_value: unknown;
}

export function getAlertLogs(params?: Record<string, string | number>) {
  return request.get<{
    code: number;
    data: { items: AlertLog[]; total: number; current_page: number; last_page: number };
  }>('/alert-logs', { params });
}

export function acknowledgeAlert(id: number) {
  return request.patch(`/alert-logs/${id}/acknowledge`);
}
