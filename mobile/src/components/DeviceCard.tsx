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
    <div
      className="device-row-card"
      onClick={() => navigate(`/mobile/device/${device.id}`)}
      style={{
        marginBottom: 10,
        cursor: 'pointer',
        padding: '14px 16px',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <div style={{
          width: 44, height: 44, borderRadius: 14,
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
            <span style={{ padding: '3px 7px', borderRadius: 999, background: 'var(--color-fill)', color: 'var(--color-text-secondary)', fontSize: 11 }}>
              {device.type}
            </span>
          </div>
          <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 2 }}>
            {device.location || '未分配位置'}
          </div>
        </div>
        {topMetrics.length > 0 && (
          <div style={{ textAlign: 'right', flexShrink: 0 }}>
            {topMetrics.map((m) => {
              const meta = lookup(m.metric_key);
              return (
                <div key={m.metric_key} style={{ fontSize: 12, color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
                  <span style={{ color: 'var(--color-text-tertiary)', marginRight: 4 }}>{meta.icon}</span>
                  {typeof m.value === 'number' ? m.value.toFixed(1) : String(m.value)}
                  <span style={{ color: 'var(--color-text-tertiary)', marginLeft: 2 }}>{meta.unit}</span>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
