import request from './request';

export interface Device {
  id: number;
  device_uid: string;
  name: string;
  type: string;
  location: string | null;
  firmware_version: string | null;
  is_online: boolean;
  last_seen: string | null;
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
