import { sendCommand, getCommandResult } from '@/api/device';
import { withCommandResult } from './command-result';
import { useServerStore } from '@/stores/server';

export type ModuleType = 'dht11' | 'sound' | 'ac_ir';
export interface GatewayModule { uid: string; type: ModuleType; pin: number; name: string }
export interface ModuleSnapshot {
  modules: GatewayModule[];
  module_config_version?: number;
  pins?: Partial<Record<ModuleType, number[]>>;
  max_modules?: number;
  board?: string;
}

export async function readGatewayModules(id: number): Promise<ModuleSnapshot> {
  const server = useServerStore();
  const revision = server.connectionRevision;
  const reply = await withCommandResult(() => sendCommand(id, { action: 'list_modules', params: {} }), 20000, getCommandResult);
  if (server.connectionRevision !== revision) throw new Error('服务器已切换，请重新进入设备页面');
  if (!Array.isArray(reply.modules)) throw new Error('设备未返回模块清单，请升级固件后重试');
  if (reply.modules.some(m => !m || typeof m.uid !== 'string' || typeof m.name !== 'string'
    || !['dht11', 'sound', 'ac_ir'].includes(m.type) || !Number.isInteger(m.pin))) {
    throw new Error('设备模块清单格式异常，请检查固件');
  }
  return reply as unknown as ModuleSnapshot;
}

export function modulePinError(snapshot: ModuleSnapshot, type: ModuleType, pin: number, exceptUid = ''): string {
  if (snapshot.module_config_version !== 1 || !snapshot.pins) return '请升级到 1.4.0 或更新固件后管理模块';
  if (!Number.isInteger(pin) || !snapshot.pins[type]?.includes(pin)) return '请选择设备允许的 GPIO 引脚';
  if (snapshot.modules.some(m => m.uid !== exceptUid && m.pin === pin)) return 'GPIO 已被其他模块占用';
  if (type === 'ac_ir' && snapshot.modules.some(m => m.uid !== exceptUid && m.type === 'ac_ir')) return '目前只支持一路红外发射模块';
  return '';
}

export async function changeGatewayModule(
  id: number, action: 'add_module' | 'update_module' | 'remove_module', params: Record<string, unknown>,
): Promise<ModuleSnapshot> {
  const server = useServerStore();
  const revision = server.connectionRevision;
  const reply = await withCommandResult(() => sendCommand(id, { action, params }), 65000, getCommandResult);
  if (server.connectionRevision !== revision) throw new Error('服务器已切换，请重新进入设备页面');
  if (reply.applied !== true || typeof reply.uid !== 'string') throw new Error('设备未确认配置生效，请升级固件或刷新核对');
  if ((action !== 'add_module' && reply.uid !== params.uid) || (action !== 'remove_module' && reply.pin !== params.pin)) {
    throw new Error('设备执行回执与操作不一致，请刷新核对');
  }
  const snapshot = await readGatewayModules(id);
  const module = snapshot.modules.find(m => m.uid === reply.uid);
  if (action === 'remove_module' ? !!module : !module || module.pin !== params.pin || (action === 'add_module' && module.type !== params.type)) {
    throw new Error('设备读回配置与操作不一致，请刷新核对');
  }
  return snapshot;
}
