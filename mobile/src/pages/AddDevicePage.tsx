import { useState, useEffect, useRef, useCallback } from 'react';
import { NavBar, Input, Button, Toast, DotLoading } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import {
  createProvisionCode,
  getProvisionStatus,
  type ProvisionDevice,
  type ProvisionStatus,
} from '@/api/provisioning';

type Phase = ProvisionStatus['status'];

function fmt(sec: number): string {
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
}

export default function AddDevicePage() {
  const navigate = useNavigate();
  const [location, setLocation] = useState('');
  const [code, setCode] = useState('');
  const [expiresIn, setExpiresIn] = useState(0);
  const [phase, setPhase] = useState<Phase>('pending');
  const [device, setDevice] = useState<ProvisionDevice | null>(null);
  const [generating, setGenerating] = useState(false);

  const pollRef = useRef<number | null>(null);
  const tickRef = useRef<number | null>(null);

  const stopTimers = useCallback(() => {
    if (pollRef.current) { window.clearInterval(pollRef.current); pollRef.current = null; }
    if (tickRef.current) { window.clearInterval(tickRef.current); tickRef.current = null; }
  }, []);

  // 卸载时清理定时器
  useEffect(() => stopTimers, [stopTimers]);

  const startPolling = useCallback((c: string) => {
    stopTimers();

    // 倒计时
    tickRef.current = window.setInterval(() => {
      setExpiresIn((s) => {
        if (s <= 1) {
          stopTimers();
          setPhase((cur) => (cur === 'pending' ? 'expired' : cur));
          return 0;
        }
        return s - 1;
      });
    }, 1000);

    // 轮询注册状态
    pollRef.current = window.setInterval(async () => {
      try {
        const { data: res } = await getProvisionStatus(c);
        if (res.code !== 0) return;
        if (res.data.status === 'registered') {
          stopTimers();
          setDevice(res.data.device);
          setPhase('registered');
        } else if (res.data.status === 'expired') {
          stopTimers();
          setPhase('expired');
        }
      } catch {
        /* 网络抖动忽略，下次继续 */
      }
    }, 3000);
  }, [stopTimers]);

  const handleGenerate = async () => {
    setGenerating(true);
    try {
      const { data: res } = await createProvisionCode(location.trim() || undefined);
      if (res.code === 0) {
        setCode(res.data.provision_code);
        setExpiresIn(res.data.expires_in);
        setDevice(null);
        setPhase('pending');
        startPolling(res.data.provision_code);
      } else {
        Toast.show({ content: '生成失败', icon: 'fail' });
      }
    } catch {
      Toast.show({ content: '生成失败', icon: 'fail' });
    } finally {
      setGenerating(false);
    }
  };

  const copyCode = async () => {
    try {
      await navigator.clipboard.writeText(code);
      Toast.show({ content: '配对码已复制', icon: 'success' });
    } catch {
      Toast.show({ content: code });
    }
  };

  const reset = () => {
    stopTimers();
    setCode('');
    setDevice(null);
    setExpiresIn(0);
    setPhase('pending');
  };

  const showForm = !code;

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        添加设备
      </NavBar>

      {/* 表单：选位置 + 生成码 */}
      {showForm && (
        <div className="surface-card detail-section-card" style={{ padding: 16, marginTop: 12 }}>
          <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 12, color: 'var(--color-text)' }}>新设备配网</div>
          <div style={{ marginBottom: 10, color: 'var(--color-text-secondary)', fontSize: 14 }}>设备位置（可选）</div>
          <Input placeholder="如 客厅、卧室" value={location} onChange={setLocation} clearable />
          <Button
            block
            color="primary"
            loading={generating}
            onClick={handleGenerate}
            style={{ borderRadius: 12, marginTop: 16 }}
          >
            生成配对码
          </Button>
          <div style={{ marginTop: 14, fontSize: 12, color: 'var(--color-text-tertiary)', lineHeight: 1.7 }}>
            生成后按提示，让设备连上你家 WiFi 并粘贴配对码，即可自动上线，无需手工填写 MQTT 信息。
          </div>
        </div>
      )}

      {/* 等待中：展示配对码 + 图文步骤 */}
      {!showForm && phase === 'pending' && (
        <>
          <div className="surface-card detail-section-card" style={{ padding: 20, marginTop: 12, textAlign: 'center' }}>
            <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 8 }}>配对码</div>
            <div style={{ fontSize: 34, fontWeight: 700, letterSpacing: 4, color: 'var(--color-primary)' }}>{code}</div>
            <div style={{ marginTop: 8, fontSize: 12, color: 'var(--color-text-tertiary)' }}>
              有效期剩余 {fmt(expiresIn)}
            </div>
            <Button size="small" fill="outline" color="primary" onClick={copyCode} style={{ borderRadius: 10, marginTop: 12 }}>
              复制配对码
            </Button>
          </div>

          <div className="surface-card detail-section-card" style={{ padding: 16 }}>
            <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 12, color: 'var(--color-text)' }}>接下来</div>
            <ol style={{ margin: 0, paddingLeft: 18, color: 'var(--color-text-secondary)', fontSize: 14, lineHeight: 2 }}>
              <li>给设备通电，等待配网指示灯闪烁</li>
              <li>手机 WiFi 连接到设备热点 <b>HG-Setup-xxxx</b></li>
              <li>打开弹出的配网页（或访问 192.168.4.1）</li>
              <li>填写家里的 WiFi 和密码，<b>粘贴上面的配对码</b>，提交</li>
              <li>回到本页，等待设备自动上线</li>
            </ol>
          </div>

          <div style={{ textAlign: 'center', color: 'var(--color-text-tertiary)', fontSize: 14, marginTop: 16 }}>
            <DotLoading color="var(--color-primary)" /> 等待设备上线…
          </div>
          <div style={{ textAlign: 'center', marginTop: 12 }}>
            <a onClick={reset} style={{ color: 'var(--color-primary)', fontSize: 14 }}>重新生成</a>
          </div>
        </>
      )}

      {/* 成功 */}
      {phase === 'registered' && (
        <div className="surface-card detail-section-card" style={{ padding: 24, marginTop: 12, textAlign: 'center' }}>
          <div style={{ fontSize: 40 }}>✅</div>
          <div style={{ fontSize: 18, fontWeight: 600, margin: '8px 0', color: 'var(--color-text)' }}>
            {device?.name || '设备'} 已上线
          </div>
          <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 20 }}>{device?.device_uid}</div>
          {device && (
            <Button block color="primary" onClick={() => navigate(`/mobile/device/${device.id}`)} style={{ borderRadius: 12, marginBottom: 10 }}>
              查看设备
            </Button>
          )}
          <Button block fill="outline" onClick={reset} style={{ borderRadius: 12 }}>
            继续添加设备
          </Button>
        </div>
      )}

      {/* 过期 */}
      {phase === 'expired' && (
        <div className="surface-card detail-section-card" style={{ padding: 24, marginTop: 12, textAlign: 'center' }}>
          <div style={{ fontSize: 40 }}>⏱️</div>
          <div style={{ fontSize: 16, fontWeight: 600, margin: '8px 0', color: 'var(--color-text)' }}>配对码已过期</div>
          <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginBottom: 20 }}>请重新生成后再试</div>
          <Button block color="primary" onClick={reset} style={{ borderRadius: 12 }}>
            重新生成
          </Button>
        </div>
      )}
    </div>
  );
}
