import test from 'node:test';
import assert from 'node:assert/strict';
import {createNavigationTiming} from '../src/shared/api/navigation-timing.js';
function fixture(){let clock=0;const tasks=[],states=[];return {tasks,states,time:value=>clock=value,timing:createNavigationTiming({now:()=>clock,publish:state=>states.push(state),schedule:task=>tasks.push(task)}),flush(){while(tasks.length)tasks.shift()();}};}
test('network and server durations stay scoped to the initiating navigation',()=>{
 const f=fixture();f.timing.start('/reports');const old=f.timing.network('/reports/options');f.timing.start('/wallets');const current=f.timing.network('/finance/options');old(9000,4000);current(1200,80);f.timing.network('/maps/location')(3000,2000);assert.equal(f.states.at(-1).network_ms,1200);assert.equal(f.states.at(-1).server_ms,80);f.timing.paint('/wallets');f.flush();current(9000,3000);assert.equal(f.states.at(-1).network_ms,1200);
});
test('navigation waits for parallel and chained page reads, excluding telemetry and mutations',()=>{
 const f=fixture();f.timing.start('/reports');const options=f.timing.read('/reports/options'),wallet=f.timing.read('/finance/wallets');f.time(10);f.timing.paint('/reports');f.time(40);options();const summary=f.timing.read('/reports/summary');f.timing.read('/maps/heartbeat')();f.timing.read('/digital/orders','POST')();f.flush();assert.equal(f.states.at(-1).ready_ms,null);f.time(60);wallet();f.flush();assert.equal(f.states.at(-1).ready_ms,null);f.time(90);summary();f.flush();assert.equal(f.states.at(-1).paint_ms,10);assert.equal(f.states.at(-1).ready_ms,90);assert.equal(f.states.at(-1).requests,3);
});
test('old responses and duplicate completion cannot change a newer route measurement',()=>{
 const f=fixture();f.timing.start('/reports');const old=f.timing.read('/reports/options');f.time(20);f.timing.start('/wallets');const fresh=f.timing.read('/finance/options');f.time(25);f.timing.paint('/wallets');f.time(40);old(true);f.flush();assert.equal(f.states.at(-1).pending,1);fresh();fresh();f.flush();assert.equal(f.states.at(-1).path,'/wallets');assert.equal(f.states.at(-1).pending,0);assert.equal(f.states.at(-1).errors,0);assert.equal(f.states.at(-1).ready_ms,20);
});
test('failed page reads are counted and later background refreshes leave completed navigation unchanged',()=>{
 const f=fixture();f.timing.start('/dashboard');const read=f.timing.read('/dashboard/summary');f.time(10);f.timing.paint('/dashboard');f.time(30);read(true);f.flush();assert.equal(f.states.at(-1).errors,1);f.time(90);f.timing.read('/dashboard/activity')();f.flush();assert.equal(f.states.at(-1).ready_ms,30);
});
