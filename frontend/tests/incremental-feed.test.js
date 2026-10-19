import test from 'node:test';
import assert from 'node:assert/strict';
import {createIncrementalFeed} from '../src/shared/api/incremental-feed.js';

const response=(page,ids,last=3)=>({data:ids.map(id=>({id})),meta:{current_page:page,last_page:last,total:25}});
const state=()=>({rows:[],meta:{current_page:1,last_page:1,total:0},loading:false,error:''});
test('history requests ten at a time, merges overlaps once and prevents concurrent next-page requests',async()=>{
 const view=state(),calls=[];let finish;
 const feed=createIncrementalFeed({state:view,key:r=>r.id,read:async params=>{calls.push(params);return params.page===1?response(1,[3,2]):new Promise(resolve=>finish=()=>resolve(response(2,[2,1])));}});
 await feed.load();const first=feed.more(),second=feed.more();assert.equal(first,second);assert.equal(calls.length,2);assert.deepEqual(view.rows.map(r=>r.id),[3,2]);
 finish();await first;assert.deepEqual(view.rows.map(r=>r.id),[3,2,1]);assert.ok(calls.every(p=>p.per_page===10));
});
test('failed refresh keeps all known history and page position; successful refresh replaces it with fresh reads',async()=>{
 const view=state();let fail=false,pending=false,finish;const calls=[];
 const feed=createIncrementalFeed({state:view,key:r=>r.id,read:async(params,signal,options)=>{calls.push({...params,...options});if(fail)throw Error('network unavailable');if(pending)await new Promise(resolve=>finish=resolve);return response(params.page,[params.page]);}});
 await feed.load();await feed.more();fail=true;await feed.refresh();assert.deepEqual(view.rows.map(r=>r.id),[1,2]);assert.equal(view.meta.current_page,2);assert.equal(view.error,'network unavailable');
 fail=false;pending=true;const updating=feed.refresh();assert.equal(view.rows.length,2);finish();await new Promise(setImmediate);finish();await updating;assert.equal(view.meta.current_page,2);assert.ok(calls.slice(-2).every(p=>p.fresh));
});
test('changing filters cancels stale results and restored pages hydrate without fetching',async()=>{
 const view=state();let filter='old',finish;
 const feed=createIncrementalFeed({state:view,key:r=>r.id,parameters:()=>({q:filter}),peek:p=>response(p.page,[p.page]),read:async p=>p.q==='old'?new Promise(resolve=>finish=()=>resolve(response(1,[99]))):response(1,[42],1)});
 feed.hydrate(2);assert.equal(view.rows.length,2);const stale=feed.load();filter='new';await feed.load();finish();await stale;assert.deepEqual(view.rows.map(r=>r.id),[42]);
});
test('permission loss removes previously displayed history rather than retaining protected records',async()=>{
 const view=state();let allowed=true;
 const feed=createIncrementalFeed({state:view,key:r=>r.id,read:async()=>{if(!allowed)throw Object.assign(Error('permission revoked'),{status:403});return response(1,[1]);}});
 await feed.load();allowed=false;await feed.refresh();assert.equal(view.rows.length,0);
});
