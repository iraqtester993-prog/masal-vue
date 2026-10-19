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

test('display data survives revalidation while forced refresh subscribers wait for the new value', async () => {
  let now = 0, finish;
  const cache = createReadCache({ now: () => now, ttl: 30000 });
  await cache.read('a', async () => ({ revision: 1 }));
  const refreshed = cache.read('a', () => new Promise(resolve => { finish = resolve; }), { force: true });
  await Promise.resolve();
  assert.equal(cache.has('a'), false);
  assert.deepEqual(cache.peek('a', 120000), { revision: 1 });
  let joined = false;
  const ordinary = cache.read('a', async () => { throw new Error('Must join refresh'); }).then(value => { joined = true; return value; });
  await Promise.resolve(); assert.equal(joined, false);
  finish({ revision: 2 });
  assert.deepEqual(await refreshed, { revision: 2 });
  assert.deepEqual(await ordinary, { revision: 2 });
  assert.equal(cache.has('a'), true);
  now = 30000;
  let resolveExpired;
  const revalidated = cache.read('a', () => new Promise(resolve => { resolveExpired = resolve; }));
  await Promise.resolve();
  assert.equal(cache.has('a'), false);
  assert.deepEqual(cache.peek('a', 120000), { revision: 2 });
  now = 120000; assert.equal(cache.peek('a', 120000), null);
  resolveExpired({ revision: 3 }); await revalidated;
});

test('a newer explicit refresh aborts an older result instead of returning or caching it', async () => {
  const cache = createReadCache();
  let finishOld;
  const older = cache.read('a', () => new Promise(resolve => { finishOld = resolve; }));
  const rejected = assert.rejects(older, { name: 'AbortError' });
  await Promise.resolve();
  assert.deepEqual(await cache.read('a', async () => ({ revision: 2 }), { force: true }), { revision: 2 });
  finishOld({ revision: 1 }); await rejected;
  assert.deepEqual(cache.peek('a'), { revision: 2 });
});


test('a failed refresh preserves the last display but cannot treat it as fresh or suppress a retry',async()=>{
 const cache=createReadCache();await cache.read('wallet',async()=>({balance:94}));
 await assert.rejects(cache.read('wallet',async()=>{throw Error('offline');},{force:true}));
 assert.deepEqual(cache.peek('wallet',600000),{balance:94});assert.equal(cache.has('wallet'),false);
 assert.deepEqual(await cache.read('wallet',async()=>({balance:93})),{balance:93});
});

test('recently displayed navigation data survives eviction of a colder page',async()=>{
 const cache=createReadCache({limit:2});await cache.read('home',async()=>({value:1}));await cache.read('old-filter',async()=>({value:2}));
 assert.deepEqual(cache.peek('home'),{value:1});await cache.read('sales',async()=>({value:3}));
 assert.deepEqual(cache.peek('home'),{value:1});assert.equal(cache.peek('old-filter'),null);
});
