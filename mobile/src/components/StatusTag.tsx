import { Tag } from 'antd-mobile';

type StatusType = 'online' | 'offline' | 'triggered' | 'acknowledged' | 'resolved'
  | 'pending' | 'delivered' | 'success' | 'failed' | 'timeout';

const config: Record<StatusType, { color: string; label: string }> = {
  online: { color: 'success', label: '在线' },
  offline: { color: 'default', label: '离线' },
  triggered: { color: 'danger', label: '触发中' },
  acknowledged: { color: 'warning', label: '已确认' },
  resolved: { color: 'success', label: '已解决' },
  pending: { color: 'default', label: '待处理' },
  delivered: { color: 'primary', label: '已送达' },
  success: { color: 'success', label: '成功' },
  failed: { color: 'danger', label: '失败' },
  timeout: { color: 'warning', label: '超时' },
};

interface StatusTagProps {
  status?: StatusType | string;
  online?: boolean;
}

export default function StatusTag({ status = '', online }: StatusTagProps) {
  const key = online !== undefined ? (online ? 'online' : 'offline') : status;
  const c = config[key as StatusType] || { color: 'default', label: key };
  return (
    <Tag color={c.color as 'success' | 'danger' | 'warning' | 'primary' | 'default'} fill="outline">
      {c.label}
    </Tag>
  );
}
