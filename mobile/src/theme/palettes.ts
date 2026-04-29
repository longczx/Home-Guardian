export type ThemeMode = 'light' | 'dark' | 'system';

export interface ThemePalette {
  id: string;
  name: string;
  description: string;
  preview: [string, string, string];
  colors: {
    primary: string;
    accent: string;
    success: string;
    warning: string;
    danger: string;
  };
}

export interface CustomPalette {
  primary: string;
  accent: string;
  success: string;
  warning: string;
  danger: string;
}

export const DEFAULT_CUSTOM_PALETTE: CustomPalette = {
  primary: '#4f7cff',
  accent: '#a478ff',
  success: '#45b87a',
  warning: '#f5a623',
  danger: '#f15b6c',
};

export const THEME_PALETTES: ThemePalette[] = [
  {
    id: 'cloud-blue',
    name: '云岚蓝',
    description: '接近参考图的清透蓝白，适合日常控制面板。',
    preview: ['#4f7cff', '#8eb4ff', '#eef4ff'],
    colors: {
      primary: '#4f7cff',
      accent: '#8f79ff',
      success: '#4ebc89',
      warning: '#f4b63d',
      danger: '#f16c7f',
    },
  },
  {
    id: 'sage-home',
    name: '鼠尾草',
    description: '更柔和的居家色调，适合温湿度和环境类展示。',
    preview: ['#5d9c8a', '#98c8bb', '#f2f7f4'],
    colors: {
      primary: '#5d9c8a',
      accent: '#7e88e8',
      success: '#48b07b',
      warning: '#eca94a',
      danger: '#e36c7a',
    },
  },
  {
    id: 'sand-gold',
    name: '砂金米',
    description: '暖白与柔金搭配，适合更生活化的氛围。',
    preview: ['#d79b51', '#f1c27b', '#fff7eb'],
    colors: {
      primary: '#d79b51',
      accent: '#7c8cff',
      success: '#58b983',
      warning: '#efb547',
      danger: '#ef7676',
    },
  },
  {
    id: 'graphite-night',
    name: '夜幕石墨',
    description: '偏深色科技感方案，适合夜间查看。',
    preview: ['#6f86ff', '#a4b1ff', '#1d2338'],
    colors: {
      primary: '#6f86ff',
      accent: '#8e72ff',
      success: '#59c98d',
      warning: '#ffb84c',
      danger: '#ff7385',
    },
  },
];

export function getPaletteById(paletteId: string) {
  return THEME_PALETTES.find((item) => item.id === paletteId) ?? THEME_PALETTES[0];
}