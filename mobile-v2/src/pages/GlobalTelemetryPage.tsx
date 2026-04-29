import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import ReactECharts from 'echarts-for-react';
import { getDevices, type Device } from '@/api/device';
import { getTelemetryHistory, getTelemetryMetrics } from '@/api/telemetry';
import { EmptyState, ErrorState } from '@/components/ui';
import { ChartSkeleton, Skeleton } from '@/components/Skeleton';
import { BarChart2, Globe } from 'lucide-react';

const TIME_RANGES = [{ label: '1h', value: '1h' }, { label: '24h', value: '24h' }, { label: '7d', value: '7d' }, { label: '30d', value: '30d' }];

export default function GlobalTelemetryPage() {
  const navigate = useNavigate();
  const [devices, setDevices] = useState<Device[]>([]);
  const [metrics, setMetrics] = useState<string[]>([]);
  const [activeDevice, setActiveDevice] = useState<number | null>(null);
  const [activeMetric, setActiveMetric] = useState('');
  const [activeRange, setActiveRange] = useState('24h');
  const [chartData, setChartData] = useState<{ ts: string; value: unknown }[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingChart, setLoadingChart] = useState(false);
  const [error, setError] = useState(false);
  const isDark = document.documentElement.classList.contains('dark');

  useEffect(() => {
    getDevices({ per_page: 200 }).then((res) => {
      const devs = res.data.data.items;
      setDevices(devs);
      if (devs.length > 0) setActiveDevice(devs[0].id);
    }).catch(() => setError(true)).finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (!activeDevice) return;
    getTelemetryMetrics(activeDevice).then((res) => {
      const keys = res.data.data;
      setMetrics(keys);
      if (keys.length > 0) setActiveMetric(keys[0]);
    }).catch(() => setMetrics([]));
  }, [activeDevice]);

  useEffect(() => {
    if (!activeDevice || !activeMetric) return;
    setLoadingChart(true);
    getTelemetryHistory(activeDevice, activeMetric, { range: activeRange })
      .then((res) => setChartData(res.data.data))
      .catch(() => setChartData([]))
      .finally(() => setLoadingChart(false));
  }, [activeDevice, activeMetric, activeRange]);

  const textColor = isDark ? '#94a3b8' : '#64748b';
  const gridColor = isDark ? '#1e293b' : '#f1f5f9';

  const chartOption = {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'axis',
      backgroundColor: isDark ? '#1e293b' : '#fff',
      borderColor: isDark ? '#334155' : '#e2e8f0',
      textStyle: { color: textColor },
    },
    grid: { left: 12, right: 12, top: 12, bottom: 40, containLabel: true },
    xAxis: { type: 'time', axisLabel: { color: textColor, fontSize: 10 }, splitLine: { lineStyle: { color: gridColor } } },
    yAxis: { type: 'value', axisLabel: { color: textColor, fontSize: 10 }, splitLine: { lineStyle: { color: gridColor } } },
    series: [{
      type: 'line',
      data: chartData.map((p) => [new Date(p.ts).getTime(), Number(p.value)]),
      smooth: true, symbol: 'none',
      lineStyle: { color: '#6366f1', width: 2.5 },
      areaStyle: {
        color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [{ offset: 0, color: 'rgba(99,102,241,0.25)' }, { offset: 1, color: 'rgba(99,102,241,0)' }] },
      },
    }],
  };

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100 ml-1">全屋趋势分析</h1>
      </div>

      <div className="px-4 pb-6 space-y-4">
        {/* Hero info card */}
        <div className="hero-card bg-gradient-to-br from-violet-500 to-indigo-600 p-5 text-white">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-10 h-10 bg-white/20 rounded-2xl flex items-center justify-center">
              <Globe className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-lg font-bold">全屋趋势分析</h2>
              <p className="text-white/70 text-xs">跨设备历史数据对比</p>
            </div>
          </div>
          <div className="flex gap-3">
            {[
              { label: '设备数', value: devices.length },
              { label: '当前设备', value: devices.find(d => d.id === activeDevice)?.name || '--' },
              { label: '时区', value: 'Asia/Shanghai' },
            ].map(({ label, value }) => (
              <div key={label} className="bg-white/15 rounded-xl px-3 py-2">
                <p className="text-white/60 text-[10px]">{label}</p>
                <p className="text-white text-xs font-semibold truncate max-w-[80px]">{String(value)}</p>
              </div>
            ))}
          </div>
        </div>

        {loading ? <ChartSkeleton /> : error ? <ErrorState message="加载设备失败" /> : (
          <>
            {/* Device selector */}
            <div className="card p-4">
              <p className="text-xs text-slate-500 dark:text-slate-400 font-medium mb-2">选择设备</p>
              <div className="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4">
                {devices.map((d) => (
                  <button key={d.id} onClick={() => setActiveDevice(d.id)}
                    className={`flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors
                      ${activeDevice === d.id ? 'bg-indigo-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400'}`}>
                    {d.name}
                  </button>
                ))}
              </div>
            </div>

            {/* Metric selector */}
            {metrics.length > 0 && (
              <div className="card p-4">
                <p className="text-xs text-slate-500 dark:text-slate-400 font-medium mb-2">选择指标</p>
                <div className="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4">
                  {metrics.map((m) => (
                    <button key={m} onClick={() => setActiveMetric(m)}
                      className={`flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors
                        ${activeMetric === m ? 'bg-indigo-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400'}`}>
                      {m}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {/* Time range */}
            <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
              {TIME_RANGES.map(({ label, value }) => (
                <button key={value} onClick={() => setActiveRange(value)}
                  className={`flex-1 py-2 rounded-xl text-sm font-medium transition-all
                    ${activeRange === value ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}>
                  {label}
                </button>
              ))}
            </div>

            {/* Chart */}
            <div className="card p-4">
              {loadingChart ? (
                <div className="h-56 flex items-center justify-center">
                  <div className="w-6 h-6 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin" />
                </div>
              ) : chartData.length === 0 ? (
                <div className="h-56 flex items-center justify-center">
                  <EmptyState icon={BarChart2} title="暂无数据" description="该时间段内没有数据" />
                </div>
              ) : (
                <ReactECharts option={chartOption} style={{ height: 260 }} notMerge />
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
