import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { computed, createSSRApp, h, onServerPrefetch, provide, reactive, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';

test('the actual wallet and price pages render using the portal context for system, agent employee and POS identities', async () => {
  const server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)),
    mode: 'admin',
    logLevel: 'error',
    server: { middlewareMode: true, hmr: false },
    appType: 'custom',
  });
  try {
    const { portalContextKey } = await server.ssrLoadModule('/src/modules/auth/session.js');
    const wallets = (await server.ssrLoadModule('/src/modules/finance/WalletsPage.vue')).default;
    const prices = (await server.ssrLoadModule('/src/modules/finance/PricesPage.vue')).default;
    const { useFinanceRuntime } = await server.ssrLoadModule('/src/modules/finance/finance-runtime.js');
    const { financeKey, emptyFilters } = await server.ssrLoadModule('/src/modules/finance/finance-model.js');
    const permissions = ['wallets.view', 'wallets.bulk', 'wallets.transfer', 'ledger.view', 'invoices.view', 'prices.view', 'prices.propose'];
    for (const [type, kind] of [['system', 'owner'], ['main_agent', 'employee'], ['pos', 'owner']]) {
      const requests = [];
      const session = {
        state: reactive({ identity: { account: { id: 12, type, name: 'الحساب الفعلي' }, membership: { kind }, permissions } }),
        can: (permission) => permissions.includes(permission),
        api: { request: (path) => { requests.push(path); throw new Error('SSR must not fabricate an API response'); }, mutate: () => { throw new Error('Rendering must not write'); } },
        refresh: async () => null,
      };
      for (const [page, label] of [[wallets, 'الأرصدة والحركات'], [prices, 'سجل تغييرات الأسعار']]) {
        const app = createSSRApp({ render: () => h(page) });
        app.provide(portalContextKey, { session, portal: { id: type === 'system' ? 'admin' : type === 'pos' ? 'pos' : 'agents' }, config: {} });
        const html = await renderToString(app);
        assert.ok(html.includes(label), `${type}/${kind} must render ${label}`);
        assert.ok(!html.includes('undefined'));
      }
      assert.deepEqual(requests, []);
    }

    const permissionsForChildren = ['wallets.view', 'wallets.request', 'wallets.approve', 'wallets.transfer', 'wallets.deposit', 'wallets.reverse', 'wallets.bulk', 'invoices.view', 'invoices.create', 'invoices.settle', 'security.fundingRequests', 'security.fundingRecovery'];
    const allAccounts = [
      { id: 12, name: 'مدير', type: 'system', parent_id: null, status: 'active' },
      { id: 13, name: 'رئيسي', type: 'main_agent', parent_id: 12, status: 'active' },
      { id: 14, name: 'فرعي', type: 'sub_agent', parent_id: 13, status: 'active' },
      { id: 15, name: 'فرع فرعي', type: 'sub_branch', parent_id: 14, status: 'active' },
      { id: 16, name: 'نقطة', type: 'pos', parent_id: 15, status: 'active' },
    ];
    for (const type of ['system', 'main_agent', 'pos']) {
      const actor = allAccounts.find((account) => account.type === type);
      const accounts = allAccounts.slice(allAccounts.indexOf(actor));
      const session = {
        state: reactive({ identity: { account: actor, membership: { kind: 'owner' }, permissions: permissionsForChildren } }),
        can: (permission) => permissionsForChildren.includes(permission), refresh: async () => null,
        api: { request: async (path) => {
          if (path === '/finance/options?compact=1') return { data: { accounts, own_account_id: actor.id, parent_account_id: actor.parent_id, services: [{ id: 'voucher', name: 'البطاقات' }], currencies: ['IQD', 'USD'], funding_policy: { version: 1, daily_limit: 3, amounts: ['25000.00'], recovery_hours: 24 }, counts: { today_requests: 0, pending_incoming: 0, transfer_count: 0 } } };
          if (path.startsWith('/finance/wallets?')) return { data: [], meta: { last_page: 1 } };
          throw new Error(`Unexpected render request: ${path}`);
        }, mutate: () => { throw new Error('A render must not write'); } },
      };
      for (const [file, label] of [['SimpleWallets', 'سجل التمويل'], ['WalletBulk', 'معاينة المجموعة'], ['WalletRecovery', 'سجل استرجاع الرصيد'], ['WalletInvoices', 'حالة الفاتورة']]) {
        const component = (await server.ssrLoadModule(`/src/modules/finance/${file}.vue`)).default;
        const wrapper = {
          setup() {
            const vm = useFinanceRuntime();
            const service = ref('voucher'), currency = ref('IQD'), filters = reactive(emptyFilters());
            provide(financeKey, { ...vm, service, currency, filters, accounts: computed(() => vm.options.accounts), showFilters: ref(false), filtersActive: ref(false), emptyScope: () => false, recordParameters: (extra) => ({ service: service.value, currency: currency.value, ...extra }), refresh: vm.loadOptions });
            onServerPrefetch(() => vm.loadOptions());
            return () => h(component);
          },
        };
        const app = createSSRApp(wrapper); app.provide(portalContextKey, { session, portal: {}, config: {} });
        const html = await renderToString(app);
        assert.ok(html.includes(label), `${type}: ${file} must render after real runtime options loading`);
        assert.ok(!html.includes('undefined'));
        if (file === 'WalletRecovery' && type === 'main_agent') assert.ok(html.includes('الحساب للاسترجاع'), 'recovery must offer a paged descendant account picker');
        if (file === 'WalletRecovery' && type === 'system') assert.ok(!html.includes('آخر موعد:'), 'system must not offer unsupported money recovery');
      }
    }
    const requests=[];
    const session={state:reactive({identity:{account:{id:1,type:'system'},membership:{kind:'owner'}}}),can:()=>true,refresh:async()=>null,api:{request:async path=>{
      requests.push(path);
      if(path==='/finance/options?compact=1')return{data:{accounts:Array.from({length:10000},(_,i)=>({id:i+1,name:String(i+1),type:i?'pos':'system',parent_id:1,status:'active'})),services:[],currencies:['IQD'],own_account_id:1}};
      if(path.startsWith('/finance/wallets?'))return{data:[{account_id:9000,service:'cash',currency:'IQD',available:'25.00'}],meta:{last_page:1}};
      throw Error(path);
    }}};
    let runtime;
    const app=createSSRApp({setup(){runtime=useFinanceRuntime();onServerPrefetch(async()=>{await runtime.loadOptions();assert.equal(requests.length,1,'options must not download network wallet balances');assert.equal(runtime.walletAmount(9000,'cash','IQD'),null,'unloaded balance must not become zero');await runtime.loadWallets(undefined,[9000]);});return()=>h('span');}});
    app.provide(portalContextKey,{session,portal:{},config:{}});await renderToString(app);
    assert.equal(requests.length,2);assert.ok(requests[1].includes('account_ids%5B%5D=9000'));assert.ok(requests[1].includes('metrics=0'));
    assert.equal(runtime.walletAmount(9000,'cash','IQD'),'25.00');assert.equal(runtime.walletAmount(9999,'cash','IQD'),null);
  } finally { await server.close(); }
});
