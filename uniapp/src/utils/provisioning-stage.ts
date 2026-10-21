import type { ProvisionStatus } from '@/api/provisioning';

export function provisioningStage(status: ProvisionStatus): { ready: boolean; label: string } {
  const labels = { pending: '等待设备注册', expired: '配对码已过期', connecting: '已注册，等待 MQTT 连接', awaiting_data: '已连接，等待传感器首次数据', ready: '设备已连接并完成首报' };
  // Older servers cannot prove readiness. Do not turn "registered" into "online".
  const stage = status.stage || (status.status === 'registered' ? 'connecting' : status.status);
  return { ready: stage === 'ready' && !!status.device?.is_online, label: labels[stage] };
}
