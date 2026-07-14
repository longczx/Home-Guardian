import { createI18n } from 'vue-i18n';
import zhHans from './zh-Hans';
import en from './en';

/**
 * 自定义消息编译器：仅做 `{name}` 具名插值的纯字符串替换。
 * vue-i18n 默认编译器依赖 `new Function`，微信小程序禁用 eval 会报错；
 * 用此编译器可在 App / 小程序 / H5 全端安全运行。
 */
function messageCompiler(message: unknown) {
  if (typeof message !== 'string') {
    return () => '';
  }
  return (ctx: { named: (k: string) => unknown }) =>
    message.replace(/\{(\w+)\}/g, (_, key) => {
      const v = ctx.named(key);
      return v === undefined || v === null ? '' : String(v);
    });
}

// 中英双语；实际语言由 locale store 依「跟随系统 / 手选」决定（App 启动时 apply）。
export const i18n = createI18n({
  legacy: false,
  locale: 'zh-Hans',
  fallbackLocale: 'zh-Hans',
  messageCompiler,
  messages: {
    'zh-Hans': zhHans,
    en,
  },
});

export type MessageSchema = typeof zhHans;
