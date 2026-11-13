import { test, beforeEach, afterEach, after } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = await mkdtemp(join(tmpdir(), 'hg-onboarding-'));
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
      return { contents: compileScript(descriptor, { id: 'onboarding-test' }).content, loader: 'ts', resolveDir: resolve('src/pages/manage') };
    });
    builder.onResolve({ filter: /^@dcloudio\/uni-app$/ }, () => ({ path: 'uni-app', namespace: 'mock' }));
    builder.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({ contents: 'export const onLoad = fn => fn(globalThis.onboardingQuery || {});' }));
  } }],
});
const runtime = await import(pathToFileURL(output));
let app, state, requests, clipboard, routes, notices, server, auth, first;
beforeEach(() => {
  requests = []; clipboard = []; routes = []; notices = [];
  globalThis.uni = {
    getStorageSync() {}, setStorageSync() {}, showToast: options => notices.push(options.title),
    request: options => requests.push(options),
    reLaunch: options => routes.push(options.url),
    navigateTo: options => routes.push(options.url),
    setClipboardData: options => { clipboard.push(options.data); options.success?.(); },
  };
  runtime.setActivePinia(runtime.createPinia());
  server = runtime.useServerStore(); auth = runtime.useAuthStore();
  first = server.upsert('Home', 'http://107.149.87.86/api/');
  auth.setSession('test-access', 'test-refresh', null, first);
});
function mount(query = {}) {
  globalThis.onboardingQuery = query;
  const renderer = runtime.createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {} });
  const original = runtime.Page.setup;
  app = renderer.createApp({ ...runtime.Page, setup(props, context) { state = original(props, context); return state; }, render() { return null; } });
  app.mount({});
}
afterEach(() => { app?.unmount(); app = undefined; });
after(async () => { await rm(dir, { recursive: true, force: true }); });
const respond = (request, data) => request.success({ statusCode: 200, data: { code: 0, data } });
const page = (items, lastPage = 1) => ({ items, current_page: 1, last_page: lastPage, total: items.length, per_page: 100 });
const online = { id: 12, device_uid: 'esp32-abcd', name: 'Living room', type: 'gateway', gateway_uid: null, is_online: true };

test('initial device addition asks for an onboarding path without provisioning or server creation', async () => {
  mount();
  assert.equal(state.mode.value, 'choose');
  await state.generate();
  assert.equal(requests.length, 0); assert.equal(routes.length, 0);
  await state.chooseMode('gateway');
  assert.equal(state.mode.value, 'gateway');
  assert.equal(requests.length, 0, 'Choosing new gateway must not silently create an authorization code');
  state.location.value = 'Living room';
  const pending = state.generate();
  assert.equal(requests[0].url, 'http://107.149.87.86/api/provisioning/codes');
  assert.equal(requests[0].data.location, 'Living room');
  respond(requests[0], { provision_code: 'PAIR-A', expires_in: 120 }); await pending;
  assert.equal(state.code.value, 'PAIR-A'); assert.equal(clipboard.at(-1), 'PAIR-A');
  assert.equal(routes.length, 0);
});

test('pairing page copies its platform and invalidates codes and late responses on server switches', async () => {
  mount(); await state.chooseMode('gateway');
  state.copyPlatformUrl(); assert.equal(clipboard.at(-1), 'http://107.149.87.86');
  const generated = state.generate();
  respond(requests[0], { provision_code: 'PAIR-A', expires_in: 120 }); await generated;
  server.upsert('Parents', 'https://second.example:8443/');
  assert.equal(state.code.value, ''); assert.equal(state.mode.value, 'choose');
  state.copyPlatformUrl(); assert.equal(clipboard.at(-1), 'https://second.example:8443');
  server.switchTo(first); await state.chooseMode('gateway');
  const pending = state.generate();
  server.upsert('Another', 'http://third.example');
  const before = clipboard.length;
  respond(requests[1], { provision_code: 'STALE-A', expires_in: 120 }); await pending;
  assert.equal(state.code.value, ''); assert.equal(state.busy.value, false); assert.equal(clipboard.length, before);
});

test('existing-module path selects only root gateways and never creates pairing codes', async () => {
  mount(); const pending = state.chooseMode('module');
  assert.match(requests[0].url, /type=gateway/);
  respond(requests[0], page([online, { ...online, id: 13, is_online: false },
    { ...online, id: 14, type: 'sensor' }, { ...online, id: 15, gateway_uid: 'parent' }])); await pending;
  assert.deepEqual(state.gateways.value.map(g => g.id), [12, 13]);
  await state.generate(); assert.equal(requests.length, 1);
  state.openModules(13); assert.equal(routes.length, 0); assert.match(notices.at(-1), /离线/);
  state.openModules(14); state.openModules(999); assert.equal(routes.length, 0);
  state.openModules(12); assert.equal(routes.at(-1), '/pages/manage/gateway-modules?id=12');
});

test('gateway selection supports pagination and retains previously loaded gateways', async () => {
  mount(); const firstPage = state.chooseMode('module');
  respond(requests[0], page([online], 2)); await firstPage;
  assert.equal(state.gatewaysHasMore.value, true);
  const nextPage = state.loadGateways(true); assert.match(requests[1].url, /page=2/);
  respond(requests[1], page([online, { ...online, id: 16 }], 2)); await nextPage;
  assert.deepEqual(state.gateways.value.map(g => g.id), [12, 16]); assert.equal(state.gatewaysHasMore.value, false);
});

test('empty gateway list can lead to new gateway setup without generating a code automatically', async () => {
  mount(); const pending = state.chooseMode('module'); respond(requests[0], page([])); await pending;
  assert.equal(state.gateways.value.length, 0);
  await state.chooseMode('gateway'); assert.equal(state.mode.value, 'gateway'); assert.equal(requests.length, 1);
});

test('gateway-list failures stay on the module path and retries only read devices', async () => {
  mount(); const pending = state.chooseMode('module');
  requests[0].success({ statusCode: 503, data: { code: 1, message: '网关列表暂不可用' } }); await pending;
  assert.equal(state.mode.value, 'module'); assert.match(state.gatewaysError.value, /暂不可用/);
  assert.equal(state.gatewaysLoading.value, false);
  const retry = state.loadGateways(); respond(requests[1], page([online])); await retry;
  assert.equal(state.gateways.value[0].id, 12); assert.ok(requests.every(r => r.method === 'GET'));
});

test('server changes or leaving module selection discard delayed gateway responses', async () => {
  mount(); const firstLoad = state.chooseMode('module'); state.backToChoices(); await state.chooseMode('gateway');
  respond(requests[0], page([online])); await firstLoad;
  assert.equal(state.mode.value, 'gateway'); assert.equal(state.gateways.value.length, 0);
  state.backToChoices(); const secondLoad = state.chooseMode('module'); server.upsert('Other', 'http://other.example');
  respond(requests[1], page([online])); await secondLoad;
  assert.equal(state.mode.value, 'choose'); assert.equal(state.gateways.value.length, 0);
  state.openModules(online.id); assert.equal(routes.length, 0);
});

test('re-provisioning keeps the original authorization target and bypasses the choice screen', async () => {
  mount({ rebind: '77' }); assert.equal(state.mode.value, 'gateway');
  await state.chooseMode('module'); state.backToChoices(); assert.equal(state.mode.value, 'gateway');
  const pending = state.generate(); assert.equal(requests[0].data.rebind_device_id, 77);
  respond(requests[0], { provision_code: 'REBIND', expires_in: 120 }); await pending;
  assert.equal(state.code.value, 'REBIND');
});

test('deep-link device addition requires login before provisioning or gateway queries', async () => {
  auth.logout(); mount(); assert.equal(routes.at(-1), '/pages/auth/login');
  await state.chooseMode('module'); await state.generate(); assert.equal(requests.length, 0);
});


test('newly registered gateway can proceed to module management and cannot reuse its ID after switching platforms', () => {
  mount();
  state.registeredId.value = 77;
  state.configureModules();
  assert.equal(routes.at(-1), '/pages/manage/gateway-modules?id=77');
  const count = routes.length;
  server.upsert('Other', 'http://other.example');
  state.configureModules();
  assert.equal(routes.length, count);
});
