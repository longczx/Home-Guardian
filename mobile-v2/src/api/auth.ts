import request from './request';

export interface LoginParams { username: string; password: string; }
export interface LoginResult { access_token: string; refresh_token: string; user: UserInfo; }
export interface UserInfo { id: number; username: string; roles: string[]; permissions: Record<string, string[] | boolean>; locations: string[]; }

export function login(params: LoginParams) {
  return request.post<{ code: number; data: LoginResult }>('/auth/login', params);
}
export function logout(refreshToken: string) {
  return request.post('/auth/logout', { refresh_token: refreshToken });
}
export function logoutAll() {
  return request.post('/auth/logout-all');
}
export function changePassword(params: { current_password: string; new_password: string }) {
  return request.put('/auth/password', params);
}
export function getMe() {
  return request.get<{ code: number; data: UserInfo }>('/auth/me');
}
