import { useState, useEffect, useMemo } from 'react';
import { NavBar, Selector } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getDevice, type Device } from '@/api/device';
import { getLatestTelemetry, getAggregatedTelemetry, type LatestMetric, type AggregatedPoint } from '@/api/telemetry';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';
import { buildMetricLookup } from '@/utils/metricLookup';
import { APP_TIME_ZONE_LABEL, formatTelemetryAxisLabel, getTelemetryRange, getTelemetrySourceLabel } from '@/utils/dateTime';
import TelemetryChart from '@/components/TelemetryChart';
import TelemetryPageSkeleton from '@/components/TelemetryPageSkeleton';

const RANGES = [
  { label: '1小时', value: '1h' },
  { label: '24小时', value: '24h' },
  { label: '7天', value: '7d' },
  { label: '30天', value: '30d' },
];

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
    const [start, end] = getTelemetryRange(range);
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
        return formatTelemetryAxisLabel(p.bucket, range);
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
        <div className="screen-header" style={{ marginTop: 8 }}>
          <div>
            <div className="screen-header__title">{device?.name || '遥测数据'}</div>
            <div className="screen-header__subtitle">{device?.location || '设备空间'} · 单设备趋势分析</div>
          </div>
        </div>

        <div className="detail-hero-panel detail-hero-panel--metric">
          <div className="detail-hero-panel__main">
            <div className="detail-hero-panel__eyebrow">device telemetry</div>
            <div className="detail-hero-panel__title">房间维度趋势查看</div>
            <div className="detail-hero-panel__subtitle">按指标和时间范围查看当前设备的历史趋势和变化峰值。</div>
            <div className="page-hero__meta">
              <span className="soft-chip">指标 {metricKeys.length}</span>
              <span className="soft-chip">时间范围 {RANGES.find((item) => item.value === range)?.label || range}</span>
              <span className="soft-chip">数据源 {getTelemetrySourceLabel(range)}</span>
              <span className="soft-chip">时区 {APP_TIME_ZONE_LABEL}</span>
            </div>
          </div>
          <div className="detail-hero-panel__aside">
            <div className="detail-kpi-card">
              <span>当前设备</span>
              <strong>{device?.name || '--'}</strong>
              <small>{device?.location || '未分配位置'}</small>
            </div>
          </div>
        </div>

        <div className="selector-group" style={{ marginTop: 16 }}>
          {metricKeys.length > 0 && (
            <div className="surface-card selector-field selector-field--layered">
              <div className="selector-field__label">遥测指标</div>
            <Selector
              options={metricKeys.map((k) => ({ label: metricLookup(k).label, value: k }))}
              value={[selectedKey]}
              onChange={(v) => { if (v.length) setSelectedKey(v[0]); }}
            />
            </div>
          )}

          <div className="surface-card selector-field selector-field--layered">
            <div className="selector-field__label">时间范围</div>
            <Selector
              options={RANGES}
              value={[range]}
              onChange={(v) => { if (v.length) setRange(v[0]); }}
            />
          </div>
        </div>

        <div className="surface-card chart-panel chart-panel--elevated" style={{ marginTop: 16 }}>
          <div className="chart-panel__header">
            <div>
              <div className="chart-panel__title">{selectedKey ? metricLookup(selectedKey).label : '暂无指标'}</div>
              <div className="chart-panel__subtitle">聚合均值曲线，时间按 UTC+08 解析显示。</div>
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
