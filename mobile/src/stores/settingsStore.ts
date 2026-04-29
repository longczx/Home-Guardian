import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import {
  DEFAULT_CUSTOM_PALETTE,
  type CustomPalette,
  type ThemeMode,
} from '@/theme/palettes';

interface SettingsState {
  theme: ThemeMode;
  paletteId: string;
  customPalette: CustomPalette;
  setTheme: (theme: ThemeMode) => void;
  setPaletteId: (paletteId: string) => void;
  setCustomPalette: (palette: Partial<CustomPalette>) => void;
}

export const useSettingsStore = create<SettingsState>()(
  persist(
    (set) => ({
      theme: 'system',
      paletteId: 'cloud-blue',
      customPalette: DEFAULT_CUSTOM_PALETTE,
      setTheme: (theme) => set({ theme }),
      setPaletteId: (paletteId) => set({ paletteId }),
      setCustomPalette: (palette) => set((state) => ({
        customPalette: {
          ...state.customPalette,
          ...palette,
        },
      })),
    }),
    { name: 'hg-settings' }
  )
);
