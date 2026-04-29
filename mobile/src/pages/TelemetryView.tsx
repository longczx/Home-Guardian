import { useState, useEffect, useMemo } from 'react';
import { NavBar, Picker, Selector } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { getDevices, type Device } from '@/api/device';
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

export default function TelemetryView() {
  const navigate = useNavigate();
  const [devices, setDevices] = useState<Device[]>([]);
  const [selectedDevice, setSelectedDevice] = useState<number | null>(null);
  const [metricKeys, setMetricKeys] = useState<string[]>([]);
  const [metricKey, setMetricKey] = useState('');
  const [range, setRange] = useState('24h');
  const [chartData, setChartData] = useState<AggregatedPoint[]>([]);
  const [pickerVisible, setPickerVisible] = useState(false);
  const [isBootstrapping, setIsBootstrapping] = useState(true);
  const [isChartLoading, setIsChartLoading] = useState(false);
  const { definitions, fetchDefinitions } = useMetricDefinitionStore();

  useEffect(() => { fetchDefinitions(); }, [fetchDefinitions]);

  const selectedDeviceObj = devices.find((d) => d.id === selectedDevice);

  const metricLookup = useMemo(
    () => buildMetricLookup(selectedDeviceObj?.metric_fields, definitions),
    [selectedDeviceObj?.metric_fields, definitions],
  );

  useEffect(() => {
    setIsBootstrapping(true);
    getDevices({ per_page: 200 })
      .then(({ data: res }) => {
        if (res.code === 0) {
          setDevices(res.data.items);
          if (res.data.items.length > 0 && !selectedDevice) {
            setSelectedDevice(res.data.items[0].id);
          }
        }
      })
      .finally(() => setIsBootstrapping(false));
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
    const [start, end] = getTelemetryRange(range);
    setIsChartLoading(true);
    getAggregatedTelemetry(selectedDevice, metricKey, start, end)
      .then(({ data: res }) => {
        if (res.code === 0) setChartData(res.data);
      })
      .finally(() => setIsChartLoading(false));
  }, [selectedDevice, metricKey, range]);

  if (isBootstrapping) {
    return <TelemetryPageSkeleton />;
  }

  const deviceColumns = [devices.map((d) => ({ label: `${d.name} (${d.location || '未知'})`, value: d.id }))];
  const selectedDeviceName = devices.find((d) => d.id === selectedDevice)?.name || '选择设备';

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
    series: [{
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
    }],
  };

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        数据图表
      </NavBar>

      <div>
        <div className="screen-header" style={{ marginTop: 8 }}>
          <div>
            <div className="screen-header__title">数据图表</div>
            <div className="screen-header__subtitle">跨设备查看同一指标在不同房间中的变化。</div>
          </div>
        </div>

        <div className="detail-hero-panel detail-hero-panel--metric">
          <div className="detail-hero-panel__main">
            <div className="detail-hero-panel__eyebrow">whole home telemetry</div>
            <div className="detail-hero-panel__title">全屋趋势分析</div>
            <div className="detail-hero-panel__subtitle">切换设备、指标和时间区间，统一查看历史趋势。</div>
            <div className="page-hero__meta">
              <span className="soft-chip">设备 {devices.length}</span>
              <span className="soft-chip">当前 {selectedDeviceName}</span>
              <span className="soft-chip">数据源 {getTelemetrySourceLabel(range)}</span>
              <span className="soft-chip">时区 {APP_TIME_ZONE_LABEL}</span>
            </div>
          </div>
          <div className="detail-hero-panel__aside">
            <div className="detail-kpi-card">
              <span>当前设备</span>
              <strong>{selectedDeviceName}</strong>
            </div>
          </div>
        </div>

        <div className="selector-group" style={{ marginTop: 16 }}>
          <div className="surface-card selector-field selector-field--layered">
            <div className="selector-field__label">选择设备</div>
            <div onClick={() => setPickerVisible(true)} className="selector-field__value">
              {selectedDeviceName}
            </div>
          </div>
          <Picker
            columns={deviceColumns}
            visible={pickerVisible}
            onClose={() => setPickerVisible(false)}
            onConfirm={(val) => { if (val[0]) setSelectedDevice(val[0] as number); }}
            value={selectedDevice ? [selectedDevice] : []}
          />

          {metricKeys.length > 0 && (
            <div className="surface-card selector-field selector-field--layered">
              <div className="selector-field__label">遥测指标</div>
            <Selector
              options={metricKeys.map((k) => ({ label: metricLookup(k).label, value: k }))}
              value={[metricKey]}
              onChange={(v) => { if (v.length) setMetricKey(v[0]); }}
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
              <div className="chart-panel__title">{metricKey ? metricLookup(metricKey).label : '暂无指标'}</div>
              <div className="chart-panel__subtitle">设备聚合曲线，时间按 UTC+08 解析显示。</div>
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
