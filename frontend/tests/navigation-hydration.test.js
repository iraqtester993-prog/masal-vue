import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,onServerPrefetch,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {routeLocationKey} from 'vue-router';
import {createPagedResource} from '../src/modules/accounts/paged-resource.js';

test('a paged list hydrates synchronously for its exact query and clears invalidated or different-query rows',async()=>{
 const cache=new Map([['first',{data:[{id:1,name:'remembered'}],meta:{current_page:2,last_page:3,total:21}}]]);
 let finish;
 const resource=createPagedResource((_params,_signal,options)=>{assert.equal(options.fresh,true);return new Promise(resolve=>finish=resolve);},undefined,{peek:params=>cache.get(params.q)});
 resource.hydrate({q:'first'});assert.equal(resource.state.rows[0].name,'remembered');assert.equal(resource.state.meta.current_page,2);
 const pending=resource.load({q:'first'},{fresh:true});assert.equal(resource.state.rows[0].id,1);assert.equal(resource.state.loading,true);
 finish({data:[{id:2,name:'current'}],meta:{current_page:2,last_page:3,total:22}});await pending;assert.equal(resource.state.rows[0].id,2);
 resource.hydrate({q:'other'});assert.deepEqual(resource.state.rows,[],'a new filter cannot display the previous filter rows');
 cache.clear();resource.hydrate({q:'first'});assert.deepEqual(resource.state.rows,[],'a mutation or identity change must remove remembered rows');resource.dispose();
});

test('an expired display snapshot cannot blank an already open list while the same query revalidates',async()=>{
 let finish;const cache=new Map([['first',{data:[{id:1,name:'Visible list'}],meta:{current_page:2,last_page:3,total:21}}]]);
 const resource=createPagedResource(()=>new Promise(resolve=>{finish=resolve;}),undefined,{peek:params=>cache.get(params.q)});
 resource.hydrate({q:'first',page:2});cache.clear();
 const refresh=resource.load({page:2,q:'first'},{fresh:true});assert.equal(resource.state.rows[0].name,'Visible list');assert.equal(resource.state.meta.current_page,2);
 finish({data:[{id:2,name:'Current list'}],meta:{current_page:2,last_page:3,total:22}});await refresh;assert.equal(resource.state.rows[0].name,'Current list');
 const changed=resource.load({page:2,q:'other'});assert.deepEqual(resource.state.rows,[]);assert.equal(resource.state.meta.total,0);
 finish({data:[],meta:{current_page:2,last_page:2,total:0}});await changed;resource.dispose();
});

test('actual sell and history components render remembered catalog and operations before mounted reads run',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const {portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  const sell=(await server.ssrLoadModule('/src/modules/sales/SellPage.vue')).default;
  const history=(await server.ssrLoadModule('/src/modules/digital/POSHistory.vue')).default;
  const stock={id:21,provider_id:3,provider_name:'شركة محفوظة',name:'فئة محفوظة عند الرجوع',price:'5000.00',price_version:1,available:8};
  const operation={kind:'stock',record:{id:91,account_id:12,provider_name:'شركة محفوظة',product_name:'عملية ظاهرة فور الرجوع',quantity:1,retail_total:'5000.00',status:'Printed',issued_at:'2026-10-16T10:00:00Z'}};
  const snapshots=new Map([['/sales/products',{data:[stock],pending_print:null}],['/digital/offers?',{data:[]}],['/digital/history?kind=all&pagination=cursor&per_page=10',{data:[operation],meta:{pagination:'cursor',per_page:10,has_more:false,next_cursor:null}}]]);
  const identity={user:{id:9},account:{id:12,type:'pos'},membership:{kind:'owner'}};
  const session={state:reactive({identity}),can:()=>true,refresh:async()=>null,api:{peekRequest:path=>structuredClone(snapshots.get(path)??null),request(){throw Error('initial render must not fetch');},mutate(){throw Error('navigation must not write');}}};
  function app(component,props={}){const value=createSSRApp({render:()=>h(component,props)});value.provide(portalContextKey,{session,portal:{id:'pos'},config:{}});value.provide(routeLocationKey,reactive({name:'sell',query:{}}));return value;}
  const sellHtml=await renderToString(app(sell));assert.ok(sellHtml.includes('فئة محفوظة عند الرجوع'));assert.ok(sellHtml.includes('شركة محفوظة'));assert.ok(!sellHtml.includes('جارٍ تحميل الفئات'));
  const sales={identity:ref(identity),state:reactive({busy:false}),receiptLoading:ref(false),can:()=>true};
  const historyHtml=await renderToString(app(history,{sales}));assert.ok(historyHtml.includes('عملية ظاهرة فور الرجوع'));assert.ok(!historyHtml.includes('جارٍ تحميل السجل'));
  snapshots.clear();const cleared=await renderToString(app(history,{sales}));assert.ok(!cleared.includes('عملية ظاهرة فور الرجوع'),'a cleared session snapshot cannot survive in a remounted page');
  const failingSession={...session,api:{...session.api,request:async()=>{throw Error('offline');}}};
  const inspected={...history,setup(props,context){const controls=history.setup(props,context);onServerPrefetch(async()=>{Object.assign(controls.feedState.meta,{pagination:'cursor',per_page:10,has_more:true,next_cursor:'old-filter'});controls.filters.q='new filter with no snapshot';await controls.feed.load();assert.deepEqual({...controls.meta.value},{pagination:'cursor',per_page:10,has_more:false,next_cursor:null},'a failed new filter cannot retain continuation from the previous query');assert.deepEqual(controls.rows.value,[]);});return controls;}};
  const failureApp=createSSRApp({render:()=>h(inspected,{sales})});failureApp.provide(portalContextKey,{session:failingSession,portal:{id:'pos'},config:{}});await renderToString(failureApp);
 }finally{await server.close();}
});
