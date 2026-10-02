import request from './request';
export interface Diagnostic {
  device: { name: string; type: string; is_online: boolean; last_seen: string | null; firmware_version: string | null };
  gateway: { name: string; is_online: boolean } | null;
  last_telemetry_at: string | null;
  info: { firmware?: string; wifi_rssi?: number; uptime_s?: number; free_heap?: number; module_count?: number };
  info_at: string | null;
  state: { reported_at: string | null; updated_at: string } | null;
  commands: { id: number; action: string; status: string; sent_at: string; replied_at: string | null }[];
}
export interface AutomationRun {
  id: number; automation_name: string; status: string; trigger_type: string; started_at: string; finished_at: string | null;
  trigger_context: Record<string, unknown>;
  action_results: { index: number; type: string; status: string; action?: string; error?: string; command_status?: string; deliveries?: { channel_id: number; status: string; attempts: number }[] }[];
}
export interface Backup { id: string; status: string; created_at: string; size_bytes?: number; commit?: string; error?: string }
export const getDiagnostics = (id: number) => request.get<Diagnostic>(`/devices/${id}/diagnostics`);
export const probeDiagnostics = (id: number) => request.post<{ request_id: string; status: string }>(`/devices/${id}/diagnostics/probe`);
export const getAutomationRuns = (page: number) => request.get<{ items: AutomationRun[]; total: number }>('/automation-runs', { page, per_page: 20 });
export const getBackups = () => request.get<{ items: Backup[] }>('/backups');
export const statusLabel = (status: string) => ({ running: '等待执行结果', pending: '等待结果', queued: '已排队', sent: '已发送到 MQTT',
  delivered: '等待设备回执', replied_ok: '设备已确认', replied_error: '设备执行失败', timeout: '设备确认超时',
  success: '成功', partial_failed: '部分失败', failed: '失败', skipped: '已跳过' }[status] || status);
