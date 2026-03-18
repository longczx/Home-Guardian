import type { AutomationAction } from '@/api/automation';

interface ActionSummaryProps {
  actions: AutomationAction[];
}

const actionLabels: Record<string, string> = {
  send_command: '发送指令',
  send_notification: '发送通知',
};

export default function ActionSummary({ actions }: ActionSummaryProps) {
  if (!actions || actions.length === 0) {
    return <span style={{ fontSize: 13, color: 'var(--color-text-tertiary)' }}>无动作</span>;
  }

  return (
    <span style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
      {actions.map((a, i) => (
        <span key={i}>
          {actionLabels[a.type] || a.type}
          {a.config?.device_name ? ` → ${a.config.device_name}` : ''}
          {i < actions.length - 1 ? '，' : ''}
        </span>
      ))}
    </span>
  );
}
