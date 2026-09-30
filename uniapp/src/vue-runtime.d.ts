import 'vue';

declare module 'vue' {
  interface ComponentCustomProperties {
    uni: typeof uni;
  }
}
