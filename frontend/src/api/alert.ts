import request from './request'

export function getAlertRules(params?: Record<string, any>) {
  return request.get<any, any>('/alert-rules', { params })
}

export function getAlertRule(id: number) {
  return request.get<any, any>(`/alert-rules/${id}`)
}

export function createAlertRule(data: Record<string, any>) {
  return request.post<any, any>('/alert-rules', data)
}

export function updateAlertRule(id: number, data: Record<string, any>) {
  return request.put<any, any>(`/alert-rules/${id}`, data)
}

export function deleteAlertRule(id: number) {
  return request.delete<any, any>(`/alert-rules/${id}`)
}

export function getAlertLogs(params?: Record<string, any>) {
  return request.get<any, any>('/alert-logs', { params })
}

export function getAlertLog(id: number) {
  return request.get<any, any>(`/alert-logs/${id}`)
}

export function acknowledgeAlert(id: number) {
  return request.patch<any, any>(`/alert-logs/${id}/acknowledge`)
}

export function resolveAlert(id: number) {
  return request.patch<any, any>(`/alert-logs/${id}/resolve`)
}
