import test from 'node:test';
import assert from 'node:assert/strict';
import { createReadCache } from '../src/shared/api/read-cache.js';

test('navigation shares reads, returns independent data, and expires within 15 seconds', async () => {
  let now = 0, calls = 0;
  const cache = createReadCache({ now: () => now });
  const load = async () => ({ balance: ++calls });
  const [first, second] = await Promise.all([cache.read('a', load), cache.read('a', load)]);
  assert.equal(calls, 1); first.balance = 900;
  assert.equal(second.balance, 1);
  assert.equal((await cache.read('a', load)).balance, 1);
  now = 15000;
  assert.equal((await cache.read('a', load)).balance, 2);
  assert.equal((await cache.read('a', load, { force: true })).balance, 3);
});

test('a write invalidates pending reads so old balances cannot enter the cache', async () => {
  const cache = createReadCache();
  let finish, calls = 0;
  const load = () => ++calls === 1 ? new Promise(resolve => { finish = resolve; }) : Promise.resolve({ balance: 40 });
  const result = cache.read('balance', load);
  await Promise.resolve(); cache.clear(); finish({ balance: 100 });
  assert.equal((await result).balance, 40);
  assert.equal(cache.peek('balance').balance, 40);
  cache.clear(); assert.equal(cache.peek('balance'), null);
});

test('leaving one page does not abort another subscriber and failures are never cached', async () => {
  const cache = createReadCache();
  let finish;
  const load = () => new Promise(resolve => { finish = resolve; });
  const controller = new AbortController();
  const leaving = cache.read('a', load, { signal: controller.signal });
  const staying = cache.read('a', load);
  await Promise.resolve(); controller.abort(); finish({ balance: 7 });
  await assert.rejects(leaving, { name: 'AbortError' });
  assert.equal((await staying).balance, 7);
  await assert.rejects(cache.read('bad', async () => { throw new Error('offline'); }));
  assert.equal(cache.peek('bad'), null);
});
