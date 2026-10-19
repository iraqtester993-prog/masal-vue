import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {computed,createSSRApp,h,onServerPrefetch,reactive,ref} from 'vue';
import {createRouter,createMemoryHistory,routeLocationKey} from 'vue-router';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';

test('a focused digital sale skips unrelated settings/catalog reads while the history page still loads its required context',async()=>{
 const source=await readFile(new URL('../src/modules/digital/DigitalPage.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 for(const focusedOffer of [null,{id:9,connection_id:2,provider:'topup'}]){
  const mounted=[],calls=[],runtime={identity:ref({account:{type:'pos'},membership:{kind:'owner'}}),pos:ref(true),can:()=>true,load:async(...args)=>calls.push(args)};
  const context={computed,ref,inject:()=>null,watch(){},routeLocationKey,defineProps:()=>({focusedOffer,mode:'digital'}),defineEmits:()=>()=>{},useDigitalRuntime:()=>runtime,digitalDeepLink:()=>({filters:{}}),authorizedDigitalTab:(_,__,fallback)=>fallback,onMounted:callback=>mounted.push(callback)};
  context.useRouteRefresh=()=>{};
  runInNewContext(script,context);for(const callback of mounted)await callback();
  assert.equal(calls.length,focusedOffer?0:1,'Selecting an existing offer must not fetch configuration, connections and the catalog again');
 }
});

test('loaded Topup allocation keeps four labeled balances and preserves its exact save payload after control regrouping',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'admin',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const page=(await server.ssrLoadModule('/src/modules/digital/TopupDistributionPage.vue')).default;
  const writes=[],data={can_assign:true,can_allocate:true,can_recover:true,target_id:2,connection_id:4,targets:[{id:2,name:'الوكيل الفعلي',operational:true}],connections:[{id:4,name:'Topup',available:'36000.00'}],grant:{version:7,balance:'64000.00',category_ids:[2,1],active:true},connection:{id:4,main_account_name:'الوكيل الفعلي',token_present:true,active:true,company_balance:'100000.00'},allocation:{connection_id:4,balance:'64000.00',available:'54000.00',held:'10000.00',spent:'6000.00'},categories:[{id:1,name:'فئة الحساب الفعلية',remote_id:'49',type:'topup',price:'6500.00',admin_price:'6000.00'},{id:2,name:'باقة الحساب الفعلية',remote_id:'50',type:'bundle',price:'7000.00',admin_price:'6500.00'}]};
  const session={state:reactive({identity:{account:{id:1,type:'system'},user:{id:9},membership:{kind:'owner'}}}),can:()=>true,api:{request:async path=>{assert.ok(path.startsWith('/topup/distribution?'));return{data};},mutate:async(...args)=>{writes.push(args);return{data:{}};}}};
  let controls;const loaded={...page,setup(props,context){controls=page.setup(props,context);onServerPrefetch(()=>controls.load());return controls;}};
  const router=createRouter({history:createMemoryHistory(),routes:[{path:'/topup/categories',component:{render:()=>null}}]});
  const app=createSSRApp({render:()=>h(loaded)});app.use(router);app.provide(portalContextKey,{session,portal:{id:'admin'}});
  const html=await renderToString(app);
  assert.ok(html.includes('topup-target-fields'));assert.ok(html.includes('topup-heading-actions'));assert.ok(html.includes('topup-balance-summary'));
  for(const amount of ['64,000','54,000','10,000','6,000'])assert.ok(html.includes(amount),amount);
  for(const label of ['حصة الوكيل','المتاح','المحجوز','المصروف:'])assert.match(html,new RegExp(`<dt\\b[^>]*>${label}</dt>`));
  assert.ok(html.includes('فئة الحساب الفعلية'));assert.ok(html.includes('حفظ الرصيد والفئات'));assert.equal(writes.length,0,'Reading and rendering never save');
  controls.allocationBalance.value='63000.00';await controls.save();
  assert.equal(writes.length,1);assert.equal(writes[0][0],'/topup/distribution/2');assert.equal(writes[0][1],'PUT');
  const payload=writes[0][2];assert.deepEqual(Array.from(payload.category_ids),[1,2]);assert.equal(payload.version,7);assert.equal(payload.connection_id,4);assert.equal(payload.allocation_balance,'63000.00');assert.equal(payload.active,true);assert.ok(payload.idempotency_key);
 }finally{await server.close();}
});

test('print exception cards keep reasons and decisions with the correct recipient and preserve review version/key',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'agents',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const page=(await server.ssrLoadModule('/src/modules/sales/SalesPage.vue')).default;
  const rows=[{id:1,sale_id:99,recipient_id:2,creator_id:8,version:3,account_name:'نقطة الفحص',recipient_name:'الوكيل',status:'pending',reason:'نفاد الورق',failure_reason:'توقف الطابعة',created_at:'2026-10-14T09:00:00Z'}, {id:2,sale_id:100,recipient_id:3,creator_id:8,version:1,account_name:'نقطة أخرى',recipient_name:'وكيل آخر',status:'pending',reason:'طلب لا يخص الحساب',created_at:'2026-10-14T09:00:00Z'}];
  const writes=[],session={state:reactive({identity:{account:{id:2,type:'main_agent'},user:{id:9},membership:{kind:'owner'}}}),can:key=>['sales.view','exceptions.view','exceptions.approve'].includes(key),refresh:async()=>null,api:{request:async path=>{assert.ok(path.startsWith('/sales/reprint-requests?'));return{data:rows,meta:{current_page:1,last_page:1,total:2}};},mutate:async(...args)=>{writes.push(args);return{data:{}};}}};
  let controls;const loaded={...page,setup(props,context){controls=page.setup(props,context);onServerPrefetch(()=>controls.load());return controls;}};
  const app=createSSRApp({render:()=>h(loaded,{mode:'exceptions'})});app.provide(portalContextKey,{session,portal:{id:'agents'}});app.provide(routeLocationKey,{name:'exceptions',query:{}});
  const html=await renderToString(app);
  assert.equal((html.match(/class="ops-request print-request-card"/g)||[]).length,2);assert.equal((html.match(/class="print-request-decision"/g)||[]).length,1,'Only the current recipient may see review actions');
  assert.ok(html.includes('نفاد الورق'));assert.ok(html.includes('توقف الطابعة'));assert.ok(html.includes('sales-filter-search'));assert.ok(html.includes('aria-label="من تاريخ"'));
  assert.match(html,/disabled[^>]*>رفض<\/button>/);assert.equal(writes.length,0);
  controls.reasons[1]='تعذر قراءة البطاقة';await controls.review(rows[0],'reject');
  assert.equal(writes.length,1);assert.equal(writes[0][0],'/sales/reprint-requests/1/review');assert.equal(writes[0][2].version,3);assert.equal(writes[0][2].decision,'reject');assert.equal(writes[0][2].reason,'تعذر قراءة البطاقة');assert.ok(writes[0][2].idempotency_key);
 }finally{await server.close();}
});
