import request from './request';

export interface CommandLog {
  id: number;
  device_id: number;
  device?: { id: number; name: string; location: string };
  request_id: string;
  action: string;
  params: Record<string, unknown>;
  status: 'pending' | 'delivered' | 'success' | 'failed' | 'timeout';
  response: Record<string, unknown> | null;
  sent_at: string;
  responded_at: string | null;
}

export function getCommandLogs(params?: Record<string, string | number>) {
  return request.get<{ code: number; data: { items: CommandLog[]; total: number; current_page: number; last_page: number } }>(
    '/commands', { params }
  );
}

export function getCommandLog(id: number) {
  return request.get<{ code: number; data: CommandLog }>(`/commands/${id}`);
}
