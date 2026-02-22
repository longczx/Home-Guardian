import request from './request';

export interface LoginResult {
  access_token: string;
  refresh_token: string;
  user: {
    id: number;
    username: string;
    roles: string[];
  };
}

export function login(username: string, password: string) {
  return request.post<{ code: number; data: LoginResult }>('/auth/login', {
    username,
    password,
  });
}

export function getMe() {
  return request.get<{ code: number; data: { id: number; username: string; roles: string[] } }>('/auth/me');
}

export function logout(refreshToken: string) {
  return request.post('/auth/logout', { refresh_token: refreshToken });
}
