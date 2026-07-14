import request from './request';
import type { AlertLog, Paginated } from './types';

export function getAlertLogs(params?: Record<string, string | number>) {
  return request.get<Paginated<AlertLog>>('/alert-logs', params);
}

export function acknowledgeAlert(id: number) {
  return request.patch(`/alert-logs/${id}/acknowledge`);
}

export function resolveAlert(id: number) {
  return request.patch(`/alert-logs/${id}/resolve`);
}
