import request from './request';

export interface TelemetryPoint { ts: string; value: number | string | boolean | Record<string, unknown>; }
export interface TelemetryLatest { metric_key: string; value: unknown; ts: string; }

export function getTelemetryHistory(
  deviceId: number,
  metricKey: string,
  params: { range?: string; start?: string; end?: string }
) {
  return request.get<{ code: number; data: TelemetryPoint[] }>(
    `/devices/${deviceId}/telemetry/${metricKey}`, { params }
  );
}
export function getTelemetryLatest(deviceId: number) {
  return request.get<{ code: number; data: TelemetryLatest[] }>(
    `/devices/${deviceId}/telemetry/latest`
  );
}
export function getTelemetryMetrics(deviceId: number) {
  return request.get<{ code: number; data: string[] }>(`/devices/${deviceId}/telemetry/metrics`);
}
