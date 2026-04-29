import { NavBar } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { useTheme } from '@/hooks/useTheme';
import {
  THEME_PALETTES,
  type CustomPalette,
  type ThemeMode,
} from '@/theme/palettes';

const modeOptions: Array<{ value: ThemeMode; label: string; description: string }> = [
  { value: 'light', label: '浅色', description: '白底、通透、高亮卡片。' },
  { value: 'dark', label: '深色', description: '夜间模式，强调对比。' },
  { value: 'system', label: '跟随系统', description: '跟随设备当前配色。' },
];

const customFields: Array<{ key: keyof CustomPalette; label: string }> = [
  { key: 'primary', label: '主色' },
  { key: 'accent', label: '强调色' },
  { key: 'success', label: '成功色' },
  { key: 'warning', label: '警告色' },
  { key: 'danger', label: '危险色' },
];

export default function ThemeCenterPage() {
  const navigate = useNavigate();
  const {
    theme,
    paletteId,
    customPalette,
    setTheme,
    setPaletteId,
    setCustomPalette,
  } = useTheme();

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        外观主题
      </NavBar>

      <div className="screen-header" style={{ marginTop: 8 }}>
        <div className="screen-header__title">整体视觉中心</div>
        <div className="screen-header__subtitle">切换成熟配色方案，或者直接定义整套产品主色。</div>
      </div>

      <div className="theme-preview-board">
        <div className="theme-preview-card theme-preview-card--hero">
          <div className="theme-preview-card__label">当前效果</div>
          <div className="theme-preview-card__value">Home Dashboard</div>
          <div className="theme-preview-card__meta">主色、强调色和状态色会立即作用到首页、设备、自动化、告警和我的页面。</div>
          <div className="theme-preview-swatches">
            {Object.values(paletteId === 'custom' ? customPalette : THEME_PALETTES.find((item) => item.id === paletteId)?.colors ?? customPalette).map((color) => (
              <span key={color} className="theme-preview-swatches__item" style={{ background: color }} />
            ))}
          </div>
        </div>
      </div>

      <div className="section-row">
        <span className="section-title">显示模式</span>
      </div>
      <div className="selection-stack">
        {modeOptions.map((item) => (
          <button
            key={item.value}
            className={`selection-card ${theme === item.value ? 'selection-card--active' : ''}`}
            onClick={() => setTheme(item.value)}
          >
            <span>
              <strong>{item.label}</strong>
              <small>{item.description}</small>
            </span>
            <span className="selection-card__check" />
          </button>
        ))}
      </div>

      <div className="section-row">
        <span className="section-title">成熟色系</span>
      </div>
      <div className="palette-grid">
        {THEME_PALETTES.map((palette) => (
          <button
            key={palette.id}
            className={`palette-card ${paletteId === palette.id ? 'palette-card--active' : ''}`}
            onClick={() => setPaletteId(palette.id)}
          >
            <div className="palette-card__swatches">
              {palette.preview.map((color) => (
                <span key={color} style={{ background: color }} />
              ))}
            </div>
            <div className="palette-card__title">{palette.name}</div>
            <div className="palette-card__description">{palette.description}</div>
          </button>
        ))}
      </div>

      <div className="section-row">
        <span className="section-title">自定义色系</span>
        <span className="section-link" onClick={() => setPaletteId('custom')}>应用自定义</span>
      </div>
      <div className="glass-card custom-palette-panel">
        {customFields.map((field) => (
          <label key={field.key} className="custom-palette-row">
            <span>{field.label}</span>
            <div className="custom-palette-row__controls">
              <input
                type="color"
                value={customPalette[field.key]}
                onChange={(event) => {
                  setPaletteId('custom');
                  setCustomPalette({ [field.key]: event.target.value });
                }}
              />
              <input
                type="text"
                value={customPalette[field.key]}
                onChange={(event) => {
                  setPaletteId('custom');
                  setCustomPalette({ [field.key]: event.target.value });
                }}
              />
            </div>
          </label>
        ))}
      </div>
    </div>
  );
}