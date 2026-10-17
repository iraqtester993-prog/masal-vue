import test from 'node:test';
import assert from 'node:assert/strict';
import { createApiClient } from '../src/shared/api/client.js';

function environment(t, fetch) {
  t.mock.method(globalThis, 'fetch', fetch);
  const oldDocument = globalThis.document, oldStorage = globalThis.sessionStorage;
  globalThis.document = { cookie: 'XSRF-TOKEN=current' };
  const data = new Map(), writes = [];
  globalThis.sessionStorage = { getItem: key => data.get(key) ?? null, setItem: (key, value) => { writes.push(key); data.set(key, value); }, removeItem: key => data.delete(key) };
  t.after(() => {
    if (oldDocument === undefined) delete globalThis.document; else globalThis.document = oldDocument;
    if (oldStorage === undefined) delete globalThis.sessionStorage; else globalThis.sessionStorage = oldStorage;
  });
  return { data, writes };
}

test('products, offers and exact account filters hydrate from session memory without new persistent data', async t => {
  const calls = [];
  const { writes } = environment(t, async url => { calls.push(url); return Response.json({ data: [{ id: calls.length }] }); });
  const api = createApiClient('/api/v1'); api.setReadScope('pos:1');
  await api.request('/sales/products'); await api.request('/sales/products?');
  await api.request('/digital/offers?service=topup&page=1');
  await api.request('/digital/offers?page=1&service=topup');
  assert.equal(calls.length, 2);
  assert.equal(api.hasFreshRequest('/sales/products?'), true);
  assert.deepEqual(api.peekFreshRequest('/digital/offers?page=1&service=topup'), { data: [{ id: 2 }] });
  const parameters = { kind: 'agents', q: 'A & B', page: 2 };
  await api.accounts(parameters);
  assert.deepEqual(api.peekAccounts({ page: 2, q: 'A & B', kind: 'agents' }), { data: [{ id: 3 }] });
  assert.equal(api.peekAccounts({ ...parameters, page: 3 }), null);
  assert.equal(api.peekRequest('/digital/orders/id/receipt'), null);
  assert.deepEqual(writes, []);
});

test('memory freshness lasts 30 seconds, display hydration lasts at most 120 seconds, and refresh stays authoritative', async t => {
  let now = 0, calls = 0, finish;
  t.mock.method(Date, 'now', () => now);
  environment(t, async () => ++calls === 1 ? Response.json({ data: [1] }) : new Promise(resolve => { finish = () => resolve(Response.json({ data: [2] })); }));
  const api = createApiClient('/api/v1'), path = '/sales/products';
  await api.request(path);
  now = 29999; assert.equal(api.hasFreshRequest(path), true);
  now = 30000; assert.equal(api.hasFreshRequest(path), false); assert.equal(api.peekFreshRequest(path), null);
  assert.deepEqual(api.peekRequest(path), { data: [1] });
  const refreshed = api.request(path, { fresh: true }); await Promise.resolve();
  assert.equal(api.hasFreshRequest(path), false); assert.equal(api.peekFreshRequest(path), null);
  assert.deepEqual(api.peekRequest(path), { data: [1] });
  now = 120000; assert.equal(api.peekRequest(path), null); assert.equal(api.peekRequest(path, Infinity), null);
  finish(); await refreshed; assert.equal(api.hasFreshRequest(path), true);
});

test('read-only recovery polling preserves navigation data, real order lookup and every business write invalidate it', async t => {
  let calls = 0;
  environment(t, async () => { calls++; return Response.json({ data: [] }); });
  const api = createApiClient('/api/v1'), path = '/sales/products';
  await api.request(path);
  await api.request('/digital/orders/recovery?service=topup');
  assert.deepEqual(api.peekRequest(path), { data: [] });
  await api.request(path); assert.equal(calls, 2);
  await api.request('/digital/orders/order-id'); assert.equal(api.peekRequest(path), null);
  await api.request(path);
  await api.request('/catalog/preferences', { method: 'PUT', body: { hidden_columns: [] } });
  assert.equal(api.peekRequest(path), null);
});

test('forced summary refresh cannot be overwritten by an older request or its persisted snapshot', async t => {
  let finishOld, count = 0;
  const { data } = environment(t, async () => ++count === 1 ? new Promise(resolve => { finishOld = () => resolve(Response.json({ data: { cards: [{ value: 1 }] } })); }) : Response.json({ data: { cards: [{ value: 2 }] } }));
  const api = createApiClient('/api/v1'), path = '/dashboard/summary?currency=IQD'; api.setReadScope('pos:1');
  const old = api.request(path), rejected = assert.rejects(old, { name: 'AbortError' });
  await Promise.resolve(); await api.request(path, { fresh: true }); finishOld(); await rejected;
  assert.equal(api.peekRequest(path).data.cards[0].value, 2);
  assert.equal(JSON.parse(data.get('masal.dashboard.display.v1')).value.data.cards[0].value, 2);
});

test('old scoped requests and late forbidden errors cannot replay or clear the next session cache', async t => {
  let finishOld, calls = 0;
  environment(t, async () => ++calls === 1 ? new Promise(resolve => { finishOld = () => resolve(Response.json({}, { status: 403 })); }) : Response.json({ data: [{ id: 2 }] }));
  const api = createApiClient('/api/v1'), path = '/sales/products'; api.setReadScope('pos:1');
  const old = api.request(path), rejected = assert.rejects(old, { name: 'AbortError' });
  await Promise.resolve(); api.setReadScope('pos:2'); await api.request(path);
  finishOld(); await rejected;
  assert.equal(calls, 2); assert.deepEqual(api.peekRequest(path), { data: [{ id: 2 }] });
  api.clearReadCache(); assert.equal(api.peekRequest(path), null);
});

test('display snapshot respects requested age, query identity, scope and a subsequent revocation', async t => {
  let now = 0;
  t.mock.method(Date, 'now', () => now);
  environment(t, async () => Response.json({ data: { cards: [{ value: 7 }] } }));
  const path = '/dashboard/summary?currency=IQD&view=cards';
  const first = createApiClient('/api/v1'); first.setReadScope('pos:1'); await first.request(path);
  now = 40000;
  const reload = createApiClient('/api/v1'); reload.setReadScope('pos:1');
  assert.equal(reload.hasFreshRequest(path), false); assert.equal(reload.peekFreshRequest(path), null);
  assert.equal(reload.peekRequest(path, 30000), null);
  assert.equal(reload.peekRequest('/dashboard/summary?view=cards&currency=IQD').data.cards[0].value, 7);
  assert.equal(reload.peekRequest('/dashboard/summary?currency=USD&view=cards'), null);
  t.mock.method(globalThis, 'fetch', async () => Response.json({}, { status: 403 }));
  await assert.rejects(reload.request('/auth/me'), { status: 403 }); assert.equal(reload.peekRequest(path), null);
});
