import { Tag } from 'antd-mobile';

interface StatusTagProps {
  online: boolean;
}

export default function StatusTag({ online }: StatusTagProps) {
  return (
    <Tag color={online ? 'success' : 'default'} fill="outline">
      {online ? '在线' : '离线'}
    </Tag>
  );
}
