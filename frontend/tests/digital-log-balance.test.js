import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {computed,effectScope,reactive,ref,watch} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {createDigitalApi} from '../src/modules/digital/digital-api.js';

const page=data=>({data,meta:{current_page:1,last_page:1,total:data.length}});
const totals=quantity=>({data:{quantity,cost:'0.00',retail:'0.00'}});
const funds=(id,amount)=>({balance:{scope:'account_available',account_id:id,total:1,known:1,amount,updated_at:null}});
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};

async function controls({snapshots=new Map(),requests=[],filters={provider:'topup',main_account_id:11}}={}){
 const timers=new Map(),reads=[];
 const client={peekRequest:path=>snapshots.get(path)??null,request:(path,options)=>{const pending=deferred();reads.push({path,options,pending});return pending.promise;}};
 const vm={api:createDigitalApi(client),pos:ref(false),identity:ref({account:{type:'system'},membership:{kind:'owner'}}),can:()=>true,state:reactive({options:{accounts:[]},connections:[],error:''}),failure:async error=>error.message};
 const source=await readFile(new URL('../src/modules/digital/DigitalLog.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const scope=effectScope(),context={computed,reactive,ref,watch,AbortController,defineProps:()=>({vm,routeFilters:filters}),defineExpose(){},useAccountLookup:()=>({}),onMounted(){},onBeforeUnmount(){},setTimeout:callback=>{const key=Symbol();timers.set(key,callback);return key;},clearTimeout:key=>timers.delete(key)};
 context.useRouteRefresh=()=>{};
 scope.run(()=>runInNewContext(script+'\nglobalThis.controls={filters,rows,summary,balance,meta,loading,load};',context));
 return {...context.controls,vm,reads,timers,dispose:()=>scope.stop()};
}

test('log restores exact-query cached rows and totals immediately and retains them throughout a fresh refresh',async()=>{
 const snapshots=new Map([
  ['/digital/orders?provider=topup&main_account_id=11&page=1',page([{id:81,product_name:'saved'}])],
  ['/digital/orders/summary?provider=topup&main_account_id=11',totals(1)],
  ['/digital/connections?provider=topup&main_account_id=11&per_page=1',funds(11,'12500.67')],
 ]);
 const view=await controls({snapshots});
 try{
  assert.equal(view.rows.value[0].id,81);assert.equal(view.summary.value.quantity,1);assert.equal(view.balance.value.amount,'12500.67');
  const pending=view.load(1,{fresh:true});
  assert.equal(view.rows.value[0].id,81);assert.equal(view.summary.value.quantity,1);assert.equal(view.balance.value.amount,'12500.67');
  for(const read of view.reads)assert.equal(read.options.fresh,true,'manual refresh must bypass the memory freshness window');
  view.reads.find(read=>read.path.includes('/orders?')).pending.resolve(page([{id:82}]));
  view.reads.find(read=>read.path.includes('/summary?')).pending.resolve(totals(2));
  view.reads.find(read=>read.path.includes('/connections?')).pending.resolve(funds(11,'12400.42'));
  await pending;assert.equal(view.rows.value[0].id,82);assert.equal(view.balance.value.amount,'12400.42');assert.equal(view.summary.value.quantity,2);
 }finally{view.dispose();}
});

test('changing agent cancels immediately and late old responses cannot populate the new agent during debounce or after it',async()=>{
 const view=await controls();
 try{
  const first=view.load();const older=[...view.reads];
  view.filters.main_account_id=22;
  assert.ok(older.every(read=>read.options.signal.aborted),'selection must cancel without waiting for debounce');
  assert.equal(view.balance.value,null);assert.equal(view.summary.value,null);
  older.find(read=>read.path.includes('/orders?')).pending.resolve(page([{id:11}]));
  older.find(read=>read.path.includes('/summary?')).pending.resolve(totals(11));
  older.find(read=>read.path.includes('/connections?')).pending.resolve(funds(11,'12500.67'));
  await first;
  assert.equal(view.rows.value.length,0);assert.equal(view.balance.value,null);assert.equal(view.summary.value,null);
  const callback=[...view.timers.values()][0];view.timers.clear();const next=callback();
  const latest=view.reads.slice(3);
  assert.ok(latest.every(read=>read.path.includes('main_account_id=22')));
  assert.equal(latest.find(read=>read.path.includes('/connections?')).options.fresh,true,'agent balance always revalidates against the server');
  latest.find(read=>read.path.includes('/orders?')).pending.resolve(page([{id:22}]));
  latest.find(read=>read.path.includes('/summary?')).pending.resolve(totals(22));
  latest.find(read=>read.path.includes('/connections?')).pending.resolve(funds(22,'0.00'));
  await next;assert.equal(view.rows.value[0].id,22);assert.equal(view.balance.value.account_id,22);assert.equal(view.balance.value.amount,'0.00');
 }finally{view.dispose();}
});

test('a new agent cannot retain previous totals on failure and display snapshots match the complete filter',async()=>{
 const snapshots=new Map([
  ['/digital/orders?provider=topup&main_account_id=11&page=1',page([{id:11}])],
  ['/digital/orders/summary?provider=topup&main_account_id=11',totals(11)],
  ['/digital/connections?provider=topup&main_account_id=11&per_page=1',funds(11,'12500.67')],
 ]);
 const view=await controls({snapshots});
 try{
  view.filters.main_account_id=22;
  assert.equal(view.rows.value.length,0);assert.equal(view.summary.value,null);assert.equal(view.balance.value,null);
  const pending=view.load();for(const read of view.reads)read.pending.reject(Error('offline'));await pending;
  assert.equal(view.rows.value.length,0);assert.equal(view.summary.value,null);assert.equal(view.balance.value,null);
  assert.equal(view.meta.total,0);assert.equal(view.vm.state.error,'offline');
  view.filters.main_account_id=11;assert.equal(view.rows.value[0].id,11);assert.equal(view.balance.value.amount,'12500.67');
  view.filters.status='failed';assert.equal(view.rows.value.length,0);assert.equal(view.summary.value,null);
 }finally{view.dispose();}
});
