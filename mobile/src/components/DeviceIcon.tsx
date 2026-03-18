import {
  AppstoreOutline,
  SetOutline,
  CheckShieldOutline,
  SoundOutline,
  VideoOutline,
  GlobalOutline,
  PayCircleOutline,
} from 'antd-mobile-icons';

const iconMap: Record<string, React.ReactNode> = {
  sensor: <CheckShieldOutline />,
  actuator: <SetOutline />,
  camera: <VideoOutline />,
  gateway: <GlobalOutline />,
  alarm: <SoundOutline />,
  meter: <PayCircleOutline />,
};

interface DeviceIconProps {
  type: string;
  size?: number;
  color?: string;
}

export default function DeviceIcon({ type, size = 22, color = 'var(--color-primary)' }: DeviceIconProps) {
  const icon = iconMap[type] || <AppstoreOutline />;
  return (
    <span style={{ fontSize: size, color, display: 'inline-flex', alignItems: 'center' }}>
      {icon}
    </span>
  );
}
