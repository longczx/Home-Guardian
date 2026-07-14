import request from './request';
import type { Device, Paginated, LatestMetric, AggregatedPoint } from './types';

export function getDevices(params?: Record<string, string | number | boolean>) {
  return request.get<Paginated<Device>>('/devices', params);
}

export function getDevice(id: number) {
  return request.get<Device>(`/devices/${id}`);
}

export function sendCommand(id: number, payload: Record<string, unknown>) {
  return request.post<{ request_id: string; status: string }>(`/devices/${id}/command`, payload);
}

export function getLatestTelemetry(deviceId: number) {
  return request.get<LatestMetric[]>('/telemetry/latest', { device_id: deviceId });
}

export function getAggregatedTelemetry(
  deviceId: number,
  metricKey: string,
  start: string,
  end: string,
) {
  return request.get<AggregatedPoint[]>('/telemetry/aggregated', {
    device_id: deviceId,
    metric_key: metricKey,
    start,
    end,
  });
}
