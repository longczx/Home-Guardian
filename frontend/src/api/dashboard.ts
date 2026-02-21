import request from './request'

export function getDashboardStats() {
  return request.get<any, any>('/devices', { params: { per_page: 1 } })
}

export function getAlertLogsApi(params?: Record<string, any>) {
  return request.get<any, any>('/alert-logs', { params })
}

export function getTelemetryLatest(params?: Record<string, any>) {
  return request.get<any, any>('/telemetry/latest', { params })
}
