import test from 'node:test';
import assert from 'node:assert/strict';
import {createRouteRefreshCoordinator} from '../src/shared/route-refresh.js';

test('the active child refreshes in place and releasing it returns ownership to the page',async()=>{
 const coordinator=createRouteRefreshCoordinator(),calls=[];
 coordinator.register(()=>calls.push('wallets'));
 const release=coordinator.register(()=>calls.push('funding'),{priority:10});
 await coordinator.refresh();assert.deepEqual(calls,['funding']);release();
 await coordinator.refresh();assert.deepEqual(calls,['funding','wallets']);
});

test('a blocked child never falls back to refreshing its parent or discarding its draft',async()=>{
 const coordinator=createRouteRefreshCoordinator();let calls=0;
 coordinator.register(()=>calls++);let blocked=true;
 coordinator.register(()=>calls++,{priority:10,canRefresh:()=>!blocked});
 assert.equal(coordinator.canRefresh(),false);assert.equal(await coordinator.refresh(),false);assert.equal(calls,0);
 blocked=false;assert.equal(coordinator.canRefresh(),true);await coordinator.refresh();assert.equal(calls,1);
});

test('repeated refresh shares one read and a route cancellation cannot report completion for a new page',async()=>{
 const coordinator=createRouteRefreshCoordinator();let finish,calls=0,signal;
 coordinator.register(options=>{calls++;signal=options.signal;return new Promise(resolve=>finish=resolve);});
 const first=coordinator.refresh(),second=coordinator.refresh();assert.equal(first,second);
 await Promise.resolve();assert.equal(calls,1);assert.equal(coordinator.canRefresh(),false);
 coordinator.cancel();assert.equal(signal.aborted,true);finish();await assert.rejects(first,{name:'AbortError'});
 assert.equal(coordinator.canRefresh(),true);
});

test('unmount cancels the old page without canceling a new page refresh',async()=>{
 const coordinator=createRouteRefreshCoordinator();let finish;
 const release=coordinator.register(()=>new Promise(resolve=>finish=resolve));
 const previous=coordinator.refresh();await Promise.resolve();release();
 coordinator.register(()=>true);assert.equal(await coordinator.refresh(),true);
 finish();await assert.rejects(previous,{name:'AbortError'});
});

test('a displayed refresh failure is never announced as a completed update',async()=>{
 const coordinator=createRouteRefreshCoordinator();coordinator.register(()=>false);
 assert.equal(await coordinator.refresh(),false);assert.equal(coordinator.canRefresh(),true);
});

test('an unregistered page cannot announce a successful update without reading its data',async()=>{
 const coordinator=createRouteRefreshCoordinator();assert.equal(coordinator.canRefresh(),false);assert.equal(await coordinator.refresh(),false);
});
