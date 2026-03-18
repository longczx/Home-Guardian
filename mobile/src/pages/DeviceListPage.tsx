import { useState, useEffect, useMemo } from 'react';
import { SearchBar, Tabs, PullToRefresh } from 'antd-mobile';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { useDeviceStore } from '@/stores/deviceStore';
import DeviceCard from '@/components/DeviceCard';
import EmptyState from '@/components/EmptyState';

export default function DeviceListPage() {
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const { devices, metricsMap, fetchDevices } = useDeviceStore();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const locationFilter = searchParams.get('location');

  useEffect(() => { fetchDevices(); }, [fetchDevices]);

  const filtered = useMemo(() => {
    let list = devices;
    if (locationFilter) list = list.filter((d) => d.location === locationFilter);
    if (filter === 'online') list = list.filter((d) => d.is_online);
    if (filter === 'offline') list = list.filter((d) => !d.is_online);
    if (search) {
      const q = search.toLowerCase();
      list = list.filter((d) =>
        d.name.toLowerCase().includes(q) ||
        d.device_uid.toLowerCase().includes(q) ||
        (d.location || '').toLowerCase().includes(q)
      );
    }
    return list;
  }, [devices, filter, search, locationFilter]);

  const grouped = useMemo(() => {
    const map = new Map<string, typeof filtered>();
    filtered.forEach((d) => {
      const loc = d.location || '未分配';
      if (!map.has(loc)) map.set(loc, []);
      map.get(loc)!.push(d);
    });
    return map;
  }, [filtered]);

  return (
    <div style={{ background: 'var(--color-bg)', minHeight: '100%' }}>
      <div style={{ padding: '12px 16px 8px', background: 'var(--navbar-bg)' }}>
        <div style={{ fontSize: 20, fontWeight: 700, color: 'var(--color-text)', marginBottom: 10 }}>
          {locationFilter ? locationFilter : '设备'}
        </div>
        <SearchBar
          placeholder="搜索设备名称、UID"
          value={search}
          onChange={setSearch}
          style={{ '--background': 'var(--color-fill)' }}
        />
      </div>

      <Tabs
        activeKey={filter}
        onChange={setFilter}
        style={{ '--title-font-size': '14px', background: 'var(--navbar-bg)', position: 'sticky', top: 0, zIndex: 10 }}
      >
        <Tabs.Tab title="全部" key="all" />
        <Tabs.Tab title="在线" key="online" />
        <Tabs.Tab title="离线" key="offline" />
      </Tabs>

      <PullToRefresh onRefresh={fetchDevices}>
        <div style={{ padding: '12px 16px' }}>
          {filtered.length === 0 ? (
            <EmptyState title="暂无设备" />
          ) : (
            Array.from(grouped.entries()).map(([loc, devs]) => (
              <div key={loc}>
                <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text-secondary)', margin: '12px 0 8px', display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span>{loc}</span>
                  <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>({devs.length})</span>
                </div>
                {devs.map((d) => (
                  <DeviceCard key={d.id} device={d} metrics={metricsMap[d.id]} />
                ))}
              </div>
            ))
          )}
        </div>
      </PullToRefresh>

      {locationFilter && (
        <div style={{ textAlign: 'center', padding: 16 }}>
          <span
            style={{ color: 'var(--color-primary)', fontSize: 14, cursor: 'pointer' }}
            onClick={() => navigate('/mobile/devices')}
          >
            查看全部设备
          </span>
        </div>
      )}
    </div>
  );
}
