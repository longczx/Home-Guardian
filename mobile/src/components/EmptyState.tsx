import { ErrorBlock } from 'antd-mobile';

interface EmptyStateProps {
  title?: string;
  description?: string;
}

export default function EmptyState({ title = '暂无数据', description = '' }: EmptyStateProps) {
  return (
    <div className="glass-card glass-card--soft" style={{ padding: '18px 0' }}>
      <ErrorBlock
        status="empty"
        title={title}
        description={description}
        style={{ '--image-height': '120px', padding: '32px 0' }}
      />
    </div>
  );
}
