import { useEffect } from 'react';
import { useSettingsStore } from '@/stores/settingsStore';
import { getPaletteById } from '@/theme/palettes';

export function useTheme() {
  const theme = useSettingsStore((s) => s.theme);
  const paletteId = useSettingsStore((s) => s.paletteId);
  const customPalette = useSettingsStore((s) => s.customPalette);
  const setTheme = useSettingsStore((s) => s.setTheme);
  const setPaletteId = useSettingsStore((s) => s.setPaletteId);
  const setCustomPalette = useSettingsStore((s) => s.setCustomPalette);

  useEffect(() => {
    const apply = (t: 'light' | 'dark') => {
      document.documentElement.setAttribute('data-theme', t);
    };

    const root = document.documentElement;
    const palette = paletteId === 'custom' ? customPalette : getPaletteById(paletteId).colors;

    root.style.setProperty('--color-primary', palette.primary);
    root.style.setProperty('--color-primary-strong', palette.primary);
    root.style.setProperty('--color-accent', palette.accent);
    root.style.setProperty('--color-success', palette.success);
    root.style.setProperty('--color-warning', palette.warning);
    root.style.setProperty('--color-danger', palette.danger);

    if (theme === 'system') {
      const mq = window.matchMedia('(prefers-color-scheme: dark)');
      apply(mq.matches ? 'dark' : 'light');
      const handler = (e: MediaQueryListEvent) => apply(e.matches ? 'dark' : 'light');
      mq.addEventListener('change', handler);
      return () => mq.removeEventListener('change', handler);
    }

    apply(theme);
  }, [customPalette, paletteId, theme]);

  return { theme, paletteId, customPalette, setTheme, setPaletteId, setCustomPalette };
}
