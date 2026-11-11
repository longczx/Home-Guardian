import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

test('pairing page copies the selected platform and invalidates codes and late responses after switching', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'hg-pairing-'));
  let app;
  try {
    const output = join(dir, 'page.mjs');
    await build({
      stdin: { contents: `
        export { createPinia, setActivePinia } from 'pinia';
        export { createRenderer } from 'vue';
        export { useServerStore } from './src/stores/server';
        export { useAuthStore } from './src/stores/auth';
        export { default as Page } from './src/pages/manage/device-add.vue';
      `, resolveDir: process.cwd() },
      alias: { '@': resolve('src') }, outfile: output, bundle: true, platform: 'node', format: 'esm',
      plugins: [{ name: 'vue-test', setup(builder) {
        builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
          const { descriptor } = parse(await readFile(path, 'utf8'));
          return { contents: compileScript(descriptor, { id: 'pairing-test' }).content, loader: 'ts', resolveDir: resolve('src/pages/manage') };
        });
        builder.onResolve({ filter: /^@dcloudio\/uni-app$/ }, () => ({ path: 'uni-app', namespace: 'mock' }));
        builder.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({ contents: 'export const onLoad = fn => fn({});' }));
      } }],
    });
    const runtime = await import(pathToFileURL(output));
    const requests = [], clipboard = [];
    globalThis.uni = {
      getStorageSync() {}, setStorageSync() {}, showToast() {},
      request: options => requests.push(options),
      setClipboardData: options => { clipboard.push(options.data); options.success?.(); },
    };
    runtime.setActivePinia(runtime.createPinia());
    const server = runtime.useServerStore();
    const auth = runtime.useAuthStore();
    const first = server.upsert('Home', 'http://107.149.87.86/api/');
    auth.setSession('test-access', 'test-refresh', null, first);
    const renderer = runtime.createRenderer({
      createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {},
    });
    let state;
    const original = runtime.Page.setup;
    app = renderer.createApp({ ...runtime.Page, setup(props, context) { state = original(props, context); return state; }, render() { return null; } });
    app.mount({});
    assert.equal(state.platformUrl.value, 'http://107.149.87.86');
    state.copyPlatformUrl();
    assert.equal(clipboard.at(-1), 'http://107.149.87.86');
    const respond = (request, data) => request.success({ statusCode: 200, data: { code: 0, data } });
    const generated = state.generate();
    respond(requests[0], { provision_code: 'PAIR-A', expires_in: 120 });
    await generated;
    assert.equal(state.code.value, 'PAIR-A');
    assert.equal(clipboard.at(-1), 'PAIR-A');
    server.upsert('Parents', 'https://second.example:8443/');
    assert.equal(state.code.value, '');
    assert.equal(state.status.value, 'idle');
    state.copyPlatformUrl();
    assert.equal(clipboard.at(-1), 'https://second.example:8443');
    server.switchTo(first);
    const pending = state.generate();
    server.upsert('Another', 'http://third.example');
    const before = clipboard.length;
    respond(requests[1], { provision_code: 'STALE-A', expires_in: 120 });
    await pending;
    assert.equal(state.code.value, '');
    assert.equal(state.busy.value, false);
    assert.equal(clipboard.length, before);  // no stale code auto-copied beside another platform
  } finally {
    app?.unmount();
    await rm(dir, { recursive: true, force: true });
  }
});
