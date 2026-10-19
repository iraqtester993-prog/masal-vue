import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
import * as Vue from 'vue';
import {parse,compileScript} from '@vue/compiler-sfc';
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

test('password reset polling waits while authentication or another mutation is being saved',async()=>{
  let busy=true,mutation=false,reads=0;
  const session={state:{identity:identity().data,get busy(){return busy;}},api:{hasPendingMutation:()=>mutation,passwordResetStatus:async()=>{reads++;return {data:{required:false,version:0}};}},applyPasswordResetStatus:async()=>{}};
  const monitor=createPasswordResetMonitor(session,{surface:new EventTarget(),document:Object.assign(new EventTarget(),{visibilityState:'visible'}),setInterval:()=>0,clearInterval:()=>{}});
  try{
    await monitor.refresh();assert.equal(reads,0);
    busy=false;mutation=true;await monitor.refresh();assert.equal(reads,0);
    mutation=false;await monitor.refresh();assert.equal(reads,1);
  }finally{monitor.dispose();}
});

test('a status response or authorization failure arriving during password confirmation cannot interrupt the save',async()=>{
  for(const status of [200,401,403]){
    let resolveStatus,rejectStatus,identityReads=0;
    const checked=new Promise((resolve,reject)=>{resolveStatus=resolve;rejectStatus=reject;}),saved=deferred();
    const auth=createSession('agents',client({login:async()=>identity(true,5),passwordResetStatus:()=>checked,completePasswordReset:()=>saved.promise,me:async()=>{identityReads++;throw new ApiError('Synthetic authorization failure',status===200?401:status);}}));
    await auth.login('agent','secret');
    const monitor=createPasswordResetMonitor(auth,{surface:new EventTarget(),document:Object.assign(new EventTarget(),{visibilityState:'visible'}),setInterval:()=>0,clearInterval:()=>{}});
    try{
      const polling=monitor.refresh(),saving=auth.completePasswordReset('newpass123','newpass123',5);
      if(status===200)resolveStatus({data:{required:true,version:6}});else rejectStatus(new ApiError('Synthetic authorization failure',status));
      await polling;
      assert.equal(identityReads,0,`Status ${status} must not start a competing identity refresh`);
      assert.equal(auth.state.identity.password_reset.version,5);assert.equal(auth.state.busy,true);
      saved.resolve(identity(false,6));await saving;
      assert.equal(auth.state.identity.user.id,1);assert.equal(auth.state.identity.password_reset.required,false);assert.equal(auth.state.identity.password_reset.version,6);assert.equal(auth.can('dashboard.view'),true);
    }finally{monitor.dispose();}
  }
});

test('a status check already in flight also waits when an unrelated mutation starts before its response',async()=>{
  let mutation=false,applied=0,refreshed=0,finish;
  const session={state:{identity:identity().data,busy:false},api:{hasPendingMutation:()=>mutation,passwordResetStatus:()=>new Promise(resolve=>finish=resolve)},applyPasswordResetStatus:async()=>{applied++;},refresh:async()=>{refreshed++;}};
  const monitor=createPasswordResetMonitor(session,{surface:new EventTarget(),document:Object.assign(new EventTarget(),{visibilityState:'visible'}),setInterval:()=>0,clearInterval:()=>{}});
  try{
    const checking=monitor.refresh();mutation=true;finish({data:{required:true,version:7}});await checking;
    assert.equal(applied,0);assert.equal(refreshed,0);
    mutation=false;const resumed=monitor.refresh();finish({data:{required:true,version:7}});await resumed;assert.equal(applied,1);
  }finally{monitor.dispose();}
});

test('an old authorization failure after password save succeeds cannot clear the replacement session',async()=>{
  for(const status of [401,403]){
    let rejectStatus,identityReads=0;
    const checked=new Promise((_,reject)=>rejectStatus=reject),saved=deferred();
    const auth=createSession('agents',client({login:async()=>identity(true,5),passwordResetStatus:()=>checked,completePasswordReset:()=>saved.promise,me:async()=>{identityReads++;throw new ApiError('Synthetic expired session',status);}}));
    await auth.login('agent','secret');
    const monitor=createPasswordResetMonitor(auth,{surface:new EventTarget(),document:Object.assign(new EventTarget(),{visibilityState:'visible'}),setInterval:()=>0,clearInterval:()=>{}});
    try{
      const polling=monitor.refresh(),saving=auth.completePasswordReset('newpass123','newpass123',5);
      saved.resolve(identity(false,6));await saving;
      assert.equal(auth.state.busy,false);assert.equal(auth.state.identity.password_reset.version,6);
      rejectStatus(new ApiError('Synthetic expired session',status));await polling;
      assert.equal(identityReads,0);assert.equal(auth.state.identity.user.id,1);assert.equal(auth.state.identity.password_reset.required,false);assert.equal(auth.state.identity.password_reset.version,6);assert.equal(auth.can('dashboard.view'),true);
    }finally{monitor.dispose();}
  }
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

async function recoverySurface(status,trusted,options={}){
  const source=await readFile(new URL('../src/modules/auth/ManagerRecovery.vue',import.meta.url),'utf8');
  const parsed=parse(source);assert.deepEqual(parsed.errors,[]);
  let compiled=compileScript(parsed.descriptor,{id:'managed-recovery-fixture',inlineTemplate:true}).content;
  compiled=compiled.replace(/^import\s+{([^}]+)}\s+from\s+['"]vue['"];?/gm,(_,names)=>`const {${names.replace(/\s+as\s+/g,':')}}=Vue;`).replace(/^import .*;?\r?$/gm,'').replace('export default','globalThis.Component=');
  let clock=0,nextTimer=0;const timers=new Map();
  const calls=[],session={api:{
    passwordRecoveryStatus:async()=>({data:{status,trusted,request_label:'CURRENT-DEVICE',version:7}}),
    requestPasswordRecovery:async payload=>{calls.push({...payload});return {data:{status:'pending',trusted:true,request_label:'NEW-DEVICE',version:8}};},
    ...options.api,
  }};
  const AccountModal={setup(_,context){return()=>Vue.h('section',context.slots.default?.());}};
  const FormField={props:['name','label','modelValue'],emits:['update:modelValue'],setup(props,{emit}){return()=>Vue.h('label',[props.label,Vue.h('input',{name:props.name,value:props.modelValue,onInput:event=>emit('update:modelValue',event.target.value)})]);}};
  const context={Vue,AbortController,Date:class extends Date{static now(){return clock;}},usePortal:()=>({session,portal:{id:'agents'}}),AccountModal,FormField,PhoneRecovery:{render:()=>null},bootstrapPasswordRecoveryDevice,createRecoveryDeviceAttempt,newPasswordError,window:new EventTarget(),document:Object.assign(new EventTarget(),{visibilityState:'visible'}),setInterval:(callback,delay)=>{timers.set(++nextTimer,{callback,delay});return nextTimer;},clearInterval:id=>timers.delete(id)};
  runInNewContext(compiled,context);
  const node=(tag,text='')=>({tag,text,props:{},children:[],parent:null});
  const renderer=Vue.createRenderer({
    createElement:tag=>node(tag),createText:text=>node('#text',text),createComment:text=>node('#comment',text),
    setText:(element,text)=>element.text=text,setElementText:(element,text)=>{element.text=text;element.children=[];},
    parentNode:element=>element.parent,nextSibling:element=>element.parent?.children[element.parent.children.indexOf(element)+1]||null,
    patchProp:(element,key,previous,next)=>element.props[key]=next,
    insert:(element,parent,anchor=null)=>{if(element.parent)element.parent.children.splice(element.parent.children.indexOf(element),1);element.parent=parent;const index=anchor?parent.children.indexOf(anchor):-1;if(index<0)parent.children.push(element);else parent.children.splice(index,0,element);},
    remove:element=>{if(element.parent)element.parent.children.splice(element.parent.children.indexOf(element),1);element.parent=null;},
  });
  const root=node('root'),app=renderer.createApp(context.Component,{open:true,login:'agent-fixture'});
  app.mount(root);await new Promise(setImmediate);await Vue.nextTick();
  const all=element=>[element,...element.children.flatMap(all)],text=element=>element.text+element.children.map(text).join('');
  const flush=async()=>{await new Promise(setImmediate);await Vue.nextTick();};
  return {app,root,calls,all:()=>all(root),text:()=>text(root),timers,
    advance:async milliseconds=>{clock+=milliseconds;for(const timer of [...timers.values()])if(timer.delay===1000)timer.callback();await flush();},
    poll:async()=>{for(const timer of [...timers.values()])if(timer.delay===5000)await timer.callback();await flush();},
    focus:async()=>{context.window.dispatchEvent(new Event('focus'));await flush();},
  };
}

test('known pending recovery shows its device request reference and completed or expired requests can be renewed',async()=>{
  for(const trusted of [false,true]){
    const page=await recoverySurface('pending',trusted);
    try{assert.ok(page.text().includes('CURRENT-DEVICE'));assert.ok(page.text().includes('مرجع الطلب'));assert.equal(page.all().some(node=>node.tag==='form'),false);assert.equal(page.calls.length,0);}
    finally{page.app.unmount();}
  }
  for(const status of ['completed','expired']){
    const page=await recoverySurface(status,true);
    try{
      assert.ok(page.text().includes(status==='completed'?'انتهى طلب الاستعادة السابق لهذا الجهاز.':'انتهت صلاحية الطلب.'));
      assert.equal(page.text().includes('انتظر موافقة المسؤول'),false);
      assert.ok(page.all().some(node=>node.tag==='input'&&node.props.name==='recovery-login'));
      const form=page.all().find(node=>node.tag==='form');assert.ok(form);assert.equal(page.calls.length,0);
      await form.props.onSubmit({preventDefault(){}});await Vue.nextTick();
      assert.deepEqual(page.calls,[{login:'agent-fixture'}]);assert.ok(page.text().includes('NEW-DEVICE'));
    }finally{page.app.unmount();}
  }
});

test('recovery request 429 honors server retry seconds and cannot resubmit through a disabled or stale form',async()=>{
  let writes=0,reads=0;
  const page=await recoverySurface('none',false,{api:{
    passwordRecoveryStatus:async()=>{reads++;return {data:{status:'none'}};},
    requestPasswordRecovery:async()=>{writes++;if(writes===1)throw Object.assign(new ApiError('Too Many Attempts.',429),{retryAfter:3});return {data:{status:'pending',request_label:'RETRIED-DEVICE',version:8}};},
  }});
  try{
    const submit=page.all().find(node=>node.tag==='form').props.onSubmit;
    await submit({preventDefault(){}});await Vue.nextTick();
    assert.equal(writes,1);assert.ok(page.text().includes('محاولات كثيرة. يمكنك المحاولة بعد 3 ثانية.'));assert.equal(page.text().includes('Too Many Attempts'),false);
    assert.equal(page.all().find(node=>node.tag==='button'&&node.props.class==='btn primary').props.disabled,true);
    await submit({preventDefault(){}});await page.poll();await page.focus();assert.equal(writes,1);assert.equal(reads,1);
    await page.advance(2999);await submit({preventDefault(){}});assert.equal(writes,1);
    await page.advance(1);assert.equal(writes,1,'Expiry must not automatically repeat the request');
    assert.equal(page.all().find(node=>node.tag==='button'&&node.props.class==='btn primary').props.disabled,false);
    assert.equal([...page.timers.values()].some(timer=>timer.delay===1000),false);
    await submit({preventDefault(){}});await Vue.nextTick();assert.equal(writes,2);assert.ok(page.text().includes('RETRIED-DEVICE'));
  }finally{page.app.unmount();assert.equal(page.timers.size,0);}
});

test('a throttled status poll pauses timer and focus reads and recovery POST until the server deadline',async()=>{
  let reads=0,writes=0;
  const page=await recoverySurface('none',false,{api:{
    passwordRecoveryStatus:async()=>{reads++;if(reads===1)throw Object.assign(new ApiError('Too Many Attempts.',429),{retryAfter:4});return {data:{status:'pending',trusted:true,request_label:'RESUMED-DEVICE',version:7}};},
    requestPasswordRecovery:async()=>{writes++;throw Error('Request must wait for status cooldown');},
  }});
  try{
    assert.equal(reads,1);assert.ok(page.text().includes('محاولات كثيرة. يمكنك المحاولة بعد 4 ثانية.'));
    const submit=page.all().find(node=>node.tag==='form').props.onSubmit;
    await page.poll();await page.focus();await submit({preventDefault(){}});assert.equal(reads,1);assert.equal(writes,0);
    await page.advance(3999);await page.poll();assert.equal(reads,1);
    await page.advance(1);await page.poll();assert.equal(reads,2);assert.equal(writes,0);assert.ok(page.text().includes('RESUMED-DEVICE'));assert.equal(page.text().includes('محاولات كثيرة'),false);
  }finally{page.app.unmount();assert.equal(page.timers.size,0);}
});

test('missing retry metadata uses a sixty-second local pause and unmount clears its countdown',async()=>{
  let writes=0;
  const page=await recoverySurface('none',false,{api:{requestPasswordRecovery:async()=>{writes++;throw new ApiError('Too Many Attempts.',429);}}});
  try{
    const submit=page.all().find(node=>node.tag==='form').props.onSubmit;
    await submit({preventDefault(){}});await Vue.nextTick();assert.ok(page.text().includes('60 ثانية.'));
    await page.advance(59999);await submit({preventDefault(){}});assert.equal(writes,1);assert.equal(page.all().find(node=>node.tag==='button'&&node.props.class==='btn primary').props.disabled,true);
    await page.advance(1);assert.equal(page.all().find(node=>node.tag==='button'&&node.props.class==='btn primary').props.disabled,false);assert.equal(writes,1);
    await submit({preventDefault(){}});await Vue.nextTick();assert.equal(writes,2);assert.ok([...page.timers.values()].some(timer=>timer.delay===1000));
  }finally{page.app.unmount();assert.equal(page.timers.size,0);}
});

test('a zero-second server retry uses only a one-second pause without repeating the request',async()=>{
  let writes=0;
  const page=await recoverySurface('none',false,{api:{requestPasswordRecovery:async()=>{writes++;throw Object.assign(new ApiError('Too Many Attempts.',429),{retryAfter:0});}}});
  try{
    const submit=page.all().find(node=>node.tag==='form').props.onSubmit;
    await submit({preventDefault(){}});await Vue.nextTick();assert.ok(page.text().includes('بعد 1 ثانية.'));
    await page.advance(999);await submit({preventDefault(){}});assert.equal(writes,1);assert.equal(page.all().find(node=>node.tag==='button'&&node.props.class==='btn primary').props.disabled,true);
    await page.advance(1);assert.equal(writes,1);assert.equal(page.all().find(node=>node.tag==='button'&&node.props.class==='btn primary').props.disabled,false);
    assert.equal([...page.timers.values()].some(timer=>timer.delay===1000),false);
  }finally{page.app.unmount();assert.equal(page.timers.size,0);}
});

test('an old throttled status response cannot delay a newer successful recovery request',async()=>{
  let rejectOld,reads=0,writes=0;
  const page=await recoverySurface('none',false,{api:{
    passwordRecoveryStatus:()=>{reads++;if(reads===1)return new Promise((resolve,reject)=>{rejectOld=reject;});return Promise.resolve({data:{status:'pending',trusted:true,request_label:'LATEST-DEVICE',version:8}});},
    requestPasswordRecovery:async()=>{writes++;return {data:{status:'pending',trusted:true,request_label:'LATEST-DEVICE',version:8}};},
  }});
  try{
    await page.all().find(node=>node.tag==='form').props.onSubmit({preventDefault(){}});await Vue.nextTick();assert.equal(writes,1);assert.ok(page.text().includes('LATEST-DEVICE'));
    rejectOld(Object.assign(new ApiError('Too Many Attempts.',429),{retryAfter:120}));await new Promise(setImmediate);await Vue.nextTick();
    assert.ok(page.text().includes('LATEST-DEVICE'));assert.equal(page.text().includes('محاولات كثيرة'),false);assert.equal(page.text().includes('Too Many Attempts'),false);
    assert.equal([...page.timers.values()].some(timer=>timer.delay===1000),false);
    await page.poll();assert.equal(reads,2);assert.equal(writes,1);assert.ok(page.text().includes('LATEST-DEVICE'));
  }finally{page.app.unmount();assert.equal(page.timers.size,0);}
});
