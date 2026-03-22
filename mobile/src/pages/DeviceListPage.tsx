import { useState, useEffect, useMemo } from 'react';
import { SearchBar, Tabs, PullToRefresh } from 'antd-mobile';
import { useSearchParams, useNavigate } from 'react-router-dom';
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
            <>
              {/* 网关分组 */}
              {grouped.gateways.map(({ gateway, sensors }) => (
                <div key={gateway.device_uid} style={{ marginBottom: 16 }}>
                  {/* 网关卡片 */}
                  <div
                    onClick={() => navigate(`/mobile/device/${gateway.id}`)}
                    style={{
                      background: 'var(--color-bg-card)',
                      borderRadius: 'var(--card-radius)',
                      boxShadow: 'var(--card-shadow)',
                      padding: '12px 16px',
                      marginBottom: 2,
                      cursor: 'pointer',
                      display: 'flex', alignItems: 'center', gap: 10,
                    }}
                  >
                    <span style={{ fontSize: 18 }}>🖧</span>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                        <span style={{
                          width: 8, height: 8, borderRadius: '50%', flexShrink: 0,
                          background: gateway.is_online ? 'var(--color-success)' : 'var(--color-text-tertiary)',
                        }} />
                        <span style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)' }}>
                          {gateway.name}
                        </span>
                        <span style={{ fontSize: 11, color: 'var(--color-text-tertiary)', background: 'var(--color-fill)', padding: '1px 6px', borderRadius: 4 }}>
                          网关
                        </span>
                      </div>
                      <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 2 }}>
                        {gateway.location || '未分配位置'} · {sensors.length} 个传感器
                      </div>
                    </div>
                  </div>
                  {/* 传感器列表（缩进） */}
                  <div style={{ paddingLeft: 20 }}>
                    {sensors.map((d) => (
                      <DeviceCard key={d.id} device={d} metrics={metricsMap[d.id]} metricLookup={metricLookup} />
                    ))}
                  </div>
                </div>
              ))}

              {/* 独立设备 */}
              {grouped.standalone.length > 0 && (
                <div>
                  {grouped.gateways.length > 0 && (
                    <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text-secondary)', margin: '12px 0 8px' }}>
                      独立设备
                    </div>
                  )}
                  {grouped.standalone.map((d) => (
                    <DeviceCard key={d.id} device={d} metrics={metricsMap[d.id]} metricLookup={metricLookup} />
                  ))}
                </div>
              )}
            </>
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
