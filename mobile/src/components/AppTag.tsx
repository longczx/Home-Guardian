interface AppTagProps {
  children: React.ReactNode;
  tone?: 'primary' | 'success' | 'warning' | 'danger' | 'default';
}

export default function AppTag({ children, tone = 'default' }: AppTagProps) {
  return <span className={`app-tag app-tag--${tone}`}>{children}</span>;
}