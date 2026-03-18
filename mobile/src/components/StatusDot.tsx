interface StatusDotProps {
  online: boolean;
  size?: number;
}

export default function StatusDot({ online, size = 8 }: StatusDotProps) {
  return (
    <span
      style={{
        display: 'inline-block',
        width: size,
        height: size,
        borderRadius: '50%',
        background: online ? 'var(--color-success)' : 'var(--color-text-tertiary)',
        flexShrink: 0,
      }}
    />
  );
}
