import request from './request';

export interface PushSettings {
  push_enabled: boolean;
  min_severity: 'info' | 'warning' | 'critical';
  registered: boolean;
}

export function registerPushDevice(cid: string, platform: string) {
  return request.post('/push/devices', { cid, platform });
}

export function unregisterPushDevice(cid: string) {
  return request.del('/push/devices', { cid });
}

export function getPushSettings() {
  return request.get<PushSettings>('/push/settings');
}

export function updatePushSettings(data: { push_enabled?: boolean; min_severity?: string }) {
  return request.put('/push/settings', data);
}
