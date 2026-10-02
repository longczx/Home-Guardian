import request from './request';
import type { Device } from './types';
export type HomeMode = 'home' | 'away' | 'sleep';
export const modes: { value: HomeMode; label: string }[] = [{ value: 'home', label: '在家' }, { value: 'away', label: '离家' }, { value: 'sleep', label: '睡眠' }];
export interface HomeOverview { mode: HomeMode; mode_changed_at: string | null; devices: Device[]; rooms: string[]; offline_count: number; active_alerts: number }
export interface PolicyCheck { label: string; passed: boolean; reason: string; observed_at?: string }
export interface RulePreview { eligible: boolean; checks: PolicyCheck[]; conditions: PolicyCheck[]; note: string }
export const getHomeOverview = () => request.get<HomeOverview>('/home/overview');
export const setHomeMode = (mode: HomeMode) => request.put('/home/mode', { mode });
export const setFavorite = (id: number, is_favorite: boolean) => request.put(`/devices/${id}/favorite`, { is_favorite });
export const setOverride = (id: number, minutes: number) => request.put(`/devices/${id}/override`, { minutes });
export const previewAutomation = (id: number) => request.get<RulePreview>(`/automations/${id}/preview`);
export const handleAlert = (id: number, note: string) => request.patch(`/alert-logs/${id}/handling`, { note });
export interface Room { id: number; name: string; sort_order: number }
export const getRooms = () => request.get<Room[]>('/rooms');
export const saveRoom = (name: string, sort_order: number) => request.put('/rooms', { name, sort_order });
export const deleteRoom = (id: number) => request.del(`/rooms/${id}`);
