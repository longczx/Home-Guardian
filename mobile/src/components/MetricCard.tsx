import type { ReactNode } from 'react';

interface MetricCardProps {
  icon?: ReactNode;
  label: string;
  value: string | number;
  unit?: string;
  trend?: 'up' | 'down' | null;
}

export default function MetricCard({ icon, label, value, unit, trend }: MetricCardProps) {
  return (
    <div style={{
      background: 'var(--color-bg-card)',
      borderRadius: 'var(--card-radius)',
      padding: '14px 12px',
      boxShadow: 'var(--card-shadow)',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8 }}>
        {icon && <span style={{ fontSize: 16, color: 'var(--color-primary)' }}>{icon}</span>}
        <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>{label}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 2 }}>
        <span style={{ fontSize: 24, fontWeight: 700, color: 'var(--color-text)' }}>{value}</span>
        {unit && <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>{unit}</span>}
        {trend && (
          <span style={{
            fontSize: 14, marginLeft: 4,
            color: trend === 'up' ? 'var(--color-danger)' : 'var(--color-success)',
          }}>
            {trend === 'up' ? '↑' : '↓'}
          </span>
        )}
      </div>
    </div>
  );
}
