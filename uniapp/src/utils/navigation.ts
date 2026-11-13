export function goHome() {
  const url = '/pages/index/index';
  uni.switchTab({ url, fail: () => uni.reLaunch({ url }) });
}
