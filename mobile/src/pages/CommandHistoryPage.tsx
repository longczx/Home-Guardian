import { useState, useEffect, useCallback } from 'react';
import { NavBar, InfiniteScroll, Tag } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { getCommandLogs, type CommandLog } from '@/api/command';
import StatusTag from '@/components/StatusTag';
import RelativeTime from '@/components/RelativeTime';
import EmptyState from '@/components/EmptyState';

export default function CommandHistoryPage() {
  const navigate = useNavigate();
  const [commands, setCommands] = useState<CommandLog[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);

  const fetchCommands = useCallback(async (p: number) => {
    try {
      const { data: res } = await getCommandLogs({ page: p, per_page: 20 });
      if (res.code === 0) {
        if (p === 1) setCommands(res.data.items);
        else setCommands((prev) => [...prev, ...res.data.items]);
        setHasMore(res.data.current_page < res.data.last_page);
      }
    } catch {
      setHasMore(false);
    }
  }, []);

  useEffect(() => { fetchCommands(1); }, [fetchCommands]);

  const loadMore = async () => {
    const next = page + 1;
    setPage(next);
    await fetchCommands(next);
  };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        命令历史
      </NavBar>

      <div style={{ padding: '8px 16px' }}>
        {commands.length === 0 && !hasMore ? (
          <EmptyState title="暂无命令记录" />
        ) : (
          commands.map((cmd) => (
            <div
              key={cmd.id}
              style={{
                background: 'var(--color-bg-card)',
                borderRadius: 'var(--card-radius)',
                padding: '14px 16px',
                marginBottom: 8,
                boxShadow: 'var(--card-shadow)',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <Tag color="primary" fill="outline">{cmd.action}</Tag>
                  <StatusTag status={cmd.status} />
                </div>
                <RelativeTime date={cmd.sent_at} />
              </div>
              <div style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
                {cmd.device?.name || `设备 #${cmd.device_id}`}
                {cmd.device?.location ? ` · ${cmd.device.location}` : ''}
              </div>
              {cmd.params && Object.keys(cmd.params).length > 0 && (
                <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 6, fontFamily: 'monospace', background: 'var(--color-fill)', padding: '6px 8px', borderRadius: 6 }}>
                  {JSON.stringify(cmd.params)}
                </div>
              )}
            </div>
          ))
        )}
      </div>
      <InfiniteScroll loadMore={loadMore} hasMore={hasMore} />
    </div>
  );
}
