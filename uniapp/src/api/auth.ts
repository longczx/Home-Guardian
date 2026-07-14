import { apiRequest } from './request';
import type { AuthUser } from '@/stores/auth';

export interface LoginResult {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  user: {
    id: number;
    username: string;
    email: string | null;
    roles: string[];
    home_id: number;
    home_role: 'owner' | 'admin' | 'member';
  };
}

export function login(username: string, password: string) {
  return apiRequest<LoginResult>('/auth/login', {
    method: 'POST',
    data: { username, password },
    auth: false,
  });
}

export function register(payload: {
  invite_code: string;
  username: string;
  password: string;
  full_name?: string;
  email?: string;
}) {
  return apiRequest<LoginResult>('/auth/register', {
    method: 'POST',
    data: payload,
    auth: false,
  });
}

export function fetchMe() {
  return apiRequest<AuthUser & { roles: string[] }>('/auth/me');
}

/** 把登录响应里的 user 折叠成 store 需要的精简结构 */
export function toAuthUser(u: LoginResult['user']): AuthUser {
  return {
    id: u.id,
    username: u.username,
    home_id: u.home_id,
    home_role: u.home_role,
  };
}
