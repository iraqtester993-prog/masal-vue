import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp, h, onServerPrefetch, reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createApiClient} from '../src/shared/api/client.js';
import {allPages, createFinanceApi} from '../src/modules/finance/finance-api.js';

async function withRuntime(api, verify) {
  const server = await createServer({root: fileURLToPath(new URL('../', import.meta.url)), mode: 'admin', logLevel: 'error', server: {middlewareMode: true, hmr: false, ws: false}, appType: 'custom'});
  try {
    const {portalContextKey} = await server.ssrLoadModule('/src/modules/auth/session.js');
    const {useFinanceRuntime} = await server.ssrLoadModule('/src/modules/finance/finance-runtime.js');
    const session = {api, state: reactive({identity: {account: {id: 14, type: 'main_agent'}, membership: {kind: 'owner'}}}), can: permission => permission === 'wallets.view', refresh: async () => null};
    const app = createSSRApp({setup() {
      const runtime = useFinanceRuntime();
      onServerPrefetch(() => verify(runtime));
      return () => h('span');
    }});
    app.provide(portalContextKey, {session, portal: {id: 'agents'}, config: {}});
    await renderToString(app);
  } finally {await server.close();}
}

function page(available) {
  return {data: [{account_id: 14, service: 'voucher', currency: 'IQD', available}], meta: {last_page: 1}};
}

test('fresh funding balance bypasses the actual client cache before 15 seconds while other reads stay cached', async t => {
  let clock = 1791930000000, balance = '1042000.00';
  const calls = [];
  t.mock.method(Date, 'now', () => clock);
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    assert.equal(options.method, 'GET', 'checking a preview must not write');
    calls.push(url);
    return Response.json(url.includes('/finance/wallets?') ? page(balance) : {data: {value: 'unchanged'}});
  });
  const client = createApiClient('/api/v1'), api = createFinanceApi(client);
  await api.options();
  await client.request('/catalog/options');
  await withRuntime(client, async runtime => {
    assert.equal(await runtime.loadWallets(undefined, [14]), true);
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), '1042000.00');
    balance = '900.00';
    clock += 14999;
    assert.equal(await runtime.loadWallets(undefined, [14]), true);
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), '1042000.00', 'ordinary navigation reads must retain their cache');
    assert.equal(calls.filter(url => url.includes('/finance/wallets?')).length, 1);
    const authoritative = await runtime.loadWallets(undefined, [14], {fresh: true});
    assert.equal(authoritative[0].available, '900.00');
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), '900.00', 'the preview must see the server balance even inside the cache TTL');
    runtime.wallets.value[0].available = '1.00';
    assert.equal(authoritative[0].available, '900.00', 'the preview snapshot must remain independent of mutable UI state');
    assert.equal(await runtime.loadWallets(undefined, [14]), true);
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), '900.00');
  });
  await api.options();
  await client.request('/catalog/options');
  const walletCalls = calls.filter(url => url.includes('/finance/wallets?'));
  assert.equal(walletCalls.length, 2);
  assert.equal(walletCalls[0], walletCalls[1], 'fresh reads must not add a cachebuster or change the query');
  assert.equal(calls.filter(url => url.includes('/finance/options?')).length, 1);
  assert.equal(calls.filter(url => url.includes('/catalog/options')).length, 1);
});

test('a failed or aborted authoritative read cannot be treated as a successful funding preview', async t => {
  let failed = false;
  t.mock.method(globalThis, 'fetch', async (_url, options) => {
    assert.equal(options.method, 'GET');
    return failed ? Response.json({message: 'Unavailable'}, {status: 500}) : Response.json(page('1000.00'));
  });
  await withRuntime(createApiClient('/api/v1'), async runtime => {
    assert.equal(await runtime.loadWallets(undefined, [14]), true);
    const controller = new AbortController();
    controller.abort();
    assert.equal(await runtime.loadWallets(controller.signal, [14], {fresh: true}), false);
    failed = true;
    assert.equal(await runtime.loadWallets(undefined, [14], {fresh: true}), false);
    assert.ok(runtime.balancesError.value);
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), null, 'a failed read must not fall back to the cached balance');
  });
});

test('an older in-flight wallet read cannot overwrite the authoritative balance after it arrives', async t => {
  let releaseOld, beginOld, calls = 0;
  const oldStarted = new Promise(resolve => {beginOld = resolve;});
  t.mock.method(globalThis, 'fetch', async (_url, options) => {
    assert.equal(options.method, 'GET');
    if (++calls === 1) {
      beginOld();
      return new Promise(resolve => {releaseOld = () => resolve(Response.json(page('1000.00')));});
    }
    return Response.json(page('500.00'));
  });
  await withRuntime(createApiClient('/api/v1'), async runtime => {
    const oldRead = runtime.loadWallets(undefined, [14]);
    await oldStarted;
    assert.equal((await runtime.loadWallets(undefined, [14], {fresh: true}))[0].available, '500.00');
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), '500.00');
    releaseOld();
    assert.equal(await oldRead, false);
    assert.equal(runtime.walletAmount(14, 'voucher', 'IQD'), '500.00');
    assert.equal(calls, 2);
  });
});

test('fresh balance reads bypass the cache on every page and preserve the caller abort signal', async t => {
  let available = '1000.00';
  const calls = [];
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    assert.equal(options.method, 'GET');
    calls.push(url);
    const number = Number(new URL(url, 'https://example.test').searchParams.get('page'));
    const rows = number === 1 ? [{account_id: 14, service: 'voucher', currency: 'IQD', available}, ...Array.from({length: 99}, (_, index) => ({account_id: 14, service: `api:${index + 1}`, currency: 'IQD', available: '0.00'}))] : [{account_id: 14, service: 'cash', currency: 'IQD', available}];
    return Response.json({data: rows, meta: {last_page: 2, total: 101, per_page: 100}});
  });
  const api = createFinanceApi(createApiClient('/api/v1')), controller = new AbortController(), parameters = {account_ids: [14], metrics: 0};
  const first = await allPages(api, 'wallets', parameters, controller.signal);
  available = '200.00';
  const cached = await allPages(api, 'wallets', parameters, controller.signal);
  assert.equal(first.length, 101);
  assert.deepEqual(cached, first);
  assert.equal(calls.length, 2);
  const fresh = await allPages(api, 'wallets', parameters, controller.signal, {fresh: true});
  assert.equal(fresh[0].available, '200.00');
  assert.equal(fresh.at(-1).available, '200.00');
  assert.equal(calls.length, 4);
  assert.deepEqual(calls.slice(0, 2), calls.slice(2));
  controller.abort();
  await assert.rejects(allPages(api, 'wallets', parameters, controller.signal, {fresh: true}), error => error.name === 'AbortError');
  assert.equal(calls.length, 4);
});
