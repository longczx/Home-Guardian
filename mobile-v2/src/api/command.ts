import request from './request';

export interface CommandLog {
  id: number;
  request_id: string;
  device_id: number;
  device_name?: string;
  topic: string;
  payload: Record<string, unknown>;
  status: 'pending' | 'sent' | 'success' | 'failed' | 'timeout';
  response: Record<string, unknown> | null;
  sent_by: number | null;
  username?: string;
  sent_at: string;
  replied_at: string | null;
}

export function getCommandLogs(params?: Record<string, string | number>) {
  return request.get<{ code: number; data: { items: CommandLog[]; total: number; last_page: number } }>(
    '/command-logs', { params }
  );
}
