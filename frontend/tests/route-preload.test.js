import test from 'node:test';
import assert from 'node:assert/strict';
import {createRoutePreloader} from '../src/router/preload.js';
test('route warming waits for page data, honors permissions and never mounts a component',async()=>{
 const tasks=[],calls=[];let ready=false;const routes=['reports','wallets','private'].map(name=>({name,components:{default:async()=>{calls.push(name);return {setup(){throw Error('must not mount');}};}}}));
 const warm=createRoutePreloader({routes:()=>routes,allowed:route=>route.name!=='private',ready:()=>ready,schedule:task=>tasks.push(task)});
 warm.start(['reports','wallets','private'],'reports');tasks.shift()();assert.deepEqual(calls,[]);ready=true;tasks.shift()();await new Promise(resolve=>setImmediate(resolve));assert.deepEqual(calls,['wallets']);while(tasks.length){tasks.shift()();await new Promise(resolve=>setImmediate(resolve));}assert.deepEqual(calls,['wallets']);
});
test('a newer navigation cancels queued imports and data saver prevents warming',async()=>{
 const tasks=[],calls=[];let constrained=false;const routes=['a','b'].map(name=>({name,components:{default:async()=>calls.push(name)}}));const warm=createRoutePreloader({routes:()=>routes,allowed:()=>true,ready:()=>true,schedule:task=>tasks.push(task),constrained:()=>constrained});warm.start(['a'],'b');warm.start(['b'],'a');while(tasks.length){tasks.shift()();await new Promise(resolve=>setImmediate(resolve));}assert.deepEqual(calls,['b']);constrained=true;warm.start(['a'],'b');assert.equal(tasks.length,0);
});
