import { test, beforeEach, after } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = await mkdtemp(join(tmpdir(), 'hg-client-'));
const output = join(dir, 'runtime.mjs');
await build({
  stdin: { contents: `
    export { createPinia, setActivePinia } from 'pinia';
    export { useServerStore } from './src/stores/server';
    export { useAuthStore } from './src/stores/auth';
    export { apiRequest } from './src/api/request';
    export { connectWs, closeWs, onWs } from './src/utils/ws';
    export { provisioningStage } from './src/utils/provisioning-stage';
    export { withCommandResult } from './src/utils/command-result';
    export { goHome } from './src/utils/navigation';
    export { ensureReady } from './src/utils/guard';
    export { controlOptions, updateControlValues } from './src/utils/control-options';
    export { readGatewayModules, changeGatewayModule, modulePinError } from './src/utils/gateway-modules';
  `, resolveDir: process.cwd() },
  alias: { '@': resolve('src') }, outfile: output, bundle: true, platform: 'node', format: 'esm',
});
const runtime = await import(pathToFileURL(output));
let requests, sockets, server, auth;
beforeEach(() => {
  runtime.closeWs();
  requests = []; sockets = [];
  globalThis.uni = {
    getStorageSync: () => undefined, setStorageSync: () => {}, reLaunch: () => {},
    request: options => requests.push(options),
    connectSocket: () => {
      const handlers = {};
      const socket = { handlers, close: () => handlers.close?.(), send: () => {},
        onOpen: fn => { handlers.open = fn; }, onMessage: fn => { handlers.message = fn; },
        onClose: fn => { handlers.close = fn; }, onError: fn => { handlers.error = fn; } };
      sockets.push(socket); return socket;
    },
  };
  runtime.setActivePinia(runtime.createPinia());
  server = runtime.useServerStore(); auth = runtime.useAuthStore();
  server.servers = [{ id: 'a', name: 'A', url: 'https://a.example' }, { id: 'b', name: 'B', url: 'https://b.example' }];
  server.switchTo('a');
  auth.setSession('access-a', 'refresh-a', null, 'a');
  auth.setSession('access-b', 'refresh-b', null, 'b');
});
after(async () => { runtime.closeWs(); await rm(dir, { recursive: true, force: true }); });
const tick = () => new Promise(resolve => setImmediate(resolve));
function respond(request, statusCode, data) { request.success({ statusCode, data: { code: 0, data } }); }

test('ignores delayed responses including A -> B -> A switches', async () => {
  const pending = runtime.apiRequest('/devices');
  const rejected = assert.rejects(pending, /服务器已切换/);
  server.switchTo('b'); server.switchTo('a');
  respond(requests[0], 200, ['old data']);
  await rejected;
});

test('refresh writes only its originating server bucket after a switch', async () => {
  const pending = runtime.apiRequest('/devices');
  const rejected = assert.rejects(pending, /服务器已切换/);
  respond(requests[0], 401, null); await tick();
  assert.equal(requests[1].url, 'https://a.example/api/auth/refresh');
  server.switchTo('b');
  respond(requests[1], 200, { access_token: 'new-a', refresh_token: 'rotated-a' });
  await rejected;
  assert.equal(auth.byServer.a.accessToken, 'new-a');
  assert.equal(auth.byServer.b.accessToken, 'access-b');
  assert.equal(requests.length, 2);
});

test('concurrent 401 responses share a single refresh and retry at the same base', async () => {
  const one = runtime.apiRequest('/one'); const two = runtime.apiRequest('/two');
  respond(requests[0], 401, null); respond(requests[1], 401, null); await tick();
  assert.equal(requests.length, 3);
  respond(requests[2], 200, { access_token: 'new-a', refresh_token: 'rotated-a' }); await tick();
  assert.equal(requests.length, 5);
  assert.equal(requests[3].header.Authorization, 'Bearer new-a');
  respond(requests[3], 200, 1); respond(requests[4], 200, 2);
  assert.deepEqual(await Promise.all([one, two]), [1, 2]);
});

test('callbacks from an old websocket cannot deliver events or close its replacement', () => {
  const received = [];
  const off = runtime.onWs('telemetry', message => received.push(message));
  const old = sockets[0];
  server.switchTo('b'); runtime.connectWs(); const current = sockets[1];
  old.handlers.message({ data: JSON.stringify({ type: 'telemetry', value: 'wrong server' }) });
  old.handlers.close();
  runtime.connectWs(); assert.equal(sockets.length, 2);
  current.handlers.message({ data: JSON.stringify({ type: 'telemetry', value: 'B' }) });
  assert.equal(received.length, 1); assert.equal(received[0].value, 'B'); off();
});

test('device ACK arriving before the HTTP response still completes a command', async () => {
  const result = runtime.withCommandResult(async () => {
    sockets[0].handlers.message({ data: JSON.stringify({ type: 'command_reply', request_id: 'fast', status: 'replied_ok' }) });
    return { request_id: 'fast', status: 'queued' };
  }, 50);
  await result;
});

test('missing device ACK and negative ACK are surfaced to the caller', async () => {
  await assert.rejects(runtime.withCommandResult(async () => ({ request_id: 'missing', status: 'queued' }), 10), /尚未收到设备确认/);
  await assert.rejects(runtime.withCommandResult(async () => {
    sockets.at(-1).handlers.message({ data: JSON.stringify({ type: 'command_reply', request_id: 'failed', status: 'replied_error' }) });
    return { request_id: 'failed', status: 'queued' };
  }, 50), /设备执行失败/);
});

test('persisted command status resolves the result when a websocket ACK was missed', async () => {
  await runtime.withCommandResult(async () => ({ request_id: 'poll', status: 'queued' }), 50,
    async id => ({ request_id: id, status: 'replied_ok' }));
});

test('pairing registration never claims online before connection and initial data', () => {
  const device = { id: 1, name: 'gateway', device_uid: 'gw', is_online: false };
  assert.equal(runtime.provisioningStage({ status: 'registered', device }).ready, false);
  assert.equal(runtime.provisioningStage({ status: 'registered', stage: 'connecting', device }).ready, false);
  device.is_online = true;
  assert.equal(runtime.provisioningStage({ status: 'registered', stage: 'awaiting_data', device }).ready, false);
  assert.equal(runtime.provisioningStage({ status: 'registered', stage: 'ready', device }).ready, true);
  device.is_online = false;
  assert.equal(runtime.provisioningStage({ status: 'registered', stage: 'ready', device }).ready, false);
});

test('switching IR protocol selects its own model and keeps unrelated automation parameters', () => {
  const model = { param: 'model', default: 1, options_by: { field: 'protocol', values: {
    PANASONIC_AC: [{ value: 1, label: 'LKE' }, { value: 6, label: 'RKR' }],
    KELON168: [{ value: 1, label: 'RCH-R0Y3' }],
  } } };
  const current = { protocol: 'PANASONIC_AC', model: 6, power: true, temp: 25 };
  const next = runtime.updateControlValues([model], current, 'protocol', 'KELON168');
  assert.deepEqual(next, { protocol: 'KELON168', model: 1, power: true, temp: 25 });
  assert.equal(current.model, 6, 'The original editor snapshot must remain unchanged');
  assert.deepEqual(runtime.controlOptions(model, next), [{ value: 1, label: 'RCH-R0Y3' }]);
  assert.deepEqual(runtime.controlOptions(model, { protocol: 'unknown' }), []);
});


const modules = {
  module_config_version: 1, max_modules: 8,
  pins: { dht11: [4, 13, 26], sound: [32, 33, 34], ac_ir: [25, 26, 27] },
  modules: [{ uid: 'gw-ac', type: 'ac_ir', pin: 25, name: 'AC' }],
};
function ack(id, data, status = 'replied_ok') {
  sockets.at(-1).handlers.message({ data: JSON.stringify({ type: 'command_reply', request_id: id, status, data }) });
}
function accept(request, id, data, status = 'replied_ok') {
  ack(id, data, status);  // device reply may beat the HTTP response
  respond(request, 200, { request_id: id, status: 'queued' });
}

test('module pins follow device capabilities, reject conflicts and require new firmware', () => {
  assert.match(runtime.modulePinError({ modules: [] }, 'ac_ir', 25), /升级/);
  assert.match(runtime.modulePinError(modules, 'ac_ir', 34, 'gw-ac'), /允许/);
  assert.match(runtime.modulePinError(modules, 'dht11', 26.5), /允许/);
  assert.match(runtime.modulePinError(modules, 'dht11', 4.0), /^$/);
  assert.match(runtime.modulePinError(modules, 'ac_ir', 25), /占用/);
  assert.match(runtime.modulePinError(modules, 'ac_ir', 26), /一路/);
  assert.equal(runtime.modulePinError(modules, 'ac_ir', 26, 'gw-ac'), '');
});

test('command results return readback data via websocket and persisted polling fallback', async () => {
  const result = runtime.readGatewayModules(1);
  accept(requests[0], 'module-list', modules);
  assert.deepEqual(await result, modules);
  const fallback = runtime.withCommandResult(async () => ({ request_id: 'poll-data', status: 'queued' }), 100,
    async id => ({ request_id: id, status: 'replied_ok', reply: modules }));
  assert.deepEqual(await fallback, modules);
});

test('module update waits for device ACK and verifies the actual pin from readback', async () => {
  let complete = false;
  const pending = runtime.changeGatewayModule(1, 'update_module', { uid: 'gw-ac', pin: 26 }).then(value => { complete = true; return value; });
  respond(requests[0], 200, { request_id: 'update-pin', status: 'queued' });
  await tick();
  assert.equal(complete, false, 'HTTP queued is not device execution');
  ack('update-pin', { uid: 'gw-ac', pin: 26, applied: true });
  await tick();
  const read = requests.find(r => r.data?.action === 'list_modules');
  assert.ok(read);
  assert.equal(complete, false, 'ACK is followed by a real configuration read');
  const changed = { ...modules, modules: [{ ...modules.modules[0], pin: 26 }] };
  accept(read, 'readback', changed);
  assert.deepEqual(await pending, changed);
});

test('module failures expose device error and never report success or perform readback', async () => {
  const pending = runtime.changeGatewayModule(1, 'update_module', { uid: 'gw-ac', pin: 26 });
  const rejected = assert.rejects(pending, /GPIO 已被其他模块占用/);
  accept(requests[0], 'bad-pin', { message: 'GPIO 已被其他模块占用' }, 'replied_error');
  await rejected;
  assert.equal(requests.filter(r => r.data?.action === 'list_modules').length, 0);
});

test('a mismatched device readback rejects the update', async () => {
  const pending = runtime.changeGatewayModule(1, 'update_module', { uid: 'gw-ac', pin: 26 });
  const rejected = assert.rejects(pending, /不一致/);
  accept(requests[0], 'update-mismatch', { uid: 'gw-ac', pin: 26, applied: true });
  await tick();
  accept(requests.find(r => r.data?.action === 'list_modules'), 'mismatch-read', modules);
  await rejected;
});

test('module removal confirms absence in the device list, including an intentionally empty list', async () => {
  const pending = runtime.changeGatewayModule(1, 'remove_module', { uid: 'gw-ac' });
  accept(requests[0], 'remove', { uid: 'gw-ac', applied: true });
  await tick();
  accept(requests.find(r => r.data?.action === 'list_modules'), 'empty-read', { ...modules, modules: [] });
  assert.equal((await pending).modules.length, 0);
});

test('changing servers while managing modules cannot start a readback on the other server', async () => {
  const pending = runtime.changeGatewayModule(1, 'update_module', { uid: 'gw-ac', pin: 26 });
  const rejected = assert.rejects(pending, /服务器已切换/);
  server.switchTo('b');
  accept(requests[0], 'old-server-update', { uid: 'gw-ac', pin: 26, applied: true });
  await rejected;
  assert.equal(requests.length, 1);
});


test('module add uses the device-assigned UID and confirms its type and GPIO', async () => {
  const pending = runtime.changeGatewayModule(1, 'add_module', { type: 'sound', pin: 34 });
  accept(requests[0], 'add-sound', { uid: 'gw-sound-2', pin: 34, applied: true });
  await tick();
  const next = { ...modules, modules: [...modules.modules, { uid: 'gw-sound-2', type: 'sound', pin: 34, name: 'sound' }] };
  accept(requests.find(r => r.data?.action === 'list_modules'), 'added-read', next);
  assert.deepEqual(await pending, next);
});

test('module management rejects ACKs for another module and replies without applied confirmation', async () => {
  const wrong = runtime.changeGatewayModule(1, 'remove_module', { uid: 'gw-ac' });
  const rejected = assert.rejects(wrong, /回执与操作不一致/);
  accept(requests[0], 'wrong-module', { uid: 'another-ac', applied: true });
  await rejected;
  const old = runtime.changeGatewayModule(1, 'remove_module', { uid: 'gw-ac' });
  const oldRejected = assert.rejects(old, /未确认配置生效/);
  accept(requests.at(-1), 'old-firmware', { uid: 'gw-ac' });
  await oldRejected;
});


test('cold launch opens the home tab instead of a management subpage', async () => {
  const pages = JSON.parse(await readFile(resolve('src/pages.json'), 'utf8'));
  assert.equal(pages.pages[0].path, 'pages/index/index');
  assert.ok(pages.tabBar.list.some(page => page.pagePath === pages.pages[0].path));
  assert.ok(pages.pages.some(page => page.path === 'pages/manage/rooms'));
});

test('a restored room page can return home without any back-stack and has a relaunch fallback', () => {
  const calls = [];
  globalThis.uni.switchTab = options => { calls.push(['tab', options.url]); options.fail(); };
  globalThis.uni.reLaunch = options => calls.push(['relaunch', options.url]);
  runtime.goHome();
  assert.deepEqual(calls, [['tab', '/pages/index/index'], ['relaunch', '/pages/index/index']]);
});

test('protected management routes lead to server selection or login when session is missing', () => {
  const calls = [];
  globalThis.uni.reLaunch = options => calls.push(options.url);
  server.servers = [];
  assert.equal(runtime.ensureReady(), false);
  assert.equal(calls.at(-1), '/pages/server/list');
  server.servers = [{ id: 'fresh', name: 'Fresh', url: 'http://fresh.example' }];
  server.switchTo('fresh');
  assert.equal(runtime.ensureReady(), false);
  assert.equal(calls.at(-1), '/pages/auth/login');
});
