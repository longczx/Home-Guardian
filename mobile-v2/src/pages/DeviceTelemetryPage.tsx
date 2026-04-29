import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import ReactECharts from 'echarts-for-react';
import { getDevice } from '@/api/device';
import { getTelemetryHistory, getTelemetryMetrics } from '@/api/telemetry';
import { useWsStore } from '@/stores/wsStore';
import { ChartSkeleton, Skeleton } from '@/components/Skeleton';
import { EmptyState, ErrorState } from '@/components/ui';
import { BarChart2 } from 'lucide-react';

const TIME_RANGES = [
  { label: '1h', value: '1h' },
  { label: '24h', value: '24h' },
  { label: '7d', value: '7d' },
  { label: '30d', value: '30d' },
];

const METRIC_LABELS: Record<string, string> = {
  temperature: '温度 (°C)', humidity: '湿度 (%)', pressure: '气压 (hPa)',
  pm25: 'PM2.5 (μg/m³)', co2: 'CO₂ (ppm)', power: '功率 (W)',
  voltage: '电压 (V)', current: '电流 (A)', brightness: '亮度 (lux)',
};

function buildChartOption(metricKey: string, data: { ts: string; value: unknown }[], isDark: boolean) {
  const textColor = isDark ? '#94a3b8' : '#64748b';
  const gridColor = isDark ? '#1e293b' : '#f1f5f9';
  const lineColor = '#6366f1';

  const points = data.map((p) => [new Date(p.ts).getTime(), Number(p.value)]);
  return {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'axis',
      backgroundColor: isDark ? '#1e293b' : '#fff',
      borderColor: isDark ? '#334155' : '#e2e8f0',
      textStyle: { color: textColor },
      formatter: (params: { value: [number, number] }[]) => {
        const [ts, val] = params[0].value;
        return `${new Date(ts).toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })}<br/>${val.toFixed(2)}`;
      },
    },
    grid: { left: 12, right: 12, top: 12, bottom: 40, containLabel: true },
    xAxis: {
      type: 'time',
      axisLabel: { color: textColor, fontSize: 10 },
      axisLine: { lineStyle: { color: gridColor } },
      splitLine: { lineStyle: { color: gridColor } },
    },
    yAxis: {
      type: 'value',
      axisLabel: { color: textColor, fontSize: 10 },
      splitLine: { lineStyle: { color: gridColor } },
    },
    series: [{
      name: METRIC_LABELS[metricKey] || metricKey,
      type: 'line',
      data: points,
      smooth: true,
      symbol: 'none',
      lineStyle: { color: lineColor, width: 2.5 },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [
            { offset: 0, color: 'rgba(99,102,241,0.25)' },
            { offset: 1, color: 'rgba(99,102,241,0)' },
          ],
        },
      },
    }],
  };
}

export default function DeviceTelemetryPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [deviceName, setDeviceName] = useState('');
  const [metrics, setMetrics] = useState<string[]>([]);
  const [activeMetric, setActiveMetric] = useState('');
  const [activeRange, setActiveRange] = useState('24h');
  const [chartData, setChartData] = useState<{ ts: string; value: unknown }[]>([]);
  const [loadingMetrics, setLoadingMetrics] = useState(true);
  const [loadingChart, setLoadingChart] = useState(false);
  const [error, setError] = useState(false);
  const isDark = document.documentElement.classList.contains('dark');

  useEffect(() => {
    if (!id) return;
    Promise.all([getDevice(Number(id)), getTelemetryMetrics(Number(id))]).then(([devRes, mRes]) => {
      setDeviceName(devRes.data.data.name);
      const keys = mRes.data.data;
      setMetrics(keys);
      if (keys.length > 0) setActiveMetric(keys[0]);
    }).catch(() => setError(true)).finally(() => setLoadingMetrics(false));
  }, [id]);

  useEffect(() => {
    if (!id || !activeMetric) return;
    setLoadingChart(true);
    getTelemetryHistory(Number(id), activeMetric, { range: activeRange })
      .then((res) => setChartData(res.data.data))
      .catch(() => setChartData([]))
      .finally(() => setLoadingChart(false));
  }, [id, activeMetric, activeRange]);

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100 ml-1">
          {deviceName || '设备图表'}
        </h1>
      </div>

      <div className="px-4 pb-6 space-y-4">
        {loadingMetrics ? (
          <div className="space-y-3">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <ChartSkeleton />
          </div>
        ) : error ? (
          <ErrorState message="加载失败" />
        ) : metrics.length === 0 ? (
          <EmptyState icon={BarChart2} title="暂无遥测数据" description="该设备尚未上报任何数据" />
        ) : (
          <>
            {/* Metric selector */}
            <div>
              <p className="text-xs text-slate-500 dark:text-slate-400 font-medium mb-2">选择指标</p>
              <div className="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4">
                {metrics.map((m) => (
                  <button
                    key={m}
                    onClick={() => setActiveMetric(m)}
                    className={`flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors
                      ${activeMetric === m ? 'bg-indigo-500 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400'}`}
                  >
                    {METRIC_LABELS[m] || m}
                  </button>
                ))}
              </div>
            </div>

            {/* Time range */}
            <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
              {TIME_RANGES.map(({ label, value }) => (
                <button
                  key={value}
                  onClick={() => setActiveRange(value)}
                  className={`flex-1 py-2 rounded-xl text-sm font-medium transition-all
                    ${activeRange === value ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}
                >
                  {label}
                </button>
              ))}
            </div>

            {/* Chart */}
            <div className="card p-4">
              <h2 className="text-base font-bold text-slate-800 dark:text-slate-200 mb-1">
                {METRIC_LABELS[activeMetric] || activeMetric}
              </h2>
              <p className="text-xs text-slate-400 dark:text-slate-500 mb-4">
                过去 {TIME_RANGES.find(r => r.value === activeRange)?.label} 的数据趋势
              </p>
              {loadingChart ? (
                <div className="h-48 flex items-center justify-center">
                  <div className="w-6 h-6 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin" />
                </div>
              ) : chartData.length === 0 ? (
                <div className="h-48 flex items-center justify-center">
                  <p className="text-sm text-slate-400 dark:text-slate-500">该时间段内暂无数据</p>
                </div>
              ) : (
                <ReactECharts
                  option={buildChartOption(activeMetric, chartData, isDark)}
                  style={{ height: 220 }}
                  notMerge
                />
              )}
            </div>

            {/* Summary stats */}
            {chartData.length > 0 && (() => {
              const vals = chartData.map((p) => Number(p.value)).filter(Number.isFinite);
              const avg = vals.reduce((a, b) => a + b, 0) / vals.length;
              const min = Math.min(...vals);
              const max = Math.max(...vals);
              return (
                <div className="grid grid-cols-3 gap-3">
                  {[{ label: '平均', value: avg }, { label: '最小', value: min }, { label: '最大', value: max }].map(({ label, value }) => (
                    <div key={label} className="card p-3 text-center">
                      <p className="text-lg font-black text-slate-900 dark:text-slate-100">{value.toFixed(1)}</p>
                      <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{label}</p>
                    </div>
                  ))}
                </div>
              );
            })()}
          </>
        )}
      </div>
    </div>
  );
}
