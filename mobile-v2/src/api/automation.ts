import request from './request';

export interface Automation {
  id: number;
  name: string;
  is_enabled: boolean;
  trigger_type: 'telemetry' | 'schedule';
  trigger_config: Record<string, unknown>;
  actions: AutomationAction[];
  last_triggered_at: string | null;
  created_at: string;
}

export interface AutomationAction {
  type: 'device_command' | 'notify';
  device_id?: number;
  payload?: Record<string, unknown>;
  channel_ids?: number[];
}

export function getAutomations(params?: Record<string, string | number | boolean>) {
  return request.get<{ code: number; data: { items: Automation[]; total: number } }>('/automations', { params });
}
export function getAutomation(id: number) {
  return request.get<{ code: number; data: Automation }>(`/automations/${id}`);
}
export function createAutomation(data: Partial<Automation>) {
  return request.post<{ code: number; data: Automation }>('/automations', data);
}
export function updateAutomation(id: number, data: Partial<Automation>) {
  return request.put<{ code: number; data: Automation }>(`/automations/${id}`, data);
}
export function deleteAutomation(id: number) {
  return request.delete(`/automations/${id}`);
}
export function toggleAutomation(id: number, enabled: boolean) {
  return request.put(`/automations/${id}/toggle`, { is_enabled: enabled });
}
