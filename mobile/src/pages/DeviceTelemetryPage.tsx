import { useState, useEffect, useMemo } from 'react';
import { NavBar, Selector } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getDevice, type Device } from '@/api/device';
import { getLatestTelemetry, getAggregatedTelemetry, type LatestMetric, type AggregatedPoint } from '@/api/telemetry';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';
import { buildMetricLookup } from '@/utils/metricLookup';
import TelemetryChart from '@/components/TelemetryChart';
import TelemetryPageSkeleton from '@/components/TelemetryPageSkeleton';

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
  const [isBootstrapping, setIsBootstrapping] = useState(true);
  const [isChartLoading, setIsChartLoading] = useState(false);

  const deviceId = id ? parseInt(id) : 0;
  const { definitions, fetchDefinitions } = useMetricDefinitionStore();

  useEffect(() => { fetchDefinitions(); }, [fetchDefinitions]);

  const metricLookup = useMemo(
    () => buildMetricLookup(device?.metric_fields, definitions),
    [device?.metric_fields, definitions],
  );

  useEffect(() => {
    if (!deviceId) return;
    setIsBootstrapping(true);
    Promise.all([getDevice(deviceId), getLatestTelemetry(deviceId)])
      .then(([dRes, tRes]) => {
        if (dRes.data.code === 0) setDevice(dRes.data.data);
        if (tRes.data.code === 0) {
          const keys = tRes.data.data.map((m: LatestMetric) => m.metric_key);
          setMetricKeys(keys);
          if (keys.length > 0 && !selectedKey) setSelectedKey(keys[0]);
        }
      })
      .finally(() => setIsBootstrapping(false));
  }, [deviceId, selectedKey]);

  useEffect(() => {
    if (!deviceId || !selectedKey) return;
    const [start, end] = getRangeDate(range);
    setIsChartLoading(true);
    getAggregatedTelemetry(deviceId, selectedKey, start, end)
      .then(({ data: res }) => {
        if (res.code === 0) setChartData(res.data);
      })
      .finally(() => setIsChartLoading(false));
  }, [deviceId, selectedKey, range]);

  if (isBootstrapping) {
    return <TelemetryPageSkeleton />;
  }

  const chartOption = {
    backgroundColor: 'transparent',
    tooltip: { trigger: 'axis' as const },
    grid: { left: 36, right: 12, top: 24, bottom: 28 },
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
    yAxis: {
      type: 'value' as const,
      axisLabel: { color: 'var(--color-text-tertiary)' },
      splitLine: { lineStyle: { color: 'rgba(140, 154, 158, 0.14)' } },
    },
    series: [
      {
        name: '平均值',
        type: 'line',
        data: chartData.map((p) => p.avg_value),
        smooth: true,
        areaStyle: {
          opacity: 0.22,
          color: {
            type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
            colorStops: [
              { offset: 0, color: 'rgba(15, 139, 141, 0.35)' },
              { offset: 1, color: 'rgba(15, 139, 141, 0.02)' },
            ],
          },
        },
        lineStyle: { width: 3, color: '#0f8b8d' },
        itemStyle: { color: '#0f8b8d' },
        symbol: 'circle',
        symbolSize: 6,
      },
    ],
  };

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        {device?.name || '遥测数据'}
      </NavBar>

      <div>
        <div className="page-hero" style={{ marginTop: 8 }}>
          <div className="page-hero__eyebrow">device chart</div>
          <div className="page-hero__title">{device?.name || '遥测数据'}</div>
          <div className="page-hero__subtitle">按指标和时间范围查看单设备趋势变化。</div>
          <div className="page-hero__meta">
            <span className="soft-chip">指标 {metricKeys.length}</span>
            <span className="soft-chip">时间范围 {RANGES.find((item) => item.value === range)?.label || range}</span>
          </div>
        </div>

        <div className="selector-group" style={{ marginTop: 16 }}>
          {metricKeys.length > 0 && (
            <div className="glass-card selector-field">
              <div className="selector-field__label">遥测指标</div>
            <Selector
              options={metricKeys.map((k) => ({ label: metricLookup(k).label, value: k }))}
              value={[selectedKey]}
              onChange={(v) => { if (v.length) setSelectedKey(v[0]); }}
            />
            </div>
          )}

          <div className="glass-card selector-field">
            <div className="selector-field__label">时间范围</div>
            <Selector
              options={RANGES}
              value={[range]}
              onChange={(v) => { if (v.length) setRange(v[0]); }}
            />
          </div>
        </div>

        <div className="glass-card chart-panel" style={{ marginTop: 16 }}>
          <div className="chart-panel__header">
            <div>
              <div className="chart-panel__title">{selectedKey ? metricLookup(selectedKey).label : '暂无指标'}</div>
              <div className="chart-panel__subtitle">聚合均值曲线</div>
            </div>
          </div>
          {isChartLoading ? (
            <div className="telemetry-skeleton__plot" />
          ) : chartData.length > 0 ? (
            <TelemetryChart option={chartOption} />
          ) : (
            <div style={{ textAlign: 'center', color: 'var(--color-text-tertiary)', padding: 40 }}>暂无数据</div>
          )}
        </div>
      </div>
    </div>
  );
}
