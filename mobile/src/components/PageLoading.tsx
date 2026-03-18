import { SpinLoading } from 'antd-mobile';

export default function PageLoading() {
  return (
    <div style={{
      display: 'flex',
      justifyContent: 'center',
      alignItems: 'center',
      minHeight: '60vh',
    }}>
      <SpinLoading color="primary" style={{ '--size': '36px' }} />
    </div>
  );
}
