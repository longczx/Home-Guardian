import request from './request'

export function getDevices(params?: Record<string, any>) {
  return request.get<any, any>('/devices', { params })
}

export function getDevice(id: number) {
  return request.get<any, any>(`/devices/${id}`)
}

export function createDevice(data: Record<string, any>) {
  return request.post<any, any>('/devices', data)
}

export function updateDevice(id: number, data: Record<string, any>) {
  return request.put<any, any>(`/devices/${id}`, data)
}

export function deleteDevice(id: number) {
  return request.delete<any, any>(`/devices/${id}`)
}

export function sendCommand(id: number, data: { command: string; params?: Record<string, any> }) {
  return request.post<any, any>(`/devices/${id}/command`, data)
}

export function getDeviceAttributes(deviceId: number) {
  return request.get<any, any>(`/devices/${deviceId}/attributes`)
}

export function setDeviceAttributes(deviceId: number, data: Record<string, any>) {
  return request.put<any, any>(`/devices/${deviceId}/attributes`, data)
}

export function deleteDeviceAttribute(deviceId: number, key: string) {
  return request.delete<any, any>(`/devices/${deviceId}/attributes/${key}`)
}

export function getCommandLogs(params?: Record<string, any>) {
  return request.get<any, any>('/commands', { params })
}
