import request from './request';
import type { Paginated } from './types';

export interface AutomationAction {
  type: 'device_command' | 'notify';
  device_id?: number;
  payload?: { action: string; params?: Record<string, unknown> };
  channel_ids?: number[];
}

export interface Automation {
  id: number;
  name: string;
  description: string | null;
  trigger_type: 'telemetry' | 'schedule';
  trigger_config: Record<string, unknown>;
  actions: AutomationAction[];
  is_enabled: boolean;
  last_triggered_at: string | null;
}

export type AutomationInput = {
  name: string;
  description?: string;
  trigger_type: 'telemetry' | 'schedule';
  trigger_config: Record<string, unknown>;
  actions: AutomationAction[];
  is_enabled: boolean;
};

export function getAutomations(params?: Record<string, string | number>) {
  return request.get<Paginated<Automation>>('/automations', params);
}

export function getAutomation(id: number) {
  return request.get<Automation>(`/automations/${id}`);
}

export function createAutomation(data: AutomationInput) {
  return request.post<Automation>('/automations', data as unknown as Record<string, unknown>);
}

export function updateAutomation(id: number, data: AutomationInput) {
  return request.put<Automation>(`/automations/${id}`, data as unknown as Record<string, unknown>);
}

export function setAutomationEnabled(id: number, enabled: boolean) {
  return request.put<Automation>(`/automations/${id}`, { is_enabled: enabled });
}

export function deleteAutomation(id: number) {
  return request.del(`/automations/${id}`);
}
