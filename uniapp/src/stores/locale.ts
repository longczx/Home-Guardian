import { defineStore } from 'pinia';
import { i18n } from '@/locale';

export type Lang = 'zh-Hans' | 'en' | 'system';

const STORAGE_KEY = 'hg_lang';

function systemLang(): 'zh-Hans' | 'en' {
  try {
    const sys = uni.getSystemInfoSync().language || 'zh';
    return sys.toLowerCase().startsWith('zh') ? 'zh-Hans' : 'en';
  } catch {
    return 'zh-Hans';
  }
}

function resolve(pref: Lang): 'zh-Hans' | 'en' {
  return pref === 'system' ? systemLang() : pref;
}

export const useLocaleStore = defineStore('locale', {
  state: () => ({
    pref: 'system' as Lang,
  }),
  getters: {
    active(): 'zh-Hans' | 'en' {
      return resolve(this.pref);
    },
  },
  actions: {
    restore() {
      this.pref = (uni.getStorageSync(STORAGE_KEY) as Lang) || 'system';
      this.apply();
    },
    set(pref: Lang) {
      this.pref = pref;
      uni.setStorageSync(STORAGE_KEY, pref);
      this.apply();
    },
    apply() {
      (i18n.global.locale as unknown as { value: string }).value = resolve(this.pref);
    },
  },
});
