import { useState, useEffect } from 'react';
import { NavBar, Card, Selector } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import ReactECharts from 'echarts-for-react';
import { getDevices, type Device } from '@/api/device';
import { getAggregatedTelemetry, type AggregatedPoint } from '@/api/telemetry';

const RANGES = [
  { label: '1小时', value: '1h' },
  { label: '24小时', value: '24h' },
  { label: '7天', value: '7d' },
];

function getRangeDate(range: string): [string, string] {
  const now = new Date();
  const end = now.toISOString();
  let start: Date;
  switch (range) {
    case '1h':
      start = new Date(now.getTime() - 60 * 60 * 1000);
      break;
    case '7d':
      start = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
      break;
    default:
      start = new Date(now.getTime() - 24 * 60 * 60 * 1000);
  }
  return [start.toISOString(), end];
}

export default function TelemetryView() {
  const navigate = useNavigate();
  const [devices, setDevices] = useState<Device[]>([]);
  const [selectedDevice, setSelectedDevice] = useState<number | null>(null);
  const [metricKey, setMetricKey] = useState('temperature');
  const [range, setRange] = useState('24h');
  const [chartData, setChartData] = useState<AggregatedPoint[]>([]);

  useEffect(() => {
    getDevices({ per_page: 100 }).then(({ data: res }) => {
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
    const [start, end] = getRangeDate(range);
    getAggregatedTelemetry(selectedDevice, metricKey, start, end).then(
      ({ data: res }) => {
        if (res.code === 0) {
          setChartData(res.data);
        }
      }
    );
  }, [selectedDevice, metricKey, range]);

  const chartOption = {
    tooltip: { trigger: 'axis' as const },
    grid: { left: 50, right: 20, top: 20, bottom: 30 },
    xAxis: {
      type: 'category' as const,
      data: chartData.map((p) => {
        const d = new Date(p.bucket);
        return range === '7d'
          ? `${d.getMonth() + 1}/${d.getDate()}`
          : `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`;
      }),
      axisLabel: { fontSize: 10 },
    },
    yAxis: { type: 'value' as const },
    series: [
      {
        name: '平均值',
        type: 'line',
        data: chartData.map((p) => p.avg_value),
        smooth: true,
        areaStyle: { opacity: 0.1 },
        lineStyle: { width: 2 },
      },
    ],
  };

  return (
    <div style={{ minHeight: '100vh', background: '#f5f5f5' }}>
      <NavBar onBack={() => navigate(-1)}>数据图表</NavBar>

      <Card style={{ margin: 12, borderRadius: 12 }}>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 13, color: '#999', marginBottom: 4 }}>选择设备</div>
          <select
            value={selectedDevice || ''}
            onChange={(e) => setSelectedDevice(Number(e.target.value))}
            style={{
              width: '100%', padding: '8px 12px', borderRadius: 8,
              border: '1px solid #eee', fontSize: 14, background: '#f8f8f8',
            }}
          >
            {devices.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name} ({d.location || '未知'})
              </option>
            ))}
          </select>
        </div>

        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 13, color: '#999', marginBottom: 4 }}>遥测指标</div>
          <input
            value={metricKey}
            onChange={(e) => setMetricKey(e.target.value)}
            placeholder="如 temperature, humidity"
            style={{
              width: '100%', padding: '8px 12px', borderRadius: 8,
              border: '1px solid #eee', fontSize: 14, background: '#f8f8f8',
              boxSizing: 'border-box',
            }}
          />
        </div>

        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 13, color: '#999', marginBottom: 4 }}>时间范围</div>
          <Selector
            options={RANGES}
            value={[range]}
            onChange={(val) => { if (val.length) setRange(val[0]); }}
          />
        </div>
      </Card>

      <Card style={{ margin: 12, borderRadius: 12 }}>
        {chartData.length > 0 ? (
          <ReactECharts option={chartOption} style={{ height: 280 }} />
        ) : (
          <div style={{ textAlign: 'center', color: '#999', padding: 40 }}>
            暂无数据
          </div>
        )}
      </Card>
    </div>
  );
}
