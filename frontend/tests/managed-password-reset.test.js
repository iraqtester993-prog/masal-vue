import test from 'node:test';
import assert from 'node:assert/strict';
import {createSession} from '../src/modules/auth/session.js';
import {ApiError,createApiClient} from '../src/shared/api/client.js';
import {bootstrapPasswordRecoveryDevice,canResetAccountPassword,createPasswordResetMonitor,createRecoveryDeviceAttempt,newPasswordError,passwordResetState} from '../src/modules/auth/password-reset.js';
import {createRouter,createMemoryHistory} from 'vue-router';
import {createSessionNavigationGuard,landingRoute,routeAllowed} from '../src/router/access.js';
const deferred=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};
const identity=(required=false,version=0,id=1)=>({data:{user:{id},account:{id:10,type:'main_agent'},membership:{kind:'owner'},portal:'agents',permissions:['dashboard.view','account.password_reset'],password_reset:{required,version}}});
const client=(overrides={})=>({csrf:async()=>{},login:async()=>identity(),me:async()=>identity(),logout:async()=>{},...overrides});

test('forced changes retain authentication while blocking permissions, landing and direct/history routes',async()=>{
  const auth=createSession('agents',client({login:async()=>identity(true,3)}));await auth.login('agent','secret');
  assert.equal(auth.state.identity.user.id,1);assert.equal(auth.can('dashboard.view'),false);
  const routes=[['dashboard','/dashboard',true],['login','/login',false],['password-change','/change-password',true]].map(([name,path,requiresAuth])=>({name,path,component:{render:()=>null},meta:{requiresAuth,...name==='dashboard'?{permission:'dashboard.view'}:{}}}));
  const router=createRouter({history:createMemoryHistory(),routes});router.beforeEach(createSessionNavigationGuard(auth,router));
  assert.deepEqual(landingRoute(router,auth),{name:'password-change'});assert.equal(routeAllowed(routes[0],auth),false);
  await router.push('/dashboard');assert.equal(router.currentRoute.value.name,'password-change');
  await router.push('/login');assert.equal(router.currentRoute.value.name,'password-change');
  let body;auth.api.completePasswordReset=async value=>{body=value;return identity(false,4);};
  await auth.completePasswordReset('newpass123','newpass123',3);
  assert.deepEqual(body,{version:3,password:'newpass123',password_confirmation:'newpass123'});
  assert.equal(auth.can('dashboard.view'),true);await router.push('/dashboard');assert.equal(router.currentRoute.value.name,'dashboard');
});

test('a live protected API response forces change without logout and stale me cannot restore prior access',async()=>{
  const previousDocument=globalThis.document,previousFetch=globalThis.fetch;globalThis.document={cookie:'XSRF-TOKEN=qa'};
  const old=deferred();
  try{
    globalThis.fetch=async url=>url.endsWith('/auth/login')?Response.json(identity()):url.endsWith('/auth/me')?old.promise:Response.json({code:'PASSWORD_CHANGE_REQUIRED',password_reset:{required:true,version:8},message:'Change required'},{status:403});
    const api=createApiClient('/api/v1');api.csrf=async()=>{};const auth=createSession('agents',api);
    await auth.login('agent','secret');const pending=auth.refresh();
    await assert.rejects(api.request('/protected-qa'),failure=>failure.code==='PASSWORD_CHANGE_REQUIRED');
    assert.equal(auth.state.identity.password_reset.version,8);assert.equal(auth.state.identity.user.id,1);assert.equal(auth.can('dashboard.view'),false);
    old.resolve(Response.json(identity(false,0)));await pending;
    assert.equal(auth.state.identity.password_reset.required,true);assert.equal(auth.state.identity.password_reset.version,8);
  }finally{globalThis.document=previousDocument;globalThis.fetch=previousFetch;}
});

test('a late reset error from a previous user cannot restrict the replacement login',async()=>{
  const previousDocument=globalThis.document,previousFetch=globalThis.fetch;globalThis.document={cookie:'XSRF-TOKEN=qa'};
  const old=deferred();let user=1;
  try{
    globalThis.fetch=async url=>url.endsWith('/auth/login')?Response.json(identity(false,0,user)):old.promise;
    const api=createApiClient('/api/v1');api.csrf=async()=>{};const auth=createSession('agents',api);
    await auth.login('first','secret');const pending=api.request('/protected-qa');user=2;await auth.login('second','secret');
    old.resolve(Response.json({code:'PASSWORD_CHANGE_REQUIRED',password_reset:{required:true,version:99}},{status:403}));await assert.rejects(pending);
    assert.equal(auth.state.identity.user.id,2);assert.equal(auth.state.identity.password_reset.required,false);
  }finally{globalThis.document=previousDocument;globalThis.fetch=previousFetch;}
});

test('foreground monitor deduplicates checks, pauses while hidden and ignores older reset versions',async()=>{
  const current=deferred(),listeners=new Map(),events=new Map();let reads=0,interval,cleared;
  const auth=createSession('agents',client({passwordResetStatus:()=>{reads++;return current.promise;}}));await auth.login('agent','secret');
  const document={visibilityState:'visible',addEventListener:(key,value)=>listeners.set(key,value),removeEventListener:key=>listeners.delete(key)};
  const monitor=createPasswordResetMonitor(auth,{document,surface:{addEventListener:(key,value)=>events.set(key,value),removeEventListener:key=>events.delete(key)},setInterval:(fn,delay)=>{interval={fn,delay};return 77;},clearInterval:id=>cleared=id});
  assert.equal(interval.delay,5000);const first=monitor.refresh(),second=monitor.refresh();assert.equal(reads,1);
  current.resolve({data:{required:true,version:7}});await Promise.all([first,second]);assert.equal(auth.state.identity.password_reset.version,7);
  await auth.applyPasswordResetStatus({data:{required:true,version:6}});assert.equal(auth.state.identity.password_reset.version,7);
  document.visibilityState='hidden';await monitor.refresh();assert.equal(reads,1);
  monitor.dispose();assert.equal(cleared,77);assert.equal(listeners.size,0);assert.equal(events.size,0);
});

test('repeated required status cannot interrupt an in-flight password confirmation',async()=>{
  const result=deferred();const auth=createSession('agents',client({login:async()=>identity(true,5),completePasswordReset:()=>result.promise}));await auth.login('agent','secret');
  const saving=auth.completePasswordReset('newpass123','newpass123',5);
  await auth.applyPasswordResetStatus({data:{required:true,version:5}});result.resolve(identity(false,6));await saving;
  assert.equal(auth.state.identity.password_reset.required,false);
});

test('new passwords preserve whitespace, require matching confirmation and enforce byte length',()=>{
  assert.ok(newPasswordError('12345678','12345678'));assert.ok(newPasswordError('123456789','different'));
  assert.ok(newPasswordError('😀'.repeat(19),'😀'.repeat(19)));assert.equal(newPasswordError(' 123456789 ',' 123456789 '),'');
  assert.deepEqual(passwordResetState({data:{required:true,version:4}}),{required:true,version:4});assert.equal(passwordResetState({required:true,version:'4'}),null);
});

test('reset action requires the server capability, owner permission and a different non-system target',()=>{
  const actor=identity().data,target={id:20,type:'pos',can_reset_password:true};assert.equal(canResetAccountPassword(actor,target),true);
  for(const account of [{...target,can_reset_password:false},{...target,id:10},{...target,type:'system'}])assert.equal(canResetAccountPassword(actor,account),false);
  assert.equal(canResetAccountPassword({...actor,membership:{kind:'employee'}},target),false);
  assert.equal(canResetAccountPassword({...actor,permissions:[]},target),false);
});

test('older identity, status and error flags cannot unlock a forced account or re-force a completed version',async()=>{
  let response=identity(false,0);
  const auth=createSession('agents',client({me:async()=>response,completePasswordReset:async()=>identity(false,4)}));await auth.login('agent','secret');
  auth.requirePasswordChange({required:true,version:3});await auth.refresh();assert.equal(auth.state.identity.password_reset.required,true);
  await auth.completePasswordReset('newpass123','newpass123',3);
  response=identity(true,3);await auth.refresh();await auth.applyPasswordResetStatus({data:{required:true,version:3}});auth.requirePasswordChange({required:true,version:3});
  assert.deepEqual({...auth.state.identity.password_reset},{required:false,version:4});assert.equal(auth.can('dashboard.view'),true);
});

test('same-device recovery signs a dedicated challenge without sending credentials or replacing the signer',async()=>{
  const calls=[],info={installation_id:'approved-device',proof_public_key:'existing-key',proof_key_kind:'android-keystore',password:'must-never-send'};
  const api={mutate:async(path,method,body)=>{calls.push({path,method,body});return {data:path.endsWith('device-challenge')?{challenge_id:'qa-proof',challenge:'exact server proof'}:{status:'approved',version:7,trusted:true}};}};
  const result=await bootstrapPasswordRecoveryDevice(api,{readIdentity:async()=>info,signChallenge:async challenge=>{assert.equal(challenge,'exact server proof');return 'signed-proof';}});
  assert.deepEqual(calls,[{path:'/auth/password-reset/device-challenge',method:'POST',body:{installation_id:'approved-device',proof_public_key:'existing-key',proof_key_kind:'android-keystore'}},{path:'/auth/password-reset/device-bind',method:'POST',body:{installation_id:'approved-device',proof_public_key:'existing-key',proof_key_kind:'android-keystore',proof_challenge_id:'qa-proof',proof_signature:'signed-proof'}}]);
  assert.equal(result.status,'approved');assert.equal(result.version,7);
});

test('a newer non-required status is a watermark against a delayed older required error',async()=>{
  const auth=createSession('agents',client());await auth.login('agent','secret');
  await auth.applyPasswordResetStatus({data:{required:false,version:4}});auth.requirePasswordChange({required:true,version:3});
  assert.deepEqual({...auth.state.identity.password_reset},{required:false,version:4});assert.equal(auth.can('dashboard.view'),true);
});

test('transient recovery proof retries only on focus after thirty seconds and stops after success or disposal',async()=>{
  let clock=0,calls=0;const second=deferred();
  const device=createRecoveryDeviceAttempt(()=>{calls++;if(calls===1)throw new ApiError('Timeout',0);return second.promise;},{now:()=>clock});
  await device.run();assert.equal(calls,1);clock=29000;await device.run({focus:true});assert.equal(calls,1);
  clock=31000;await device.run();assert.equal(calls,1,'Five-second status polling cannot retry device proof');
  const pending=device.run({focus:true}),same=device.run({focus:true});await Promise.resolve();assert.equal(calls,2);second.resolve({status:'approved',version:7});
  assert.equal((await pending).status,'approved');await same;clock=99000;await device.run({focus:true});assert.equal(calls,2);
  const waiting=deferred();let lateCalls=0;const removed=createRecoveryDeviceAttempt(()=>{lateCalls++;return waiting.promise;},{now:()=>clock});
  const late=removed.run();await Promise.resolve();removed.dispose();waiting.resolve({status:'approved'});assert.equal(await late,null);await removed.run({focus:true});assert.equal(lateCalls,1);
});

test('rejected new devices and unsupported key formats never retry while a temporary native bridge timeout can',async()=>{
  for(const failure of [new ApiError('Invalid',422),new ApiError('Rejected device',403),null]){
    let calls=0,clock=0;const device=createRecoveryDeviceAttempt(()=>{calls++;if(failure)throw failure;return null;},{now:()=>clock});
    await device.run();clock=90000;await device.run({focus:true});assert.equal(calls,1);
  }
  let calls=0,clock=0;const device=createRecoveryDeviceAttempt(()=>{calls++;throw Object.assign(Error('No bridge'),{code:'recovery_bridge_unavailable'});},{now:()=>clock});
  await device.run();clock=31000;await device.run({focus:true});assert.equal(calls,2);
  await assert.rejects(bootstrapPasswordRecoveryDevice({},{nativeBridge:true,readIdentity:async()=>({proof_unavailable:true}),signChallenge:async()=>''}),error=>error.code==='recovery_bridge_unavailable');
});
