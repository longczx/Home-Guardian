import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { BarChart2, Search, X, MapPin, Cpu, ChevronRight } from 'lucide-react';
import { getDevices, type Device } from '@/api/device';
import { useWsStore } from '@/stores/wsStore';
import { OnlineTag, EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { formatDistanceToNow } from '@/utils/time';

const DEVICE_TYPE_ICONS: Record<string, string> = {
  sensor: '🌡️', switch: '💡', relay: '⚡', gateway: '📡', camera: '📷',
  lock: '🔒', fan: '💨', ac: '❄️', default: '📟',
};

function DeviceIcon({ type }: { type: string }) {
  const emoji = DEVICE_TYPE_ICONS[type.toLowerCase()] || DEVICE_TYPE_ICONS.default;
  return <span className="text-2xl">{emoji}</span>;
}

export default function DeviceListPage() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [search, setSearch] = useState('');
  const [activeRoom, setActiveRoom] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'all' | 'online' | 'offline'>('all');
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const deviceStatuses = useWsStore((s) => s.deviceStatuses);
  const latestTelemetry = useWsStore((s) => s.latestTelemetry);

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      const res = await getDevices({ per_page: 200 });
      setDevices(res.data.data.items);
      const loc = params.get('location');
      if (loc) setActiveRoom(loc);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [params]);

  useEffect(() => { load(); }, [load]);

  const rooms = Array.from(new Set(devices.map((d) => d.location).filter(Boolean) as string[]));

  const filtered = devices.filter((d) => {
    const isOnline = deviceStatuses[d.id] ?? d.is_online;
    if (activeTab === 'online' && !isOnline) return false;
    if (activeTab === 'offline' && isOnline) return false;
    if (activeRoom && d.location !== activeRoom) return false;
    if (search) {
      const q = search.toLowerCase();
      return (
        d.name.toLowerCase().includes(q) ||
        d.device_uid.toLowerCase().includes(q) ||
        (d.location || '').toLowerCase().includes(q)
      );
    }
    return true;
  });

  // Group by gateway
  const gateways = filtered.filter((d) => d.type.toLowerCase() === 'gateway');
  const standalone = filtered.filter((d) => {
    if (d.type.toLowerCase() === 'gateway') return false;
    if (d.gateway_uid && filtered.some((g) => g.device_uid === d.gateway_uid)) return false;
    return true;
  });
  const childrenOf = (uid: string) => filtered.filter((d) => d.gateway_uid === uid);

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="px-4 pb-6 space-y-4">
        {/* Header */}
        <div className="flex items-start justify-between pt-4">
          <div>
            <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100">设备</h1>
            <p className="text-slate-500 dark:text-slate-400 text-sm mt-0.5">按房间和状态查看</p>
          </div>
          <button onClick={() => navigate('/telemetry')}
            className="w-10 h-10 bg-white dark:bg-slate-800 rounded-2xl flex items-center justify-center shadow-sm">
            <BarChart2 className="w-5 h-5 text-slate-500" />
          </button>
        </div>

        {/* Search */}
        <div className="relative">
          <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="搜索设备名、UID、位置..."
            className="input-field pl-10 pr-10"
          />
          {search && (
            <button onClick={() => setSearch('')}
              className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400">
              <X className="w-4 h-4" />
            </button>
          )}
        </div>

        {/* Room filter */}
        {rooms.length > 0 && (
          <div className="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4">
            <button
              onClick={() => setActiveRoom(null)}
              className={`flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors
                ${!activeRoom ? 'bg-indigo-500 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400'}`}
            >
              全部
            </button>
            {rooms.map((room) => (
              <button
                key={room}
                onClick={() => setActiveRoom(activeRoom === room ? null : room)}
                className={`flex-shrink-0 flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-medium transition-colors
                  ${activeRoom === room ? 'bg-indigo-500 text-white' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-400'}`}
              >
                <MapPin className="w-3.5 h-3.5" />
                {room}
              </button>
            ))}
          </div>
        )}

        {/* Status tabs */}
        <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
          {(['all', 'online', 'offline'] as const).map((tab) => {
            const labels = { all: '全部', online: '在线', offline: '离线' };
            return (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`flex-1 py-2 rounded-xl text-sm font-medium transition-all
                  ${activeTab === tab ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}
              >
                {labels[tab]}
              </button>
            );
          })}
        </div>

        {/* Content */}
        {loading ? (
          <ListSkeleton count={6} />
        ) : error ? (
          <ErrorState onRetry={load} />
        ) : filtered.length === 0 ? (
          <EmptyState icon={Cpu} title="暂无设备" description="没有符合条件的设备" />
        ) : (
          <div className="space-y-3">
            {/* Gateways with children */}
            {gateways.map((gw) => {
              const children = childrenOf(gw.device_uid);
              const isOnline = deviceStatuses[gw.id] ?? gw.is_online;
              return (
                <div key={gw.id} className="card overflow-hidden">
                  <DeviceCard device={gw} isOnline={isOnline} latestTelemetry={latestTelemetry}
                    onClick={() => navigate(`/devices/${gw.id}`)} isGateway />
                  {children.length > 0 && (
                    <div className="border-t border-slate-100 dark:border-slate-700">
                      {children.map((child, idx) => {
                        const childOnline = deviceStatuses[child.id] ?? child.is_online;
                        return (
                          <div key={child.id} className={idx > 0 ? 'border-t border-slate-100 dark:border-slate-700' : ''}>
                            <DeviceCard device={child} isOnline={childOnline} latestTelemetry={latestTelemetry}
                              onClick={() => navigate(`/devices/${child.id}`)} isChild />
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              );
            })}
            {/* Standalone devices */}
            {standalone.map((d) => {
              const isOnline = deviceStatuses[d.id] ?? d.is_online;
              return (
                <div key={d.id} className="card">
                  <DeviceCard device={d} isOnline={isOnline} latestTelemetry={latestTelemetry}
                    onClick={() => navigate(`/devices/${d.id}`)} />
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}

function DeviceCard({ device, isOnline, latestTelemetry, onClick, isGateway, isChild }: {
  device: Device;
  isOnline: boolean;
  latestTelemetry: Record<string, { value: unknown; ts: string }>;
  onClick: () => void;
  isGateway?: boolean;
  isChild?: boolean;
}) {
  const metrics = ['temperature', 'humidity', 'power', 'voltage', 'current']
    .map((key) => ({ key, data: latestTelemetry[`${device.id}:${key}`] }))
    .filter((m) => m.data);

  return (
    <button
      onClick={onClick}
      className={`w-full p-4 flex items-center gap-3 text-left active:bg-slate-50 dark:active:bg-slate-700/50 transition-colors
                 ${isChild ? 'pl-6' : ''}`}
    >
      {isChild && <div className="w-0.5 h-8 bg-slate-200 dark:bg-slate-600 rounded-full flex-shrink-0" />}
      <div className={`w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0
                      ${isOnline ? 'bg-indigo-50 dark:bg-indigo-900/20' : 'bg-slate-100 dark:bg-slate-700'}`}>
        <DeviceIcon type={device.type} />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{device.name}</p>
          {isGateway && (
            <span className="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-1.5 py-0.5 rounded-full flex-shrink-0">
              网关
            </span>
          )}
        </div>
        <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5 truncate">
          {device.location || '未设置位置'} · {device.type}
        </p>
        {metrics.length > 0 && (
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 truncate">
            {metrics.slice(0, 2).map((m) =>
              `${m.key}: ${String(m.data!.value)}`
            ).join(' · ')}
          </p>
        )}
      </div>
      <div className="flex flex-col items-end gap-1.5 flex-shrink-0">
        <OnlineTag online={isOnline} />
        <ChevronRight className="w-4 h-4 text-slate-300 dark:text-slate-600" />
      </div>
    </button>
  );
}
