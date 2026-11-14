import request from './request';
import type { Device, Paginated } from './types';
import type { HomeMode } from './experience';
export interface SceneAction { device_id: number; action: string; params: Record<string, unknown> }
export interface Scene { id: number; name: string; enter_mode: HomeMode | null; actions: SceneAction[] }
export interface SceneRun { id: number; scene_id: number; scene_name: string; status: string; started_at: string; submission_finished: boolean;
  action_results: { index: number; device_id: number; device_name?: string; action: string; status: string; error?: string; infrared_unverified?: boolean }[] }
export const getScenes = () => request.get<{ items: Scene[] }>('/scenes');
export const saveScene = (data: { id?: number; name: string; enter_mode: HomeMode | null; actions: SceneAction[] }) => request.post<Scene>('/scenes', data);
export const deleteScene = (id: number) => request.del(`/scenes/${id}`);
export const executeScene = (id: number, request_key: string) => request.post<SceneRun>(`/scenes/${id}/execute`, { request_key });
export const getSceneRuns = (id?: number, request_key?: string) => request.get<Paginated<SceneRun>>('/scene-runs', id ? { id, request_key } : undefined);
export const modeScene = (mode: HomeMode, request_key: string) => request.put<{ mode: HomeMode; scene_run: SceneRun }>('/home/mode', { mode, execute_scene: true, request_key });
export function describeAction(action: SceneAction, devices: Device[]) {
  const d = devices.find(d => d.id === action.device_id);
  return (d?.name || `设备 #${action.device_id}`) + '：' + Object.entries(action.params).map(([key, value]) => {
    const c = d?.capability?.controls.find(c => c.command === action.action && c.param === key);
    const v = typeof value === 'boolean' ? value ? '开启' : '关闭' : c?.options?.find(o => o.value === value)?.label ?? String(value);
    return `${c?.label || key} ${v}${c?.unit || ''}`;
  }).join('，');
}
