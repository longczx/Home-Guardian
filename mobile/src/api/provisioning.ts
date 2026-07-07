import request from './request';

export interface ProvisionCodeResp {
  provision_code: string;
  expires_at: string;
  expires_in: number;
}

export interface ProvisionDevice {
  id: number;
  device_uid: string;
  name: string;
  is_online: boolean;
}

export interface ProvisionStatus {
  status: 'pending' | 'registered' | 'expired';
  device: ProvisionDevice | null;
}

/** 生成配对码（可选预设位置） */
export function createProvisionCode(location?: string) {
  return request.post<{ code: number; data: ProvisionCodeResp }>('/provisioning/codes', { location });
}

/** 轮询配对码状态 */
export function getProvisionStatus(code: string) {
  return request.get<{ code: number; data: ProvisionStatus }>(
    `/provisioning/codes/${encodeURIComponent(code)}/status`,
  );
}
