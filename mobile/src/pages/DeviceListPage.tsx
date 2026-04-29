import { useState, useEffect, useMemo } from 'react';
import { SearchBar, Tabs, PullToRefresh } from 'antd-mobile';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { RightOutline } from 'antd-mobile-icons';
import { useDeviceStore } from '@/stores/deviceStore';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';
import { buildMetricLookup } from '@/utils/metricLookup';
import DeviceCard from '@/components/DeviceCard';
import EmptyState from '@/components/EmptyState';
import type { Device } from '@/api/device';

export default function DeviceListPage() {
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const { devices, metricsMap, fetchDevices } = useDeviceStore();
  const { definitions, fetchDefinitions } = useMetricDefinitionStore();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const locationFilter = searchParams.get('location');

  useEffect(() => { fetchDevices(); fetchDefinitions(); }, [fetchDevices, fetchDefinitions]);

  const metricLookup = useMemo(
    () => buildMetricLookup(null, definitions),
    [definitions],
  );

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

  // 按网关分组
  const grouped = useMemo(() => {
    const gatewayMap = new Map<string, { gateway: Device; sensors: Device[] }>();
    const standalone: Device[] = [];

    // 先收集网关
    filtered.filter((d) => d.type === 'gateway').forEach((gw) => {
      gatewayMap.set(gw.device_uid, { gateway: gw, sensors: [] });
    });

    // 分配传感器
    filtered.filter((d) => d.type !== 'gateway').forEach((d) => {
      if (d.gateway_uid && gatewayMap.has(d.gateway_uid)) {
        gatewayMap.get(d.gateway_uid)!.sensors.push(d);
      } else {
        standalone.push(d);
      }
    });

    return { gateways: Array.from(gatewayMap.values()), standalone };
  }, [filtered]);

  const roomGroups = useMemo(() => {
    const map = new Map<string, Device[]>();
    filtered.forEach((device) => {
      const room = device.location || '未分配';
      if (!map.has(room)) {
        map.set(room, []);
      }
      map.get(room)?.push(device);
    });
    return Array.from(map.entries());
  }, [filtered]);

  return (
    <div className="mobile-page mobile-page--tight">
      <div className="screen-header">
        <div>
          <div className="screen-header__title">设备</div>
          <div className="screen-header__subtitle">先看房间，再看设备明细和在线状态。</div>
        </div>
        <button className="ghost-icon-button" onClick={() => navigate('/mobile/telemetry')}>图表</button>
      </div>

      <div className="surface-card top-panel top-panel--flat">
        <SearchBar
          placeholder="搜索设备名称、UID、房间"
          value={search}
          onChange={setSearch}
          style={{ '--background': 'transparent' }}
        />
      </div>

      {roomGroups.length > 0 && (
        <div className="room-gallery">
          {roomGroups.slice(0, 5).map(([room, roomDevices]) => (
            <button key={room} className="room-gallery__item" onClick={() => navigate(`/mobile/devices?location=${encodeURIComponent(room)}`)}>
              <div className="room-gallery__overlay" />
              <strong>{room}</strong>
              <span>{roomDevices.length} 个设备</span>
            </button>
          ))}
        </div>
      )}

      <Tabs
        activeKey={filter}
        onChange={setFilter}
        style={{ '--title-font-size': '14px', position: 'sticky', top: 76, zIndex: 9, marginTop: 10 }}
      >
        <Tabs.Tab title="全部" key="all" />
        <Tabs.Tab title="在线" key="online" />
        <Tabs.Tab title="离线" key="offline" />
      </Tabs>

      <PullToRefresh onRefresh={fetchDevices}>
        <div style={{ paddingTop: 14 }}>
          {filtered.length === 0 ? (
            <EmptyState title="暂无设备" />
          ) : (
            <div className="list-stack">
              {locationFilter && (
                <div className="surface-card room-filter-banner">
                  <div>
                    <strong>{locationFilter}</strong>
                    <span>当前房间筛选中，共 {filtered.length} 台设备</span>
                  </div>
                  <button onClick={() => navigate('/mobile/devices')}>查看全部</button>
                </div>
              )}
              {grouped.gateways.map(({ gateway, sensors }) => (
                <div key={gateway.device_uid} className="surface-card gateway-stack-card" style={{ padding: 14 }}>
                  <div
                    onClick={() => navigate(`/mobile/device/${gateway.id}`)}
                    className="gateway-stack-card__hero"
                  >
                    <span style={{ fontSize: 20 }}>🖧</span>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span style={{
                          width: 8, height: 8, borderRadius: '50%', flexShrink: 0,
                          background: gateway.is_online ? 'var(--color-success)' : 'var(--color-text-tertiary)',
                        }} />
                        <span style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)' }}>
                          {gateway.name}
                        </span>
                        <span style={{ fontSize: 11, color: 'var(--color-text-secondary)', background: 'rgba(255,255,255,0.78)', padding: '3px 8px', borderRadius: 999 }}>
                          网关
                        </span>
                      </div>
                      <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 2 }}>
                        {gateway.location || '未分配位置'} · {sensors.length} 个传感器
                      </div>
                    </div>
                    <RightOutline fontSize={14} style={{ color: 'var(--color-text-tertiary)' }} />
                  </div>
                  <div style={{ paddingLeft: 18, paddingTop: 12 }}>
                    {sensors.map((d) => (
                      <DeviceCard key={d.id} device={d} metrics={metricsMap[d.id]} metricLookup={metricLookup} />
                    ))}
                  </div>
                </div>
              ))}

              {grouped.standalone.length > 0 && (
                <div>
                  {grouped.gateways.length > 0 && (
                    <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--color-text-secondary)', margin: '8px 4px 10px' }}>
                      独立设备
                    </div>
                  )}
                  {grouped.standalone.map((d) => (
                    <DeviceCard key={d.id} device={d} metrics={metricsMap[d.id]} metricLookup={metricLookup} />
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </PullToRefresh>

    </div>
  );
}
