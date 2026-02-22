interface MetricDisplayProps {
  label: string;
  value: string | number;
  unit?: string;
}

export default function MetricDisplay({ label, value, unit }: MetricDisplayProps) {
  return (
    <div style={{ textAlign: 'center', padding: '8px 0' }}>
      <div style={{ fontSize: 24, fontWeight: 'bold', color: '#333' }}>
        {value}
        {unit && <span style={{ fontSize: 14, fontWeight: 'normal', color: '#999' }}> {unit}</span>}
      </div>
      <div style={{ fontSize: 12, color: '#999', marginTop: 4 }}>{label}</div>
    </div>
  );
}
