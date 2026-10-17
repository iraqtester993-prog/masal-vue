import test from 'node:test';
import assert from 'node:assert/strict';
import {installPullRefresh,refreshBlocked} from '../src/shared/pull-refresh.js';
test('pull refresh requires a deliberate vertical pull at the top and never interrupts protected operations',()=>{
 const handlers=new Map(),target={addEventListener:(name,fn)=>handlers.set(name,fn),removeEventListener:name=>handlers.delete(name)};
 let blocked=false,top=0,refreshes=0,prevented=0;
 const stop=installPullRefresh({target,canStart:()=>!blocked,onRefresh:()=>refreshes++,scrollTop:()=>top});
 const gesture=(x,y)=>{handlers.get('touchstart')({touches:[{clientX:30,clientY:50}],target:{closest:()=>false}});handlers.get('touchmove')({touches:[{clientX:30+x,clientY:50+y}],cancelable:true,preventDefault:()=>prevented++});handlers.get('touchend')();};
 gesture(1,160);assert.equal(refreshes,1);assert.equal(prevented,1);
 gesture(170,5);gesture(1,40);top=20;gesture(1,160);top=0;blocked=true;gesture(1,160);assert.equal(refreshes,1);
 blocked=false;handlers.get('touchstart')({touches:[{clientX:0,clientY:0}],target:{closest:()=>true}});handlers.get('touchend')();assert.equal(refreshes,1);
 assert.equal(refreshBlocked({pendingMutation:true}),true);assert.equal(refreshBlocked({dialogOpen:true}),true);assert.equal(refreshBlocked({printerBusy:true}),true);assert.equal(refreshBlocked(),false);
 stop();assert.equal(handlers.size,0);
});
