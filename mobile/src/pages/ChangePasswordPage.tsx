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
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        修改密码
      </NavBar>

      <Form
        layout="horizontal"
        style={{ '--border-top': 'none' } as React.CSSProperties}
        footer={
          <Button block color="primary" loading={loading} onClick={handleSubmit} style={{ borderRadius: 8 }}>
            确认修改
          </Button>
        }
      >
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
  );
}
