import request from './request';

export interface ProvisionCodeResp {
  provision_code: string;
  expires_at: string;
  expires_in: number;
}

export interface ProvisionStatus {
  status: 'pending' | 'registered' | 'expired';
  stage: 'pending' | 'expired' | 'connecting' | 'awaiting_data' | 'ready';
  device: { id: number; device_uid: string; name: string; is_online: boolean } | null;
}

/** 生成配对码（可选预设位置） */
export function createProvisionCode(location?: string, rebind_device_id?: number) {
  return request.post<ProvisionCodeResp>('/provisioning/codes', { location, rebind_device_id });
}

/** 轮询配对码状态 */
export function getProvisionStatus(code: string) {
  return request.get<ProvisionStatus>(`/provisioning/codes/${encodeURIComponent(code)}/status`);
}
