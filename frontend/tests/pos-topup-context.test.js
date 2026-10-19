import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,onServerPrefetch,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createMemoryHistory,createRouter} from 'vue-router';
import {topupCatalogRoute,topupHistoryRoute,topupKind,topupWalletRoute} from '../src/modules/digital/pos-topup-context.js';

test('Topup actions carry one explicit service context and use only known category kinds',()=>{
 assert.deepEqual(topupCatalogRoute(),{name:'sell',query:{service:'topup'}});
 for(const type of ['topup','bundle','bill'])assert.equal(topupCatalogRoute({type}).query.type,type);
 for(const type of ['api:6','unknown',['bundle'],null]){assert.equal(topupKind(type),'');assert.equal(topupCatalogRoute({type}).query.type,undefined);}
 assert.deepEqual(topupCatalogRoute({type:'bundle',q:' باقة  '}),{name:'sell',query:{service:'topup',type:'bundle',q:'باقة'}});
 assert.deepEqual(topupWalletRoute({funding:true}),{name:'wallets',query:{service:'topup',quick:'funding'}});
 assert.deepEqual(topupHistoryRoute(),{name:'sales',query:{service:'topup'}});
});

async function fixture(run){const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});try{return await run(server);}finally{await server.close();}}
const point={id:31,type:'pos',name:'نقطة الاختبار',status:'active'};
const options={own_account_id:31,parent_account_id:30,parent_account_name:'وكيل',topup_funder_account_id:30,topup_funder_account_name:'وكيل Topup',accounts:[point],services:[{id:'voucher',name:'البطاقات'},{id:'topup',name:'Topup'}],currencies:['IQD'],funding_policy:{daily_limit:5,amounts:['25000.00']},counts:{}};
function session(permissions,peek){return{state:reactive({identity:{user:{id:19,name:'مستخدم'},account:point,membership:{kind:'owner'},permissions}}),can:key=>permissions.includes(key),refresh:async()=>assert.fail('Render cannot refresh identity'),api:{peekRequest:peek,request:()=>assert.fail('SSR cannot request data'),mutate:()=>assert.fail('UI context cannot write money')}};}
async function application(server,Component,session,path,props={}){const{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');const router=createRouter({history:createMemoryHistory(),routes:[{path:'/sell',name:'sell',component:Component},{path:'/wallets',name:'wallets',component:Component},{path:'/sales',name:'sales',component:Component}]});await router.push(path);const app=createSSRApp({render:()=>h(Component,props)});app.use(router);app.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});return app;}

test('the real seller opens the requested Topup bundle filter without showing recharge, stock or another provider',()=>fixture(async server=>{
 const Sell=(await server.ssrLoadModule('/src/modules/sales/SellPage.vue')).default;
 const base={connection_id:4,provider:'topup',retail:'6000.00',version:1,wallet_available:'94000.00',purchasable:true,availability_reason:'available',gateway:{configured:true,purchases_enabled:true},product_provider_id:2,product_provider_name:'آسياسيل'};
 const offers=[{...base,id:1,product_id:11,name:'منتج تعبئة فعلي',type:'topup'},{...base,id:2,product_id:12,name:'باقة تجريبية للواجهة',type:'bundle'},{...base,id:3,product_id:13,provider:'rabiaa',provider_id:8,company_name:'الرابعة',name:'بطاقة شركة أخرى',type:'voucher'}];
 const actor=session(['digital.view'],path=>path==='/digital/offers?'?{data:offers}:null);
 const app=await application(server,Sell,actor,'/sell?service=topup&type=bundle');const html=await renderToString(app);
 assert.ok(html.includes('باقة تجريبية للواجهة'));assert.ok(!html.includes('منتج تعبئة فعلي'));assert.ok(!html.includes('بطاقة شركة أخرى'));assert.ok(html.includes('نوع خدمة Topup'));
 assert.match(html,/<button\b(?=[^>]*class="pos-sale-category-select")(?=[^>]*disabled)[^>]*>/,'read permission never enables a purchase');
 assert.ok(!html.includes('البطاقات والخدمات'));
}));

test('wallet Topup funding retains its service and presents legacy money separately from available funded money',()=>fixture(async server=>{
 const Page=(await server.ssrLoadModule('/src/modules/finance/WalletsPage.vue')).default;
 const legacy={service:'topup',amount:'100000.00',held:'0.00',own_amount:'100000.00',status:'unreconciled'};
 const actor=session(['wallets.view','wallets.request'],path=>path==='/finance/options?compact=1'?{data:options}:path.startsWith('/finance/wallets?service=topup&')?{data:[],summary:{own_balance:'100000.00',own_available:'94000.00',own_held:'6000.00'},legacy_topup_summary:legacy}:null);
 const inspected={...Page,setup(props,ctx){const c=Page.setup(props,ctx);assert.equal(c.service.value,'topup');c.quickFunding();assert.equal(c.tab.value,'fundingRequests');c.tab.value='balances';return c;}};
 const html=await renderToString(await application(server,inspected,actor,'/wallets?service=topup&quick=funding'));
 assert.ok(html.includes('94,000'));assert.ok(html.includes('رصيد سابق قيد المطابقة'));assert.match(html,/<dl class="wallet-legacy-topup-summary">[\s\S]*?100,000[\s\S]*?<\/dl>/);
 assert.ok(!html.includes('194,000'));assert.ok(!html.includes('200,000'));assert.ok(html.includes('طلب تمويل Topup'));
}));

test('POS Topup wallet actions link to the same seller and ten-row history instead of loading an unrelated connection list',()=>fixture(async server=>{
 const Wallet=(await server.ssrLoadModule('/src/modules/finance/TopupWallet.vue')).default,{financeKey}=await server.ssrLoadModule('/src/modules/finance/finance-model.js');
 const actor=session(['digital.view'],()=>null),finance={identity:ref(actor.state.identity),options:{accounts:[],wallet_summary:null,topup_summary:null},admin:ref(false),ownVisible:ref(true),filtersActive:ref(false),filters:{},can:actor.can};
 const inspected={...Wallet,setup(props,ctx){const c=Wallet.setup(props,ctx);onServerPrefetch(()=>c.load());return c;}};
 const app=await application(server,inspected,actor,'/wallets?service=topup');app.provide(financeKey,finance);const html=await renderToString(app);
 for(const href of ['/sell?service=topup&amp;type=topup','/sell?service=topup&amp;type=bundle','/sales?service=topup'])assert.ok(html.includes(href),href);
 assert.ok(!html.includes('class="topup-wallet-balances"'));assert.ok(!html.includes('سجل الخدمات الإلكترونية'));
}));

test('a Topup history deep link overrides an unrelated saved history filter and requests ten Topup operations',()=>fixture(async server=>{
 const History=(await server.ssrLoadModule('/src/modules/digital/POSHistory.vue')).default,calls=[];
 const actor=session(['digital.view'],()=>null);actor.api.peekViewState=()=>({filters:{kind:'card'}});actor.api.request=async(path)=>{calls.push(path);return{data:[],meta:{pagination:'cursor',per_page:10,has_more:false,next_cursor:null}};};
 const inspected={...History,setup(props,ctx){const c=History.setup(props,ctx);assert.equal(c.filters.kind,'topup');onServerPrefetch(()=>c.refresh());return c;}};
 await renderToString(await application(server,inspected,actor,'/sales?service=topup',{sales:{identity:ref(actor.state.identity),state:{busy:false},can:actor.can}}));
 assert.equal(calls.length,1);const query=new URL(calls[0],'http://local').searchParams;assert.equal(query.get('kind'),'topup');assert.equal(query.get('per_page'),'10');assert.equal(query.get('pagination'),'cursor');assert.equal(query.get('cursor'),null);
}));
