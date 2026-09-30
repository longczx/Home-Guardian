import { test, beforeEach, after } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { mkdtemp, rm } from 'node:fs/promises';
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
    export { withCommandResult } from './src/utils/command-result';
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
