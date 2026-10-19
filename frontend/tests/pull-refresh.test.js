import test from 'node:test';
import assert from 'node:assert/strict';
import {installPullRefresh,refreshBlocked,pullScrollTop} from '../src/shared/pull-refresh.js';
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

test('a nested scrolling list must reach its own top as well as the page top',()=>{
 const root={scrollTop:0},surface={scrollingElement:root},container={scrollHeight:500,clientHeight:200,scrollTop:30,parentElement:root},child={parentElement:container};
 assert.equal(pullScrollTop(child,{surface,target:{scrollY:0}}),30);
 container.scrollTop=0;assert.equal(pullScrollTop(child,{surface,target:{scrollY:0}}),0);
 root.scrollTop=40;assert.equal(pullScrollTop(child,{surface,target:{scrollY:40}}),40);
});

test('horizontal gestures and refresh-excluded surfaces never capture a page pull',()=>{
 const events=new Map();let calls=0,prevented=0,selector='';
 const target={addEventListener:(key,fn)=>events.set(key,fn),removeEventListener:key=>events.delete(key)};
 const stop=installPullRefresh({target,scrollTop:()=>0,canStart:()=>true,onRefresh:()=>calls++});
 events.get('touchstart')({touches:[{clientX:0,clientY:0}],target:{closest:value=>{selector=value;return {};}}});
 events.get('touchmove')({touches:[{clientX:0,clientY:180}],cancelable:true,preventDefault:()=>prevented++});events.get('touchend')();
 assert.match(selector,/role="dialog"/);assert.match(selector,/data-no-pull-refresh/);assert.equal(calls,0);assert.equal(prevented,0);
 events.get('touchstart')({touches:[{clientX:0,clientY:0}],target:{closest:()=>null}});
 events.get('touchmove')({touches:[{clientX:180,clientY:20}],cancelable:true,preventDefault:()=>prevented++});events.get('touchend')();
 assert.equal(calls,0);assert.equal(prevented,0);stop();
});
