import { Card } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import StatusDot from './StatusDot';
import DeviceIcon from './DeviceIcon';
import type { Device } from '@/api/device';
import type { LatestMetric } from '@/api/telemetry';
import type { MetricMeta } from '@/utils/metricLookup';

interface DeviceCardProps {
  device: Device;
  metrics?: LatestMetric[];
  metricLookup?: (key: string) => MetricMeta;
}

export default function DeviceCard({ device, metrics = [], metricLookup }: DeviceCardProps) {
  const navigate = useNavigate();
  const lookup = metricLookup || ((key: string) => ({ label: key, unit: '', icon: '📊' }));

  const topMetrics = metrics.slice(0, 3);

  return (
    <Card
      onClick={() => navigate(`/mobile/device/${device.id}`)}
      style={{
        marginBottom: 10,
        borderRadius: 'var(--card-radius)',
        boxShadow: 'var(--card-shadow)',
        cursor: 'pointer',
        background: 'var(--color-bg-card)',
      }}
      bodyStyle={{ padding: '12px 16px' }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <div style={{
          width: 40, height: 40, borderRadius: 10,
          background: 'var(--color-primary-light)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
        }}>
          <DeviceIcon type={device.type} size={20} />
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <StatusDot online={device.is_online} />
            <span style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {device.name}
            </span>
          </div>
          <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 2 }}>
            {device.location || '未分配位置'} · {device.type}
          </div>
        </div>
        {topMetrics.length > 0 && (
          <div style={{ textAlign: 'right', flexShrink: 0 }}>
            {topMetrics.map((m) => {
              const meta = lookup(m.metric_key);
              return (
                <div key={m.metric_key} style={{ fontSize: 12, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
                  {typeof m.value === 'number' ? m.value.toFixed(1) : String(m.value)}
                  <span style={{ color: 'var(--color-text-tertiary)', marginLeft: 2 }}>
                    {meta.unit}
                  </span>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </Card>
  );
}
