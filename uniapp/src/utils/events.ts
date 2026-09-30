/** uni-app emits detail.value; DOM typings do not describe native switch events. */
export function switchValue(event: unknown): boolean {
  return Boolean((event as { detail?: { value?: boolean } }).detail?.value);
}
