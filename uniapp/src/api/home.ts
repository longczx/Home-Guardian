import request from './request';

export interface InviteCode {
  id: number;
  code: string;
  role: 'admin' | 'member';
  expires_at: string;
  used_by: number | null;
  status: 'pending' | 'used' | 'expired';
  creator?: { id: number; username: string };
  used_by_user?: { id: number; username: string } | null;
}

export interface HomeMember {
  user_id: number;
  role: 'owner' | 'admin' | 'member';
  joined_at: string;
  user: { id: number; username: string; full_name: string | null };
}

export interface HomeInfo {
  home: { id: number; name: string };
  my_role: 'owner' | 'admin' | 'member';
  members: HomeMember[];
}

export function getHome() {
  return request.get<HomeInfo>('/home');
}

export function getInvites() {
  return request.get<InviteCode[]>('/invites');
}

export function createInvite(role: 'admin' | 'member' = 'member', ttl?: number) {
  return request.post<InviteCode>('/invites', { role, ttl });
}

export function deleteInvite(id: number) {
  return request.del(`/invites/${id}`);
}
