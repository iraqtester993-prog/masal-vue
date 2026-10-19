import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import {computed,reactive,ref} from 'vue';
import {parse} from '@vue/compiler-sfc';
import {createApiClient} from '../src/shared/api/client.js';
import {createReadCache} from '../src/shared/api/read-cache.js';
import {createReportApi} from '../src/modules/reports/report-api.js';
import * as reports from '../src/modules/reports/report-model.js';
import {createPagedResource} from '../src/modules/accounts/paged-resource.js';

test('returning after five minutes paints known data while a live read updates it; a transfer, secret read and actor change cannot borrow it',async t=>{
 let time=0,calls=0,finish;
 t.mock.method(Date,'now',()=>time);
 const original=globalThis.document;globalThis.document={cookie:'XSRF-TOKEN=test'};
 t.after(()=>{if(original===undefined)delete globalThis.document;else globalThis.document=original;});
 t.mock.method(globalThis,'fetch',async url=>{
  calls++;if(calls===2)return new Promise(resolve=>finish=()=>resolve(Response.json({data:{cards:[{key:'balance',value:93}]}})));
  return Response.json({data:{cards:[{key:'balance',value:94}]}});
 });
 const api=createApiClient('/api/v1');api.setReadScope('pos:1');const path='/dashboard/summary?currency=IQD&view=cards';
 await api.request(path);time=300000;
 assert.equal(api.peekRequest(path).data.cards[0].value,94);assert.equal(api.hasFreshRequest(path),false);
 const update=api.request(path);await Promise.resolve();assert.equal(calls,2);assert.equal(api.peekRequest(path).data.cards[0].value,94);
 finish();await update;assert.equal(api.peekRequest(path).data.cards[0].value,93);
 assert.equal(api.peekRequest('/sales/1/receipt'),null);assert.equal(api.peekRequest('/digital/orders/id/print-authorization'),null);
 await api.mutate('/finance/transfers','POST',{});assert.equal(api.peekRequest(path),null);
 await api.request(path);api.setReadScope('pos:2');assert.equal(api.peekRequest(path),null);
});

test('an unavailable or deleted resource removes the old display instead of retaining access to it',async()=>{
 const cache=createReadCache();await cache.read('distribution',async()=>({data:[1]}));
 await assert.rejects(cache.read('distribution',async()=>{throw Object.assign(Error('not found'),{status:404});},{force:true}));
 assert.equal(cache.peek('distribution',600000),null);
});

test('returning to a selected report preserves the exact filters and counts throughout options revalidation',async()=>{
 const source=await readFile(new URL('../src/modules/reports/ReportsPage.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import[\s\S]*?;\r?$/gm,'');
 const options={accounts:[],products:[],providers:[],cities:[],currencies:['IQD'],groups:[{id:'sales',prefixes:['sales'],title:'Sales'}],sections:[{id:'sales',title:'Sales'}]};
 let saved={filters:{...reports.emptyFilters(),agent_id:42,from:'2026-10-01'},group:'sales'},finish,remembered;
 const calls=[],unmount=[];
 const session={state:reactive({identity:{account:{type:'pos'}}}),api:{
  peekRequest:path=>path.startsWith('/reports/options')?{data:options}:{data:{sections:[{id:'sales',available:true,count:27}]}},
  hasFreshRequest:()=>false,
  request:async path=>{calls.push(path);return path.startsWith('/reports/options')?{data:options}:new Promise(resolve=>finish=()=>resolve({data:{sections:[{id:'sales',available:true,count:28}]}}));}
 }};
 const scope={...reports,computed,reactive,ref,AbortController,usePortal:()=>({session}),usePreferencesTranslator:()=>v=>v,createReportApi,
  useListNavigation:()=>({saved,restoreScroll:async()=>{},remember:value=>remembered=value}),onBeforeUnmount:fn=>unmount.push(fn),onMounted(){},onServerPrefetch(){}};
 scope.useRouteRefresh=()=>{};
 runInNewContext(script+'\nglobalThis.controls={load,sections,group,filters};',scope);
 const c=scope.controls;assert.equal(c.group.value,'sales');assert.equal(c.filters.agent_id,42);assert.equal(c.sections.value[0].count,27);
 const pending=c.load(true);await new Promise(setImmediate);
 assert.equal(c.sections.value[0].count,27,'loading options cannot blank an already hydrated summary');
 assert.equal(new URL(calls.at(-1),'https://local.test').searchParams.get('agent_id'),'42');
 finish();await pending;assert.equal(c.sections.value[0].count,28);
 for(const fn of unmount)fn();assert.equal(remembered.group,'sales');assert.equal(remembered.filters.from,'2026-10-01');
});

test('agent list restoration hydrates the selected page and filters before any account detail request',async()=>{
 const source=await readFile(new URL('../src/modules/accounts/NetworkWorkspace.vue',import.meta.url),'utf8');
 const script=parse(source).descriptor.scriptSetup.content.replace(/^import[\s\S]*?;\r?$/gm,'');
 const saved={page:3,filters:{q:'selected',type:'main_agent',status:'active',city:'',per_page:10},view:'list',hidden:['phone']};
 let remembered;const callbacks=[];
 const session={state:reactive({identity:{account:{id:1,type:'system'},membership:{kind:'owner'}}}),can:()=>true,api:{
  peekAccounts:parameters=>parameters.page===3&&parameters.q==='selected'?{data:[{id:42,name:'Known account'}],meta:{current_page:3,last_page:4,total:40}}:null,
  accounts(){throw Error('Hydration cannot fetch');}
 }};
 const scope={computed,reactive,ref,watch(){},defineAsyncComponent:()=>({}),useRoute:()=>({name:'agents',query:{}}),useAccountAccess:()=>({session,router:{},handleFailure:async e=>e.message}),
  useListNavigation:()=>({saved,restoreScroll:async()=>{},remember:v=>remembered=v}),createPagedResource,AbortController,onMounted(){},onBeforeUnmount:fn=>callbacks.push(fn),setTimeout,clearTimeout};
 scope.useRouteRefresh=()=>{};
 runInNewContext(script+'\nglobalThis.controls={state,filters,view,hidden};',scope);
 assert.equal(scope.controls.state.rows[0].id,42);assert.equal(scope.controls.state.meta.current_page,3);assert.equal(scope.controls.filters.q,'selected');
 for(const fn of callbacks)fn();assert.equal(remembered.page,3);assert.equal(remembered.hidden[0],'phone');
});
