import test from 'node:test';
import assert from 'node:assert/strict';
import {createOperationsApi} from '../src/modules/security/operations-api.js';
import {previewCount,emptyTimePolicy,systemOwner} from '../src/modules/security/operations-model.js';
import {createMutationKey} from '../src/modules/finance/finance-model.js';
import {trackTimeActivity} from '../src/modules/security/time-activity.js';

test('operations API preserves server version and idempotency contracts and sends no client time or invented scope',async () => {
  const calls=[],signal=new AbortController().signal,client={request:async (path,options)=>{calls.push({path,...options});return {data:[]};},mutate:async (path,method,body,signal)=>{calls.push({path,method,body,signal});return {data:{}};}},api=createOperationsApi(client);
  await api.security({page:2,query:'فرع & بغداد'},signal);
  assert.equal(calls[0].path,'/operations/security?page=2&query=%D9%81%D8%B1%D8%B9+%26+%D8%A8%D8%BA%D8%AF%D8%A7%D8%AF');
  const stop={scope:'main',actions:['sales'],reason:'مراجعة',idempotency_key:'once'};await api.createStop(stop,signal);assert.equal(calls[1].body,stop);
  const resume={version:7,idempotency_key:'resume'};await api.resume(81,resume,signal);assert.equal(calls[2].path,'/operations/security/stops/81/resume');assert.deepEqual(calls[2].body,resume);
  await api.saveTime(13,{policy:emptyTimePolicy(),version:0,idempotency_key:'time'},signal);assert.equal(calls[3].method,'PUT');assert.equal(calls[3].path,'/operations/account-times/13');
  await api.activity(signal);assert.deepEqual(calls[4].body,{});
  const archive={reason:'إغلاق',password:'private-runtime-value',version:4,idempotency_key:'archive'};await api.createArchive(19,archive,signal);assert.equal(calls[5].path,'/operations/archive/accounts/19');assert.equal(calls[5].body,archive);assert.equal(calls[5].signal,signal);
});

test('original same-level choices exclude descendants and custom preview uses only currently permitted accounts',()=>{
  const accounts=[{id:1,type:'main_agent'},{id:2,type:'sub_agent'},{id:3,type:'sub_branch'},{id:4,type:'pos'},{id:5,type:'pos'}];
  assert.equal(previewCount(accounts,'main',[]),1);assert.equal(previewCount(accounts,'branch',[]),1);assert.equal(previewCount(accounts,'subbranch',[]),1);assert.equal(previewCount(accounts,'pos',[]),2);assert.equal(previewCount(accounts,'all',[]),5);assert.equal(previewCount(accounts,'custom',[4,4,99]),1);
  assert.equal(systemOwner({account:{type:'system'},membership:{kind:'employee'}}),false);assert.equal(systemOwner({account:{type:'main_agent'},membership:{kind:'owner'}}),false);assert.equal(systemOwner({account:{type:'system'},membership:{kind:'owner'}}),true);
  const policy=emptyTimePolicy();policy.idleMinutes=1;assert.equal(emptyTimePolicy().idleMinutes,60);
});

test('lost archive response retries preserve the same operation without retaining a password in its fingerprint',()=>{
  let i=0;const key=createMutationKey(()=>`key-${++i}`),payload={id:19,reason:'إغلاق',version:4};
  assert.equal(key.for(payload),'key-1');assert.equal(key.for({...payload}),'key-1');assert.equal(key.for({...payload,version:5}),'key-2');key.clear();assert.equal(key.for(payload),'key-3');
});

test('idle activity only follows trusted visible interactions, debounces calls, refreshes expired auth and cleans listeners',async ()=>{
  const handlers=new Map(),calls=[],surface={document:{visibilityState:'visible'},addEventListener:(name,fn)=>handlers.set(name,fn),removeEventListener:name=>handlers.delete(name)},session={state:{identity:{user:{id:1}}},api:{mutate:async (path,method,body,signal)=>{calls.push({path,method,body,signal});}},refresh:async()=>null};
  let clock=0;const stop=trackTimeActivity(session,surface,()=>clock),event=handlers.get('pointerdown');
  await event({isTrusted:false});assert.equal(calls.length,0);await event({isTrusted:true});assert.equal(calls.length,1);assert.deepEqual(calls[0].body,{});
  clock=29000;await event({isTrusted:true});assert.equal(calls.length,1);clock=30000;surface.document.visibilityState='hidden';await event({isTrusted:true});assert.equal(calls.length,1);
  surface.document.visibilityState='visible';await event({isTrusted:true});assert.equal(calls.length,2);stop();assert.equal(handlers.size,0);assert.equal(calls[0].signal.aborted,true);clock=60000;await event({isTrusted:true});assert.equal(calls.length,2);
});
