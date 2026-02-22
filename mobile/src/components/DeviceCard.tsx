import { Card } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import StatusTag from './StatusTag';
import type { Device } from '@/api/device';

interface DeviceCardProps {
  device: Device;
}

export default function DeviceCard({ device }: DeviceCardProps) {
  const navigate = useNavigate();

  return (
    <Card
      title={device.name}
      extra={<StatusTag online={device.is_online} />}
      onClick={() => navigate(`/mobile/device/${device.id}`)}
      style={{ marginBottom: 12, borderRadius: 12, cursor: 'pointer' }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, color: '#666' }}>
        <span>{device.location || '未分配位置'}</span>
        <span>{device.type}</span>
      </div>
    </Card>
  );
}
