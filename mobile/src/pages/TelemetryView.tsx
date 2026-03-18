import { useState, useEffect } from 'react';
import { NavBar, Picker, Selector } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import ReactECharts from 'echarts-for-react';
import { getDevices, type Device } from '@/api/device';
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
  return [new Date(now.getTime() - (msMap[range] || 86400000)).toISOString(), end];
}

export default function TelemetryView() {
  const navigate = useNavigate();
  const [devices, setDevices] = useState<Device[]>([]);
  const [selectedDevice, setSelectedDevice] = useState<number | null>(null);
  const [metricKeys, setMetricKeys] = useState<string[]>([]);
  const [metricKey, setMetricKey] = useState('');
  const [range, setRange] = useState('24h');
  const [chartData, setChartData] = useState<AggregatedPoint[]>([]);
  const [pickerVisible, setPickerVisible] = useState(false);

  useEffect(() => {
    getDevices({ per_page: 200 }).then(({ data: res }) => {
      if (res.code === 0) {
        setDevices(res.data.items);
        if (res.data.items.length > 0 && !selectedDevice) {
          setSelectedDevice(res.data.items[0].id);
        }
      }
    });
  }, [selectedDevice]);

  useEffect(() => {
    if (!selectedDevice) return;
    getLatestTelemetry(selectedDevice).then(({ data: res }) => {
      if (res.code === 0) {
        const keys = res.data.map((m: LatestMetric) => m.metric_key);
        setMetricKeys(keys);
        if (keys.length > 0 && (!metricKey || !keys.includes(metricKey))) {
          setMetricKey(keys[0]);
        }
      }
    });
  }, [selectedDevice, metricKey]);

  useEffect(() => {
    if (!selectedDevice || !metricKey) return;
    const [start, end] = getRangeDate(range);
    getAggregatedTelemetry(selectedDevice, metricKey, start, end).then(({ data: res }) => {
      if (res.code === 0) setChartData(res.data);
    });
  }, [selectedDevice, metricKey, range]);

  const deviceColumns = [devices.map((d) => ({ label: `${d.name} (${d.location || '未知'})`, value: d.id }))];
  const selectedDeviceName = devices.find((d) => d.id === selectedDevice)?.name || '选择设备';

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
    series: [{
      name: '平均值',
      type: 'line',
      data: chartData.map((p) => p.avg_value),
      smooth: true,
      areaStyle: { opacity: 0.1 },
      lineStyle: { width: 2, color: '#1677ff' },
      itemStyle: { color: '#1677ff' },
    }],
  };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        数据图表
      </NavBar>

      <div style={{ padding: '12px 16px' }}>
        {/* Device Picker */}
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 6 }}>选择设备</div>
          <div
            onClick={() => setPickerVisible(true)}
            style={{
              padding: '10px 14px', borderRadius: 8, background: 'var(--color-fill)',
              color: 'var(--color-text)', fontSize: 14, cursor: 'pointer',
            }}
          >
            {selectedDeviceName}
          </div>
          <Picker
            columns={deviceColumns}
            visible={pickerVisible}
            onClose={() => setPickerVisible(false)}
            onConfirm={(val) => { if (val[0]) setSelectedDevice(val[0] as number); }}
            value={selectedDevice ? [selectedDevice] : []}
          />
        </div>

        {/* Metric Selector */}
        {metricKeys.length > 0 && (
          <div style={{ marginBottom: 12 }}>
            <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 6 }}>遥测指标</div>
            <Selector
              options={metricKeys.map((k) => ({ label: k, value: k }))}
              value={[metricKey]}
              onChange={(v) => { if (v.length) setMetricKey(v[0]); }}
            />
          </div>
        )}

        {/* Range Selector */}
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 6 }}>时间范围</div>
          <Selector
            options={RANGES}
            value={[range]}
            onChange={(v) => { if (v.length) setRange(v[0]); }}
          />
        </div>

        {/* Chart */}
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
