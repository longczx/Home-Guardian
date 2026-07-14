import request from './request';
import type { Paginated } from './types';

export interface NotificationChannel {
  id: number;
  name: string;
  type: string;
  config: Record<string, unknown>;
  is_enabled: boolean;
}

export function getChannels(params?: Record<string, string | number>) {
  return request.get<Paginated<NotificationChannel>>('/notification-channels', params);
}

export function testChannel(id: number) {
  return request.post(`/notification-channels/${id}/test`);
}
