import { createI18n } from 'vue-i18n';
import zhHans from './zh-Hans';

// 首版仅中文；i18n 框架已就位，PR4 补英文只需新增 messages，无需改代码。
export const i18n = createI18n({
  legacy: false,
  locale: 'zh-Hans',
  fallbackLocale: 'zh-Hans',
  messages: {
    'zh-Hans': zhHans,
  },
});

export type MessageSchema = typeof zhHans;
