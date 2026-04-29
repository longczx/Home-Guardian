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
    <div className="auth-shell">
      <div className="auth-shell__inner">
        <div className="page-hero" style={{ marginBottom: 16 }}>
          <div className="auth-mark">
          HG
          </div>
          <div className="auth-title">Home Guardian</div>
          <div className="auth-subtitle">进入家庭设备与环境控制台，实时查看状态并处理告警。</div>
          <div className="page-hero__meta" style={{ justifyContent: 'center' }}>
            <span className="soft-chip">IoT Dashboard</span>
            <span className="soft-chip">PWA Ready</span>
          </div>
        </div>

        <div className="glass-card auth-card">
          <Form
            layout="vertical"
            onFinish={onFinish}
            footer={
              <div className="submit-wrap">
                <Button block type="submit" color="primary" size="large" loading={loading}>
                  登录
                </Button>
              </div>
            }
          >
            <Form.Item name="username" label="用户名" rules={[{ required: true, message: '请输入用户名' }]}>
              <Input placeholder="用户名" clearable autoComplete="username" style={{ fontSize: 16 }} />
            </Form.Item>
            <Form.Item name="password" label="密码" rules={[{ required: true, message: '请输入密码' }]}>
              <Input type="password" placeholder="密码" clearable autoComplete="current-password" style={{ fontSize: 16 }} />
            </Form.Item>
          </Form>
        </div>
      </div>
    </div>
  );
}
