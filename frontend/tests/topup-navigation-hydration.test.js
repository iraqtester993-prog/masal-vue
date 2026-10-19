import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp,h,onServerPrefetch,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createRouter,createMemoryHistory} from 'vue-router';
import {readCacheKey} from '../src/shared/api/read-policy.js';

async function fixture(run){
 const cacheDir=await mkdtemp(join(tmpdir(),'dananeer-topup-navigation-'));
 const server=await createServer({configFile:false,root:fileURLToPath(new URL('../',import.meta.url)),cacheDir,logLevel:'error',optimizeDeps:{noDiscovery:true,include:[]},plugins:[vue()],server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{await run(server,(await server.ssrLoadModule('/src/modules/auth/session.js')).portalContextKey);}finally{await server.close();await rm(cacheDir,{recursive:true,force:true});}
}
function context(cache,view={}){
 const views=new Map(Object.entries(view)),reads=[],session={state:reactive({identity:{user:{id:9},account:{id:1,type:'system'},membership:{kind:'owner'}}}),can:()=>true,refresh:async()=>null};
 session.api={peekRequest:path=>structuredClone(cache.get(readCacheKey(path))??null),peekViewState:key=>structuredClone(views.get(key)??null),viewStateScope:()=>1,rememberViewState(){},request(path,options){reads.push({path,options});throw Error('Test must provide an explicit read response');},mutate(){throw Error('Navigation cannot write');}};
 return {session,reads};
}
function appFor(page,portalContextKey,session){
 const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',component:{render:()=>null}},{path:'/digital',component:{render:()=>null}},{path:'/topup/categories',component:{render:()=>null}},{path:'/topup/allocation',component:{render:()=>null}}]});
 const app=createSSRApp({render:()=>h(page)});app.use(router);app.provide(portalContextKey,{session,portal:{id:'admin'},config:{}});return app;
}
test('Topup categories restore their exact search/page before first render and retain rows only for the same query during refresh',async()=>fixture(async(server,key)=>{
 const component=(await server.ssrLoadModule('/src/modules/digital/TopupCategoriesPage.vue')).default;
 const cache=new Map([[readCacheKey('/topup/categories?page=2&q=bundle'),{data:[{id:7,name:'Remembered bundle'}],meta:{current_page:2,last_page:3,total:30}}]]),{session,reads}=context(cache,{'topup-categories':{q:'bundle',page:2}});
 let finish;session.api.request=(path,options)=>{reads.push({path,options});return new Promise(resolve=>{finish=resolve;});};
 const inspected={...component,setup(props,ctx){const controls=component.setup(props,ctx);assert.equal(controls.q.value,'bundle');assert.equal(controls.meta.value.current_page,2);assert.equal(controls.rows.value[0].name,'Remembered bundle');
  onServerPrefetch(async()=>{
   const refresh=controls.load(2,{fresh:true});assert.equal(controls.rows.value[0].id,7,'a refresh keeps the current list visible');assert.equal(reads.at(-1).options.fresh,true);
   finish({data:[{id:8,name:'Current bundle'}],meta:{current_page:2,last_page:3,total:31}});await refresh;
   controls.q.value='new filter';const changed=controls.load();assert.equal(controls.rows.value.length,0,'another search cannot display the previous search result');
   finish({data:[],meta:{current_page:1,last_page:1,total:0}});await changed;
  });return controls;}};
 const html=await renderToString(appFor(inspected,key,session));assert.ok(!html.includes('Remembered bundle'));assert.match(html,/href="\/digital"/);assert.match(html,/href="\/topup\/allocation"/);
}));

test('Topup allocation hydrates an exact target while every balance refresh stays live and switching target removes the previous balance immediately',async()=>fixture(async(server,key)=>{
 const component=(await server.ssrLoadModule('/src/modules/digital/TopupDistributionPage.vue')).default;
 const data={target_id:2,connection_id:4,targets:[{id:2,name:'Selected agent',operational:true}],connections:[{id:4,name:'Topup'}],grant:{version:7,balance:'64000.00',category_ids:[1],active:true},connection:{id:4,token_present:true,active:true,company_balance:'100000.00'},allocation:{connection_id:4,balance:'64000.00',available:'54000.00',held:'10000.00',spent:'6000.00'},categories:[{id:1,name:'Saved category',remote_id:'49',type:'topup',price:'6500.00',admin_price:'6000.00'}]};
 const cache=new Map([[readCacheKey('/topup/distribution?compact_targets=1&target_account_id=2&connection_id=4'),{data}]]),{session,reads}=context(cache,{'topup-distribution':{target:'2',connection:'4',query:'Saved',type:'topup',page:1}});
 let finish,fail,writes=0;session.api.request=(path,options)=>{reads.push({path,options});return new Promise((resolve,reject)=>{finish=resolve;fail=reject;});};session.api.mutate=async()=>{writes++;};
 const inspected={...component,setup(props,ctx){const controls=component.setup(props,ctx);assert.equal(controls.data.value.allocation.available,'54000.00');assert.equal(controls.query.value,'Saved');assert.equal(controls.readReady.value,false,'remembered data is read only until the current balance is revalidated');
  onServerPrefetch(async()=>{
   const refresh=controls.load();assert.equal(controls.data.value.allocation.available,'54000.00');assert.equal(reads.at(-1).options.fresh,true,'balance requests bypass the normal navigation TTL');await controls.save();await controls.savePrices();assert.equal(writes,0);
   finish({data:{...data,allocation:{...data.allocation,available:'53000.00'}}});await refresh;assert.equal(controls.data.value.allocation.available,'53000.00');assert.equal(controls.query.value,'Saved');assert.equal(controls.readReady.value,true);
   const failedRefresh=controls.load();fail(Error('offline'));await failedRefresh;assert.equal(controls.data.value.allocation.available,'54000.00','cached balance stays visible after a failed read');assert.equal(controls.readReady.value,false);await controls.save();await controls.savePrices();assert.equal(writes,0,'a failed refresh cannot authorize a financial write from the display snapshot');
   controls.target.value='3';controls.connectionId.value='';const changed=controls.load('3');assert.equal(controls.data.value,null,'a new agent label must never retain the prior agent balance');
   fail(Error('offline'));await changed;assert.equal(controls.data.value,null);assert.ok(controls.error.value);
  });return controls;}};
 await renderToString(appFor(inspected,key,session));
}));

test('the digital runtime displays remembered connection metadata immediately but revalidates live balances and clears an unmatched page',async()=>fixture(async(server,key)=>{
 const {useDigitalRuntime}=await server.ssrLoadModule('/src/modules/digital/digital-runtime.js');
 const cache=new Map([[readCacheKey('/digital/options?compact=1'),{data:{accounts:[],products:[],providers:[],gateway:{}}}],[readCacheKey('/digital/connections?page=1&per_page=25'),{data:[{id:4,company_balance:'94.00'}],meta:{current_page:1,last_page:2,total:30}}]]),{session,reads}=context(cache);
 let finish;session.api.request=(path,options)=>{reads.push({path,options});return path.startsWith('/digital/connections?')?new Promise(resolve=>{finish=resolve;}):Promise.resolve(cache.get(readCacheKey(path)));};
 const wrapper={setup(){const vm=useDigitalRuntime();assert.equal(vm.state.connections[0].company_balance,'94.00');
  onServerPrefetch(async()=>{
   const refresh=vm.load();assert.equal(vm.state.connections[0].company_balance,'94.00');assert.equal(reads.find(row=>row.path.startsWith('/digital/connections?')).options.fresh,true);
   finish({data:[{id:4,company_balance:'92.00'}],meta:{current_page:1,last_page:2,total:30}});await refresh;assert.equal(vm.state.connections[0].company_balance,'92.00');
   const next=vm.load(2);assert.equal(vm.state.connections.length,0);finish({data:[],meta:{current_page:2,last_page:2,total:30}});await next;
  });return()=>h('span');}};
 await renderToString(appFor(wrapper,key,session));
}));

test('wallet summaries remain visible for the same scope during a live refresh and are cleared before showing a different account filter',async()=>fixture(async(server,key)=>{
 const component=(await server.ssrLoadModule('/src/modules/finance/WalletsPage.vue')).default;
 const summary={balance:'100.00',available:'90.00',held:'10.00',children:'0.00'},cache=new Map([[readCacheKey('/finance/options?compact=1'),{data:{services:[{id:'voucher',name:'البطاقات'}],currencies:['IQD'],accounts:[{id:1,type:'system',name:'System'}],own_account_id:1}}],[readCacheKey('/finance/wallets?service=voucher&currency=IQD&per_page=1&summary=1'),{summary,data:[],meta:{last_page:1}}]]),{session,reads}=context(cache);
 let finish;session.api.request=(path,options)=>{reads.push({path,options});return new Promise(resolve=>{finish=resolve;});};
 const inspected={...component,setup(props,ctx){const controls=component.setup(props,ctx);assert.equal(controls.vm.options.wallet_summary.available,'90.00','remembered values are available before mounting');
  onServerPrefetch(async()=>{
   const refresh=controls.loadStockMetrics();assert.equal(controls.totals.value.available,'90.00');assert.equal(reads.at(-1).options.fresh,true);
   finish({summary:{...summary,available:'80.00'},data:[],meta:{last_page:1}});await refresh;assert.equal(controls.totals.value.available,'80.00');
   controls.filters.account='3';const changed=controls.loadStockMetrics();assert.equal(controls.totals.value.available,null,'changing scope cannot borrow the previous account balance');
   finish({summary:{...summary,available:'0.00'},data:[],meta:{last_page:1}});await changed;assert.equal(controls.totals.value.available,'0.00','a real zero balance stays visible');
  });return controls;}};
 await renderToString(appFor(inspected,key,session));
}));
