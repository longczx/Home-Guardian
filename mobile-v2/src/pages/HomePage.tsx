import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Settings, ChevronRight, Thermometer, Droplets, Wind, TrendingUp, Cpu, Bell, AlertTriangle, Moon, Sun, Zap, BarChart2, Clock, MapPin } from 'lucide-react';
import { getDevices, type Device } from '@/api/device';
import { getAlertLogs, type AlertLog } from '@/api/alert';
import { useAuthStore } from '@/stores/authStore';
import { useWsStore } from '@/stores/wsStore';
import { WsIndicator, StatusDot, EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { formatDistanceToNow } from '@/utils/time';

const QUICK_ENTRIES = [
  { label: '全屋图表', icon: BarChart2, to: '/telemetry', color: 'text-violet-500', bg: 'bg-violet-50 dark:bg-violet-900/20' },
  { label: '告警规则', icon: Bell, to: '/alert-rules', color: 'text-red-500', bg: 'bg-red-50 dark:bg-red-900/20' },
  { label: '自动化', icon: Zap, to: '/automations', color: 'text-amber-500', bg: 'bg-amber-50 dark:bg-amber-900/20' },
  { label: '命令历史', icon: Clock, to: '/commands', color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/20' },
  { label: '通知渠道', icon: Bell, to: '/notification-channels', color: 'text-emerald-500', bg: 'bg-emerald-50 dark:bg-emerald-900/20' },
  { label: '设备列表', icon: Cpu, to: '/devices', color: 'text-indigo-500', bg: 'bg-indigo-50 dark:bg-indigo-900/20' },
];

export default function HomePage() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [alerts, setAlerts] = useState<AlertLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const deviceStatuses = useWsStore((s) => s.deviceStatuses);
  const latestTelemetry = useWsStore((s) => s.latestTelemetry);

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      const [devRes, alertRes] = await Promise.all([
        getDevices({ per_page: 50 }),
        getAlertLogs({ status: 'triggered', per_page: 5 }),
      ]);
      setDevices(devRes.data.data.items);
      setAlerts(alertRes.data.data.items);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const onlineCount = devices.filter((d) => deviceStatuses[d.id] ?? d.is_online).length;
  const totalCount = devices.length;

  // Get representative env metrics
  const getMetric = (key: string) => {
    for (const d of devices) {
      const v = latestTelemetry[`${d.id}:${key}`];
      if (v !== undefined) return v.value;
    }
    return null;
  };

  const temp = getMetric('temperature');
  const humidity = getMetric('humidity');
  const aqi = getMetric('aqi') || getMetric('pm25');

  // Room grouping
  const rooms = Array.from(new Set(devices.map((d) => d.location).filter(Boolean)));

  if (error) return (
    <div className="page-container bg-slate-50 dark:bg-slate-950">
      <ErrorState onRetry={load} />
    </div>
  );

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="px-4 pb-6 space-y-4">
        {/* Header */}
        <div className="flex items-start justify-between pt-4">
          <div>
            <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100">
              {user?.username ? `${user.username} 的家` : '我的家'}
            </h1>
            <div className="flex items-center gap-2 mt-1">
              <WsIndicator />
              <span className="text-slate-400 text-xs">·</span>
              <span className="text-slate-400 dark:text-slate-500 text-xs">
                {onlineCount}/{totalCount} 设备在线
              </span>
            </div>
          </div>
          <button
            onClick={() => navigate('/profile')}
            className="w-10 h-10 bg-white dark:bg-slate-800 rounded-2xl flex items-center justify-center shadow-sm"
          >
            <Settings className="w-5 h-5 text-slate-500" />
          </button>
        </div>

        {loading ? (
          <ListSkeleton count={5} />
        ) : (
          <>
            {/* Environment Hero Card */}
            <div className="hero-card bg-gradient-to-br from-indigo-500 to-violet-600 p-5 text-white">
              <div className="flex items-stretch gap-4">
                <div className="flex-1">
                  <p className="text-white/70 text-xs font-medium mb-1">当前环境</p>
                  <div className="flex items-end gap-1 mb-3">
                    <span className="text-5xl font-black">
                      {temp !== null ? `${Number(temp).toFixed(1)}` : '--'}
                    </span>
                    <span className="text-xl font-semibold mb-1 text-white/80">°C</span>
                  </div>
                  <p className="text-white/70 text-sm">
                    {temp !== null
                      ? Number(temp) < 18 ? '🧊 有点凉' : Number(temp) < 26 ? '😊 舒适' : '🌡️ 偏热'
                      : '暂无传感器数据'}
                  </p>
                </div>
                <div className="flex flex-col gap-2">
                  {[
                    { icon: Droplets, label: '湿度', value: humidity !== null ? `${humidity}%` : '--' },
                    { icon: Wind, label: '空气', value: aqi !== null ? `${aqi}` : '--' },
                    { icon: TrendingUp, label: '在线', value: `${onlineCount}台` },
                  ].map(({ icon: Icon, label, value }) => (
                    <div key={label} className="bg-white/15 rounded-2xl px-3 py-2 flex items-center gap-2 min-w-[90px]">
                      <Icon className="w-4 h-4 text-white/70 flex-shrink-0" />
                      <div>
                        <p className="text-white font-semibold text-sm leading-none">{value}</p>
                        <p className="text-white/60 text-[10px] mt-0.5">{label}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>

            {/* Stats Row */}
            <div className="grid grid-cols-3 gap-3">
              {[
                { label: '设备总数', value: totalCount, icon: Cpu, color: 'text-indigo-500', bg: 'bg-indigo-50 dark:bg-indigo-900/20' },
                { label: '当前在线', value: onlineCount, icon: TrendingUp, color: 'text-emerald-500', bg: 'bg-emerald-50 dark:bg-emerald-900/20' },
                { label: '触发告警', value: alerts.length, icon: AlertTriangle, color: alerts.length > 0 ? 'text-red-500' : 'text-slate-400', bg: alerts.length > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-slate-100 dark:bg-slate-800' },
              ].map(({ label, value, icon: Icon, color, bg }) => (
                <div key={label} className="card p-3.5">
                  <div className={`w-8 h-8 ${bg} rounded-xl flex items-center justify-center mb-2`}>
                    <Icon className={`w-4 h-4 ${color}`} />
                  </div>
                  <p className="text-xl font-black text-slate-900 dark:text-slate-100">{value}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{label}</p>
                </div>
              ))}
            </div>

            {/* Quick entries */}
            <div>
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-base font-bold text-slate-800 dark:text-slate-200">快捷入口</h2>
                <button onClick={() => navigate('/telemetry')}
                  className="text-indigo-500 text-sm font-medium flex items-center gap-1">
                  数据图表 <ChevronRight className="w-4 h-4" />
                </button>
              </div>
              <div className="grid grid-cols-3 gap-3">
                {QUICK_ENTRIES.map(({ label, icon: Icon, to, color, bg }) => (
                  <button
                    key={label}
                    onClick={() => navigate(to)}
                    className="card p-4 flex flex-col items-center gap-2 active:scale-95 transition-transform"
                  >
                    <div className={`w-10 h-10 ${bg} rounded-2xl flex items-center justify-center`}>
                      <Icon className={`w-5 h-5 ${color}`} />
                    </div>
                    <span className="text-xs font-medium text-slate-600 dark:text-slate-400">{label}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Rooms */}
            {rooms.length > 0 && (
              <div>
                <div className="flex items-center justify-between mb-3">
                  <h2 className="text-base font-bold text-slate-800 dark:text-slate-200">房间</h2>
                  <button onClick={() => navigate('/devices')}
                    className="text-indigo-500 text-sm font-medium flex items-center gap-1">
                    全部房间 <ChevronRight className="w-4 h-4" />
                  </button>
                </div>
                <div className="flex gap-3 overflow-x-auto pb-1 -mx-4 px-4">
                  {rooms.slice(0, 6).map((room) => {
                    const roomDevices = devices.filter((d) => d.location === room);
                    const roomOnline = roomDevices.filter((d) => deviceStatuses[d.id] ?? d.is_online).length;
                    return (
                      <button
                        key={room}
                        onClick={() => navigate(`/devices?location=${room}`)}
                        className="card px-4 py-3 flex-shrink-0 text-left active:scale-95 transition-transform min-w-[120px]"
                      >
                        <MapPin className="w-4 h-4 text-indigo-500 mb-2" />
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{room}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                          {roomOnline}/{roomDevices.length} 在线
                        </p>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Recent alerts */}
            <div>
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-base font-bold text-slate-800 dark:text-slate-200">最近告警</h2>
                <button onClick={() => navigate('/alerts')}
                  className="text-indigo-500 text-sm font-medium flex items-center gap-1">
                  查看全部 <ChevronRight className="w-4 h-4" />
                </button>
              </div>
              {alerts.length === 0 ? (
                <div className="card p-6 text-center">
                  <div className="text-3xl mb-2">🎉</div>
                  <p className="text-sm text-slate-500 dark:text-slate-400">暂无触发中的告警</p>
                </div>
              ) : (
                <div className="space-y-2">
                  {alerts.map((alert) => (
                    <button
                      key={alert.id}
                      onClick={() => navigate(`/alerts/${alert.id}`)}
                      className="card p-4 w-full flex items-center gap-3 text-left active:scale-[0.99] transition-transform"
                    >
                      <div className="w-10 h-10 bg-red-50 dark:bg-red-900/20 rounded-2xl flex items-center justify-center flex-shrink-0">
                        <AlertTriangle className="w-5 h-5 text-red-500" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{alert.rule_name}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400 truncate">
                          {alert.device_name} · {alert.device_location || '未知位置'}
                        </p>
                      </div>
                      <div className="flex flex-col items-end flex-shrink-0">
                        <span className="tag-alert-triggered text-[10px]">触发中</span>
                        <span className="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
                          {formatDistanceToNow(alert.triggered_at)}
                        </span>
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
