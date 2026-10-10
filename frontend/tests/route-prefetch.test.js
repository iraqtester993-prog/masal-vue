import test from 'node:test';
import assert from 'node:assert/strict';
import { createRoutePrefetch } from '../src/router/prefetch.js';

test('prefetch downloads only permitted component code and honors data saving', async () => {
  let calls = 0, allowed = false;
  const load = async () => { calls++; }, router = { resolve: () => ({ matched: [{ components: { default: load } }] }) };
  const prefetch = createRoutePrefetch(router, () => allowed, {});
  prefetch('/accounts'); await Promise.resolve(); assert.equal(calls, 0);
  allowed = true; prefetch('/accounts'); prefetch('/accounts'); await Promise.resolve(); assert.equal(calls, 1);
  createRoutePrefetch(router, () => true, { saveData: true })('/accounts'); await Promise.resolve(); assert.equal(calls, 1);
});
