import request from './request';
import type { Paginated } from './types';

export interface Automation {
  id: number;
  name: string;
  description: string | null;
  trigger_type: 'telemetry' | 'schedule';
  is_enabled: boolean;
  last_triggered_at: string | null;
}

export function getAutomations(params?: Record<string, string | number>) {
  return request.get<Paginated<Automation>>('/automations', params);
}

export function setAutomationEnabled(id: number, enabled: boolean) {
  return request.put<Automation>(`/automations/${id}`, { is_enabled: enabled });
}

export function deleteAutomation(id: number) {
  return request.del(`/automations/${id}`);
}
