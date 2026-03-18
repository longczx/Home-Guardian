import request from './request';

export interface Automation {
  id: number;
  name: string;
  trigger_type: 'telemetry' | 'schedule';
  trigger_config: Record<string, unknown>;
  actions: AutomationAction[];
  is_enabled: boolean;
  last_triggered_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface AutomationAction {
  type: 'send_command' | 'send_notification';
  config: Record<string, unknown>;
}

export interface AutomationForm {
  name: string;
  trigger_type: 'telemetry' | 'schedule';
  trigger_config: Record<string, unknown>;
  actions: AutomationAction[];
  is_enabled?: boolean;
}

export function getAutomations(params?: Record<string, string | number | boolean>) {
  return request.get<{ code: number; data: { items: Automation[]; total: number; current_page: number; last_page: number } }>(
    '/automations', { params }
  );
}

export function getAutomation(id: number) {
  return request.get<{ code: number; data: Automation }>(`/automations/${id}`);
}

export function createAutomation(data: AutomationForm) {
  return request.post<{ code: number; data: Automation }>('/automations', data);
}

export function updateAutomation(id: number, data: Partial<AutomationForm>) {
  return request.put<{ code: number; data: Automation }>(`/automations/${id}`, data);
}

export function deleteAutomation(id: number) {
  return request.delete(`/automations/${id}`);
}
