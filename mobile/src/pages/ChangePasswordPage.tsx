import { useState } from 'react';
import { NavBar, Form, Input, Button, Toast } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import request from '@/api/request';

export default function ChangePasswordPage() {
  const navigate = useNavigate();
  const [oldPwd, setOldPwd] = useState('');
  const [newPwd, setNewPwd] = useState('');
  const [confirmPwd, setConfirmPwd] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async () => {
    if (!oldPwd || !newPwd || !confirmPwd) {
      Toast.show({ content: '请填写完整', icon: 'fail' });
      return;
    }
    if (newPwd !== confirmPwd) {
      Toast.show({ content: '两次密码不一致', icon: 'fail' });
      return;
    }
    if (newPwd.length < 6) {
      Toast.show({ content: '密码至少6位', icon: 'fail' });
      return;
    }
    setLoading(true);
    try {
      await request.post('/auth/change-password', {
        old_password: oldPwd,
        new_password: newPwd,
      });
      Toast.show({ content: '密码修改成功', icon: 'success' });
      navigate(-1);
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || '修改失败';
      Toast.show({ content: message, icon: 'fail' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        修改密码
      </NavBar>

      <div className="screen-header" style={{ marginTop: 8 }}>
        <div>
          <div className="screen-header__title">修改密码</div>
          <div className="screen-header__subtitle">更新当前账号密码，并保持现有校验和接口流程。</div>
        </div>
      </div>

      <div className="detail-hero-panel detail-hero-panel--form">
        <div className="detail-hero-panel__main">
          <div className="detail-hero-panel__eyebrow">security</div>
          <div className="detail-hero-panel__title">账号安全</div>
          <div className="detail-hero-panel__subtitle">新密码至少 6 位，并通过确认密码保证输入一致。</div>
        </div>
      </div>

      <div className="form-shell">
        <div className="surface-card form-card form-card--layered">
          <div className="form-card__title">密码信息</div>
          <div className="form-card__subtitle">新密码至少 6 位，两次输入必须保持一致。</div>
          <Form layout="vertical" style={{ '--border-top': 'none' } as React.CSSProperties}>
            <Form.Item label="当前密码">
              <Input type="password" value={oldPwd} onChange={setOldPwd} placeholder="输入当前密码" autoComplete="current-password" />
            </Form.Item>
            <Form.Item label="新密码">
              <Input type="password" value={newPwd} onChange={setNewPwd} placeholder="输入新密码" autoComplete="new-password" />
            </Form.Item>
            <Form.Item label="确认密码">
              <Input type="password" value={confirmPwd} onChange={setConfirmPwd} placeholder="再次输入新密码" autoComplete="new-password" />
            </Form.Item>
          </Form>
        </div>

        <div className="submit-wrap">
          <Button block color="primary" loading={loading} onClick={handleSubmit}>
            确认修改
          </Button>
        </div>
      </div>
    </div>
  );
}
