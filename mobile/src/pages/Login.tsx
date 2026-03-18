import { useState } from 'react';
import { Form, Input, Button, Toast } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { login as loginApi } from '@/api/auth';
import { useAuthStore } from '@/stores/authStore';
import { useTheme } from '@/hooks/useTheme';

export default function Login() {
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { setTokens, setUser } = useAuthStore();
  useTheme();

  const onFinish = async (values: { username: string; password: string }) => {
    setLoading(true);
    try {
      const { data: res } = await loginApi(values.username, values.password);
      if (res.code !== 0) {
        Toast.show({ content: res.data?.toString() || '登录失败', icon: 'fail' });
        return;
      }
      setTokens(res.data.access_token, res.data.refresh_token);
      setUser(res.data.user);
      Toast.show({ content: '登录成功', icon: 'success' });
      navigate('/mobile', { replace: true });
    } catch (err: unknown) {
      const message =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ||
        '网络错误';
      Toast.show({ content: message, icon: 'fail' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        padding: '0 24px',
        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
      }}
    >
      <div style={{ textAlign: 'center', marginBottom: 32 }}>
        <div style={{
          width: 72, height: 72, borderRadius: 18, margin: '0 auto 16px',
          background: 'rgba(255,255,255,0.2)',
          backdropFilter: 'blur(8px)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 32, color: '#fff', fontWeight: 700,
        }}>
          HG
        </div>
        <h1 style={{ fontSize: 26, fontWeight: 700, color: '#fff', marginBottom: 6 }}>
          Home Guardian
        </h1>
        <p style={{ color: 'rgba(255,255,255,0.7)', fontSize: 14, margin: 0 }}>
          智能家居控制面板
        </p>
      </div>

      <div
        style={{
          background: 'var(--color-bg-card)',
          borderRadius: 16,
          padding: '32px 24px',
          boxShadow: '0 8px 32px rgba(0,0,0,0.15)',
        }}
      >
        <Form onFinish={onFinish} footer={
          <Button block type="submit" color="primary" size="large" loading={loading} style={{ borderRadius: 10, fontWeight: 600 }}>
            登录
          </Button>
        }>
          <Form.Item name="username" rules={[{ required: true, message: '请输入用户名' }]}>
            <Input placeholder="用户名" clearable autoComplete="username" style={{ fontSize: 16 }} />
          </Form.Item>
          <Form.Item name="password" rules={[{ required: true, message: '请输入密码' }]}>
            <Input type="password" placeholder="密码" clearable autoComplete="current-password" style={{ fontSize: 16 }} />
          </Form.Item>
        </Form>
      </div>
    </div>
  );
}
