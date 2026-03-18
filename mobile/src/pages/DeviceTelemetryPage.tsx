import { useState, useEffect } from 'react';
import { NavBar, Selector } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import ReactECharts from 'echarts-for-react';
import { getDevice, type Device } from '@/api/device';
import { getLatestTelemetry, getAggregatedTelemetry, type LatestMetric, type AggregatedPoint } from '@/api/telemetry';

const RANGES = [
  { label: '1小时', value: '1h' },
  { label: '24小时', value: '24h' },
  { label: '7天', value: '7d' },
  { label: '30天', value: '30d' },
];

function getRangeDate(range: string): [string, string] {
  const now = new Date();
  const end = now.toISOString();
  const msMap: Record<string, number> = {
    '1h': 3600000, '24h': 86400000, '7d': 604800000, '30d': 2592000000,
  };
  const start = new Date(now.getTime() - (msMap[range] || 86400000));
  return [start.toISOString(), end];
}

export default function DeviceTelemetryPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [device, setDevice] = useState<Device | null>(null);
  const [metricKeys, setMetricKeys] = useState<string[]>([]);
  const [selectedKey, setSelectedKey] = useState('');
  const [range, setRange] = useState('24h');
  const [chartData, setChartData] = useState<AggregatedPoint[]>([]);

  const deviceId = id ? parseInt(id) : 0;

  useEffect(() => {
    if (!deviceId) return;
    Promise.all([getDevice(deviceId), getLatestTelemetry(deviceId)]).then(([dRes, tRes]) => {
      if (dRes.data.code === 0) setDevice(dRes.data.data);
      if (tRes.data.code === 0) {
        const keys = tRes.data.data.map((m: LatestMetric) => m.metric_key);
        setMetricKeys(keys);
        if (keys.length > 0 && !selectedKey) setSelectedKey(keys[0]);
      }
    });
  }, [deviceId, selectedKey]);

  useEffect(() => {
    if (!deviceId || !selectedKey) return;
    const [start, end] = getRangeDate(range);
    getAggregatedTelemetry(deviceId, selectedKey, start, end).then(({ data: res }) => {
      if (res.code === 0) setChartData(res.data);
    });
  }, [deviceId, selectedKey, range]);

  const chartOption = {
    tooltip: { trigger: 'axis' as const },
    grid: { left: 50, right: 20, top: 20, bottom: 30 },
    xAxis: {
      type: 'category' as const,
      data: chartData.map((p) => {
        const d = new Date(p.bucket);
        return range === '7d' || range === '30d'
          ? `${d.getMonth() + 1}/${d.getDate()}`
          : `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`;
      }),
      axisLabel: { fontSize: 10, color: 'var(--color-text-tertiary)' },
    },
    yAxis: { type: 'value' as const, axisLabel: { color: 'var(--color-text-tertiary)' } },
    series: [
      {
        name: '平均值',
        type: 'line',
        data: chartData.map((p) => p.avg_value),
        smooth: true,
        areaStyle: { opacity: 0.1 },
        lineStyle: { width: 2, color: '#1677ff' },
        itemStyle: { color: '#1677ff' },
      },
    ],
  };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        {device?.name || '遥测数据'}
      </NavBar>

      <div style={{ padding: '12px 16px' }}>
        {metricKeys.length > 0 && (
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 6 }}>遥测指标</div>
            <Selector
              options={metricKeys.map((k) => ({ label: k, value: k }))}
              value={[selectedKey]}
              onChange={(v) => { if (v.length) setSelectedKey(v[0]); }}
            />
          </div>
        )}

        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 6 }}>时间范围</div>
          <Selector
            options={RANGES}
            value={[range]}
            onChange={(v) => { if (v.length) setRange(v[0]); }}
          />
        </div>

        <div style={{ background: 'var(--color-bg-card)', borderRadius: 'var(--card-radius)', padding: 12, boxShadow: 'var(--card-shadow)' }}>
          {chartData.length > 0 ? (
            <ReactECharts option={chartOption} style={{ height: 280 }} />
          ) : (
            <div style={{ textAlign: 'center', color: 'var(--color-text-tertiary)', padding: 40 }}>暂无数据</div>
          )}
        </div>
      </div>
    </div>
  );
}
