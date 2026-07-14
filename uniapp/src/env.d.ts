/// <reference types="@dcloudio/types" />

declare module '*.vue' {
  import { DefineComponent } from 'vue';
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}

// vendored uQRCode（UMD，无类型声明）
declare module '@/utils/uqrcode/uqrcode.js' {
  const UQRCode: {
    new (): {
      data: string;
      size: number;
      make(): void;
      canvasContext: unknown;
      drawCanvas(): void;
    };
  };
  export default UQRCode;
}
