import { ErrorBlock } from 'antd-mobile';

interface EmptyStateProps {
  title?: string;
  description?: string;
}

export default function EmptyState({ title = '暂无数据', description = '' }: EmptyStateProps) {
  return (
    <ErrorBlock
      status="empty"
      title={title}
      description={description}
      style={{ '--image-height': '120px', padding: '48px 0' }}
    />
  );
}
