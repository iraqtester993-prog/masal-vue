import test from 'node:test';
import assert from 'node:assert/strict';
import {createCursorFeed} from '../src/shared/api/cursor-feed.js';
const response=(ids,next=null)=>({data:ids.map(id=>({kind:'stock',record:{id}})),meta:{pagination:'cursor',per_page:10,has_more:!!next,next_cursor:next}});
const state=()=>({rows:[],meta:{},loading:false,error:''}),key=row=>`${row.kind}:${row.record.id}`;
test('cursor history reads ten with opaque continuation, deduplicates mixed operation IDs and stops at exhaustion',async()=>{
 const view=state(),calls=[];let finish;
 const first=response([30,29],'opaque-one');first.data.push({kind:'digital',record:{id:30}});
 const feed=createCursorFeed({state:view,key,read:async params=>{calls.push(params);return params.cursor?new Promise(resolve=>finish=()=>resolve(response([29,28]))):first;}});
 await feed.load();const one=feed.more(),two=feed.more();assert.equal(one,two);assert.equal(calls.length,2);assert.equal(view.rows.length,3);finish();await one;
 assert.deepEqual(view.rows.map(key),['stock:30','stock:29','digital:30','stock:28']);await feed.more();assert.equal(calls.length,2);
 assert.deepEqual(calls,[{pagination:'cursor',per_page:10},{pagination:'cursor',per_page:10,cursor:'opaque-one'}]);
 assert.equal(view.meta.has_more,false);assert.ok(!Object.hasOwn(view.meta,'total'));
});
test('cached cursor chain hydrates immediately without reads and cannot cross to an unrelated filter',()=>{
 const view=state(),cache=new Map([['all:',response([3],'next')],['all:next',response([2,1])]]);let kind='all';
 const feed=createCursorFeed({state:view,key,parameters:()=>({kind}),peek:params=>cache.get(`${params.kind}:${params.cursor||''}`),read:()=>assert.fail('hydrate cannot request')});
 feed.hydrate(2);assert.deepEqual(view.rows.map(key),['stock:3','stock:2','stock:1']);assert.equal(feed.pageCount(),2);
 kind='topup';feed.hydrate(2);assert.deepEqual(view.rows,[]);assert.equal(view.meta.has_more,false);
});
test('fresh refresh retains visible rows until all restored pages succeed and follows the new continuation chain',async()=>{
 const view=state(),calls=[];let phase='initial',release;
 const feed=createCursorFeed({state:view,key,read:async(params,signal,options)=>{calls.push({params,options});if(phase==='failed')throw Error('offline');if(phase==='fresh'){if(!params.cursor)await new Promise(resolve=>release=resolve);return params.cursor?response([3,2]):response([5,4],'fresh-next');}return params.cursor?response([2,1]):response([3],'old-next');}});
 await feed.load();await feed.more();phase='failed';await feed.refresh();assert.deepEqual(view.rows.map(key),['stock:3','stock:2','stock:1']);assert.equal(feed.pageCount(),2);
 phase='fresh';const refreshing=feed.refresh();assert.equal(view.rows.length,3);release();await refreshing;
 assert.deepEqual(view.rows.map(key),['stock:5','stock:4','stock:3','stock:2']);assert.equal(calls.at(-1).params.cursor,'fresh-next');assert.ok(calls.slice(-2).every(call=>call.options.fresh));
});
test('filter changes abort prior cursor reads and late results cannot reintroduce the old records',async()=>{
 const view=state();let kind='all',finish,signal;
 const feed=createCursorFeed({state:view,key,parameters:()=>({kind}),read:async(params,abort)=>params.kind==='all'?(signal=abort,new Promise(resolve=>finish=()=>resolve(response([99],'stale')))):response([7])});
 const stale=feed.load();kind='topup';await feed.load();assert.equal(signal.aborted,true);finish();await stale;assert.deepEqual(view.rows.map(key),['stock:7']);assert.equal(view.loading,false);
});
test('permission loss or a cursor rejected for changed scope removes protected prior rows',async()=>{
 for(const failure of [Object.assign(Error('forbidden'),{status:403}),Object.assign(Error('scope changed'),{status:422,errors:{cursor:['invalid']}})]){
  const view=state();let allowed=true;const feed=createCursorFeed({state:view,key,read:async()=>{if(!allowed)throw failure;return response([1],'next');}});
  await feed.load();allowed=false;await feed.more();assert.deepEqual(view.rows,[]);assert.equal(view.meta.has_more,false);assert.equal(view.error,failure.message);
 }
});
test('invalid oversized pages and repeated cursors cannot grow the view or cause an infinite loading chain',async()=>{
 for(const invalid of [response(Array.from({length:11},(_,i)=>i)),{data:[],meta:{has_more:true,next_cursor:null}}]){
  const view=state(),feed=createCursorFeed({state:view,key,read:async()=>invalid});await feed.load();assert.deepEqual(view.rows,[]);assert.ok(view.error);assert.equal(view.loading,false);
 }
 const view=state(),feed=createCursorFeed({state:view,key,read:async params=>params.cursor==='two'?response([1],'one'):params.cursor==='one'?response([2],'two'):response([3],'one')});
 await feed.load();await feed.more();await feed.more();assert.deepEqual(view.rows.map(key),['stock:3','stock:2']);assert.ok(view.error);
});
