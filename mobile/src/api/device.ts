import request from './request';
import type { MetricField } from '@/utils/metricLookup';

export type ControlWidget = 'switch' | 'stepper' | 'slider' | 'enum' | 'button' | 'number' | 'text';

export interface ControlPoint {
  key: string;
  label: string;
  widget: ControlWidget;
  value_type: 'bool' | 'int' | 'float' | 'string' | 'enum';
  command: string;
  param: string;
  state_key?: string;
  min?: number;
  max?: number;
  step?: number;
  unit?: string;
  options?: { label: string; value: string | number; icon?: string }[];
  default?: unknown;
  depends_on?: Record<string, unknown>;
  group?: string;
  order?: number;
  icon?: string;
}

export interface Capability {
  control_mode: 'merge' | 'discrete';
  controls: ControlPoint[];
}

export interface Device {
  id: number;
  device_uid: string;
  name: string;
  type: string;
  location: string | null;
  firmware_version: string | null;
  is_online: boolean;
  last_seen: string | null;
  metric_fields: MetricField[] | null;
  gateway_uid: string | null;
  capability: Capability | null;
  state?: Record<string, unknown>;
}

export interface DeviceAttribute {
  key: string;
  value: unknown;
}

export interface PaginatedResponse<T> {
  items: T[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export function getDevices(params?: Record<string, string | number | boolean>) {
  return request.get<{ code: number; data: PaginatedResponse<Device> }>('/devices', { params });
}

export function getDevice(id: number) {
  return request.get<{ code: number; data: Device }>(`/devices/${id}`);
}

export function sendCommand(id: number, payload: Record<string, unknown>) {
  return request.post<{ code: number; data: { request_id: string; status: string } }>(
    `/devices/${id}/command`,
    payload
  );
}

export function getDeviceAttributes(deviceId: number) {
  return request.get<{ code: number; data: DeviceAttribute[] }>(`/devices/${deviceId}/attributes`);
}

export function setDeviceAttributes(deviceId: number, attrs: Record<string, unknown>) {
  return request.put(`/devices/${deviceId}/attributes`, attrs);
}
