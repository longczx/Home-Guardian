import request from './request'

export function getTelemetry(params: Record<string, any>) {
  return request.get<any, any>('/telemetry', { params })
}

export function getTelemetryLatest(params?: Record<string, any>) {
  return request.get<any, any>('/telemetry/latest', { params })
}

export function getTelemetryAggregated(params: Record<string, any>) {
  return request.get<any, any>('/telemetry/aggregated', { params })
}
