import React, { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, BarChart2, MoreVertical, Plus, Trash2, Send, RefreshCw, Edit2 } from 'lucide-react';
import { getDevice, getDeviceAttributes, setDeviceAttributes, sendCommand, type Device, type DeviceAttribute } from '@/api/device';
import { getTelemetryLatest, type TelemetryLatest } from '@/api/telemetry';
import { useWsStore } from '@/stores/wsStore';
import { OnlineTag, Switch, EmptyState, ErrorState, LoadingSpinner } from '@/components/ui';
import { CardSkeleton, Skeleton } from '@/components/Skeleton';
import { useToast } from '@/components/Toast';
import { formatDistanceToNow } from '@/utils/time';

const METRIC_META: Record<string, { label: string; unit: string; icon: string }> = {
  temperature: { label: '温度', unit: '°C', icon: '🌡️' },
  humidity: { label: '湿度', unit: '%', icon: '💧' },
  pressure: { label: '气压', unit: 'hPa', icon: '🔵' },
  pm25: { label: 'PM2.5', unit: 'μg/m³', icon: '💨' },
  co2: { label: 'CO₂', unit: 'ppm', icon: '🌿' },
  voc: { label: 'VOC', unit: 'ppb', icon: '🧪' },
  power: { label: '功率', unit: 'W', icon: '⚡' },
  voltage: { label: '电压', unit: 'V', icon: '🔌' },
  current: { label: '电流', unit: 'A', icon: '⚡' },
  brightness: { label: '亮度', unit: 'lux', icon: '☀️' },
  aqi: { label: '空气质量', unit: '', icon: '🍃' },
};

export default function DeviceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const [device, setDevice] = useState<Device | null>(null);
  const [attrs, setAttrs] = useState<DeviceAttribute[]>([]);
  const [metrics, setMetrics] = useState<TelemetryLatest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [sendingCommand, setSendingCommand] = useState(false);
  const [showMenu, setShowMenu] = useState(false);
  const [newAttrKey, setNewAttrKey] = useState('');
  const [newAttrVal, setNewAttrVal] = useState('');
  const [addingAttr, setAddingAttr] = useState(false);
  const deviceStatuses = useWsStore((s) => s.deviceStatuses);
  const latestTelemetry = useWsStore((s) => s.latestTelemetry);

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    setError(false);
    try {
      const [devRes, attrRes, metricRes] = await Promise.all([
        getDevice(Number(id)),
        getDeviceAttributes(Number(id)),
        getTelemetryLatest(Number(id)),
      ]);
      setDevice(devRes.data.data);
      setAttrs(attrRes.data.data);
      setMetrics(metricRes.data.data);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const isOnline = device ? (deviceStatuses[device.id] ?? device.is_online) : false;

  // Merge WS telemetry with API data
  const mergedMetrics = metrics.map((m) => {
    const ws = latestTelemetry[`${device?.id}:${m.metric_key}`];
    return ws ? { ...m, value: ws.value, ts: ws.ts } : m;
  });

  const handleSendCommand = async (payload: Record<string, unknown>) => {
    if (!device) return;
    setSendingCommand(true);
    try {
      await sendCommand(device.id, payload);
      toast.success('指令已发送');
    } catch {
      toast.error('指令发送失败');
    } finally {
      setSendingCommand(false);
    }
  };

  const handleAddAttr = async () => {
    if (!device || !newAttrKey.trim()) return;
    setAddingAttr(true);
    try {
      const updatedAttrs = [...attrs.filter(a => a.key !== newAttrKey), { key: newAttrKey, value: newAttrVal }];
      await setDeviceAttributes(device.id, Object.fromEntries(updatedAttrs.map(a => [a.key, a.value])));
      setAttrs(updatedAttrs);
      setNewAttrKey('');
      setNewAttrVal('');
      toast.success('属性已保存');
    } catch {
      toast.error('保存失败');
    } finally {
      setAddingAttr(false);
    }
  };

  if (error) return (
    <div className="page-container bg-slate-50 dark:bg-slate-950">
      <div className="flex items-center gap-2 p-4 pt-6">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
      </div>
      <ErrorState onRetry={load} />
    </div>
  );

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      {/* Header */}
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100 flex-1 text-center">
          {loading ? '设备详情' : device?.name || '设备详情'}
        </h1>
        <div className="flex items-center gap-2">
          <button onClick={() => navigate(`/devices/${id}/telemetry`)}
            className="p-2 text-slate-500 dark:text-slate-400">
            <BarChart2 className="w-5 h-5" />
          </button>
          <button onClick={() => setShowMenu(!showMenu)} className="p-2 text-slate-500 dark:text-slate-400">
            <MoreVertical className="w-5 h-5" />
          </button>
        </div>
      </div>

      {loading ? (
        <div className="px-4 space-y-4 pb-6">
          <CardSkeleton />
          <CardSkeleton />
          <CardSkeleton />
        </div>
      ) : device ? (
        <div className="px-4 pb-6 space-y-4">
          {/* Hero card */}
          <div className={`rounded-3xl p-5 text-white ${isOnline
            ? 'bg-gradient-to-br from-indigo-500 to-violet-600'
            : 'bg-gradient-to-br from-slate-500 to-slate-600'}`}>
            <div className="flex items-start justify-between">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <span className={`inline-block w-2 h-2 rounded-full ${isOnline ? 'bg-emerald-300' : 'bg-slate-300'}`} />
                  <span className="text-white/80 text-xs font-medium">{isOnline ? '在线' : '离线'}</span>
                </div>
                <p className="text-white/70 text-sm mb-1">{device.location || '未设置位置'}</p>
                <h2 className="text-2xl font-black text-white mb-1">{device.name}</h2>
                <p className="text-white/60 text-xs">固件 {device.firmware_version || '未知'}</p>
              </div>
              <div className="flex flex-col gap-2 min-w-[100px]">
                <div className="bg-white/15 rounded-2xl p-2.5">
                  <p className="text-white/60 text-[10px]">设备 UID</p>
                  <p className="text-white text-xs font-mono font-semibold truncate">{device.device_uid}</p>
                </div>
                <div className="bg-white/15 rounded-2xl p-2.5">
                  <p className="text-white/60 text-[10px]">最后上报</p>
                  <p className="text-white text-xs font-semibold">
                    {device.last_seen ? formatDistanceToNow(device.last_seen) : '--'}
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Real-time metrics */}
          {mergedMetrics.length > 0 && (
            <div>
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-base font-bold text-slate-800 dark:text-slate-200">实时数据</h2>
                <button onClick={() => navigate(`/devices/${id}/telemetry`)}
                  className="text-indigo-500 text-sm font-medium flex items-center gap-1">
                  查看图表 →
                </button>
              </div>
              <div className="grid grid-cols-2 gap-3">
                {mergedMetrics.map((m) => {
                  const meta = METRIC_META[m.metric_key] || { label: m.metric_key, unit: '', icon: '📊' };
                  const val = typeof m.value === 'object' ? JSON.stringify(m.value) : String(m.value);
                  return (
                    <div key={m.metric_key} className="card p-4">
                      <div className="flex items-center gap-2 mb-2">
                        <span className="text-lg">{meta.icon}</span>
                        <span className="text-xs text-slate-500 dark:text-slate-400 font-medium">{meta.label}</span>
                      </div>
                      <div className="flex items-end gap-1">
                        <span className="text-2xl font-black text-slate-900 dark:text-slate-100">
                          {Number.isFinite(Number(val)) ? Number(val).toFixed(1) : val}
                        </span>
                        {meta.unit && <span className="text-sm text-slate-500 mb-0.5">{meta.unit}</span>}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Device info */}
          <div className="card p-4">
            <h2 className="text-base font-bold text-slate-800 dark:text-slate-200 mb-3">设备信息</h2>
            <div className="grid grid-cols-2 gap-3">
              {[
                { label: '类型', value: device.type },
                { label: '位置', value: device.location || '未设置' },
                { label: '固件版本', value: device.firmware_version || '--' },
                { label: '网关', value: device.gateway_uid || '独立设备' },
              ].map(({ label, value }) => (
                <div key={label}>
                  <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
                  <p className="text-sm font-medium text-slate-900 dark:text-slate-100 mt-0.5 truncate">{value}</p>
                </div>
              ))}
            </div>
          </div>

          {/* Attributes */}
          <div className="card p-4">
            <h2 className="text-base font-bold text-slate-800 dark:text-slate-200 mb-3">设备属性</h2>
            {attrs.length === 0 ? (
              <p className="text-sm text-slate-400 dark:text-slate-500 text-center py-3">暂无属性</p>
            ) : (
              <div className="space-y-2 mb-3">
                {attrs.map((attr) => (
                  <div key={attr.key} className="flex items-center justify-between py-2 border-b border-slate-100 dark:border-slate-700 last:border-0">
                    <span className="text-sm text-slate-600 dark:text-slate-400 font-mono">{attr.key}</span>
                    <span className="text-sm font-medium text-slate-900 dark:text-slate-100">{String(attr.value)}</span>
                  </div>
                ))}
              </div>
            )}
            <div className="flex gap-2">
              <input value={newAttrKey} onChange={(e) => setNewAttrKey(e.target.value)}
                placeholder="键名" className="input-field flex-1 text-sm py-2.5" />
              <input value={newAttrVal} onChange={(e) => setNewAttrVal(e.target.value)}
                placeholder="值" className="input-field flex-1 text-sm py-2.5" />
              <button onClick={handleAddAttr} disabled={addingAttr || !newAttrKey}
                className="btn-primary px-4 py-2.5 text-sm flex items-center gap-1.5">
                {addingAttr ? <LoadingSpinner size="sm" /> : <Plus className="w-4 h-4" />}
              </button>
            </div>
          </div>

          {/* Control panel */}
          <div className="card p-4">
            <h2 className="text-base font-bold text-slate-800 dark:text-slate-200 mb-4">设备控制</h2>
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 bg-amber-50 dark:bg-amber-900/20 rounded-2xl flex items-center justify-center">
                    <span className="text-xl">💡</span>
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">电源开关</p>
                    <p className="text-xs text-slate-400">控制设备开/关</p>
                  </div>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => handleSendCommand({ action: 'turn_on' })}
                    disabled={sendingCommand || !isOnline}
                    className="bg-emerald-500 text-white text-sm font-medium px-4 py-2 rounded-xl disabled:opacity-50">
                    开
                  </button>
                  <button onClick={() => handleSendCommand({ action: 'turn_off' })}
                    disabled={sendingCommand || !isOnline}
                    className="bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium px-4 py-2 rounded-xl disabled:opacity-50">
                    关
                  </button>
                </div>
              </div>
              <button onClick={() => handleSendCommand({ action: 'get_status' })}
                disabled={sendingCommand || !isOnline}
                className="w-full flex items-center justify-center gap-2 bg-slate-100 dark:bg-slate-700 
                           text-slate-700 dark:text-slate-300 font-medium rounded-2xl py-3 disabled:opacity-50">
                {sendingCommand ? <LoadingSpinner size="sm" /> : <RefreshCw className="w-4 h-4" />}
                刷新状态
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
