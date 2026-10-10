import test from 'node:test';
import assert from 'node:assert/strict';
import { createNotificationCounts } from '../src/layouts/notification-counts.js';

test('rapid route changes share a summary for thirty seconds while explicit notification changes refresh immediately', async () => {
  let time = 0, calls = 0;
  const actor = { user: { id: 1 } };
  const reader = createNotificationCounts({ now: () => time, identity: () => actor, allowed: () => true, publish: () => {}, api: { request: async () => { calls++; return { data: { unread: 0, sidebar_counts: {} } }; } } });
  await reader.refresh();
  for (let i = 0; i < 10; i++) await reader.refresh({ force: false });
  assert.equal(calls, 1);
  await reader.refresh(); assert.equal(calls, 2);
  time = 30001; await reader.refresh({ force: false }); assert.equal(calls, 3);
  reader.clear(); await reader.refresh({ force: false }); assert.equal(calls, 4);
});

test('sidebar badges use only validated actual counts, clear unavailable data, and make no denied request', async () => {
  let actor = { user: { id: 1 } }, can = false, value, calls = 0;
  const api = { request: async (path) => { calls++; assert.equal(path, '/notifications/summary'); return { data: { unread: 3, sidebar_counts: { support: 2, wallets: 1, notifications: 3, ignored: '8', '__proto__': 7 } } }; } };
  const reader = createNotificationCounts({ api, identity: () => actor, allowed: () => can, publish: result => { value = result; } });
  await reader.refresh(); assert.equal(calls, 0); assert.equal(value, null);
  can = true; await reader.refresh(); assert.equal(value.unread, 3); assert.equal(value.counts.support, 2); assert.equal(value.counts.wallets, 1); assert.equal(value.counts.ignored, undefined); assert.equal(Object.getPrototypeOf(value.counts), null);
  api.request = async () => { throw new Error('Offline'); }; await reader.refresh(); assert.equal(value, null);
  api.request = async () => ({ data: { unread: -1, sidebar_counts: {} } }); await reader.refresh(); assert.equal(value, null);
  reader.dispose(); assert.equal(value, null);
});

test('previous identity and aborted refresh cannot republish counts after account change or unmount', async () => {
  let actor = { user: { id: 1 } }, value = null;
  const pending = [];
  const reader = createNotificationCounts({ api: { request: (path, options) => new Promise(resolve => pending.push({ resolve, signal: options.signal })) }, identity: () => actor, allowed: () => true, publish: result => { value = result; } });
  const first = reader.refresh(); actor = { user: { id: 2 } }; reader.clear(); const second = reader.refresh();
  assert.equal(pending[0].signal.aborted, true);
  pending[0].resolve({ data: { unread: 99, sidebar_counts: { support: 99 } } }); await first; assert.equal(value, null);
  pending[1].resolve({ data: { unread: 1, sidebar_counts: { support: 1 } } }); await second; assert.equal(value.unread, 1);
  const third = reader.refresh(); reader.dispose(); assert.equal(pending[2].signal.aborted, true);
  pending[2].resolve({ data: { unread: 100, sidebar_counts: {} } }); await third; assert.equal(value, null);
});
