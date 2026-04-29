import { useState, useEffect, useCallback } from 'react';
import { NavBar, InfiniteScroll } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { getCommandLogs, type CommandLog } from '@/api/command';
import AppTag from '@/components/AppTag';
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
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        命令历史
      </NavBar>

      <div>
        <div className="screen-header" style={{ marginTop: 8 }}>
          <div>
            <div className="screen-header__title">命令历史</div>
            <div className="screen-header__subtitle">按设备和执行结果查看所有已下发命令。</div>
          </div>
        </div>

        <div className="surface-card detail-summary-card">
          <div className="surface-card__eyebrow">command log</div>
          <div className="surface-card__title">命令轨迹</div>
          <div className="surface-card__meta">
            <span className="soft-chip">记录 {commands.length}</span>
            <span className="soft-chip">分页加载</span>
            <span className="soft-chip">状态跟踪</span>
          </div>
        </div>

        {commands.length === 0 && !hasMore ? (
          <EmptyState title="暂无命令记录" />
        ) : (
          commands.map((cmd) => (
            <div key={cmd.id} className="command-log-card">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <AppTag tone="primary">{cmd.action}</AppTag>
                  <StatusTag status={cmd.status} />
                </div>
                <RelativeTime date={cmd.sent_at} />
              </div>
              <div style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
                {cmd.device?.name || `设备 #${cmd.device_id}`}
                {cmd.device?.location ? ` · ${cmd.device.location}` : ''}
              </div>
              {cmd.request_id && (
                <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 6 }}>
                  Request ID: {cmd.request_id}
                </div>
              )}
              {cmd.params && Object.keys(cmd.params).length > 0 && (
                <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 8, fontFamily: 'monospace', background: 'var(--color-fill)', padding: '8px 10px', borderRadius: 14 }}>
                  {JSON.stringify(cmd.params)}
                </div>
              )}
              {cmd.response && (
                <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 8, fontFamily: 'monospace', background: 'rgba(79, 124, 255, 0.06)', padding: '8px 10px', borderRadius: 14 }}>
                  {JSON.stringify(cmd.response)}
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
