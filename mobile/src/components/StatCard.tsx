import { Card } from 'antd-mobile';
import type { ReactNode } from 'react';

interface StatCardProps {
  icon: ReactNode;
  label: string;
  value: number | string;
  color?: string;
  onClick?: () => void;
}

export default function StatCard({ icon, label, value, color = 'var(--color-primary)', onClick }: StatCardProps) {
  return (
    <Card
      onClick={onClick}
      style={{
        borderRadius: 'var(--card-radius)',
        boxShadow: 'var(--card-shadow)',
        cursor: onClick ? 'pointer' : 'default',
        background: 'var(--color-bg-card)',
      }}
      bodyStyle={{ padding: '16px 12px' }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <div style={{
          width: 40, height: 40, borderRadius: 10,
          background: color + '18',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 20, color,
        }}>
          {icon}
        </div>
        <div>
          <div style={{ fontSize: 22, fontWeight: 700, lineHeight: 1.2, color: 'var(--color-text)' }}>
            {value}
          </div>
          <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 2 }}>
            {label}
          </div>
        </div>
      </div>
    </Card>
  );
}
