import test from 'node:test';
import assert from 'node:assert/strict';
import {createIdentityMonitor} from '../src/modules/auth/identity-monitor.js';
import {createSession} from '../src/modules/auth/session.js';

const identity = color => ({data:{portal:'agents',user:{id:1},account:{id:2,type:'main_agent'},membership:{kind:'owner'},permissions:['dashboard.view'],brand:{color}}});
function environment() {
  const events=new Map(), listeners=new Map();let tick,cleared=false;
  const document={visibilityState:'visible',addEventListener:(name,fn)=>events.set(name,fn),removeEventListener:name=>events.delete(name)};
  return {document,events,listeners,get tick(){return tick;},get cleared(){return cleared;},surface:{addEventListener:(name,fn)=>listeners.set(name,fn),removeEventListener:name=>listeners.delete(name)},setInterval(fn,delay){assert.equal(delay,5000);tick=fn;return 42;},clearInterval(id){assert.equal(id,42);cleared=true;}};
}

test('an open session receives an agent color update without navigation or a second login',async()=>{
  let color='#123456',logins=0,reads=0;
  const auth=createSession('agents',{csrf:async()=>{},login:async()=>{logins++;return identity(color);},me:async()=>{reads++;return identity(color);}});
  await auth.login('agent','password');
  const env=environment(),monitor=createIdentityMonitor(auth,env);
  color='#556677';await env.tick();
  assert.equal(auth.state.identity.brand.color,color);assert.equal(logins,1);assert.equal(reads,1);assert.equal(auth.can('dashboard.view'),true);
  color='#334455';await env.listeners.get('focus')();assert.equal(auth.state.identity.brand.color,color);
  monitor.dispose();assert.equal(env.cleared,true);assert.equal(env.events.size,0);assert.equal(env.listeners.size,0);
});

test('appearance polling skips hidden, saving and logged-out sessions and coalesces slow checks',async()=>{
  const env=environment();let calls=0,resolve;
  const auth={state:{identity:{user:{id:1}},busy:false},api:{hasPendingMutation:()=>false},refresh:async options=>{assert.deepEqual(options,{background:true});calls++;return new Promise(done=>resolve=done);}};
  const monitor=createIdentityMonitor(auth,env);
  env.document.visibilityState='hidden';await monitor.refresh();assert.equal(calls,0);
  env.document.visibilityState='visible';auth.state.busy=true;await monitor.refresh();assert.equal(calls,0);
  auth.state.busy=false;auth.api.hasPendingMutation=()=>true;await monitor.refresh();assert.equal(calls,0);
  auth.api.hasPendingMutation=()=>false;const first=monitor.refresh(),second=monitor.refresh();assert.equal(calls,1);resolve();await Promise.all([first,second]);
  auth.state.identity=null;await monitor.refresh();assert.equal(calls,1);
  monitor.dispose();await monitor.refresh();assert.equal(calls,1);
});

test('a delayed identity refresh cannot restore a logged-out account',async()=>{
  let resolve;
  const auth=createSession('agents',{csrf:async()=>{},login:async()=>identity('#123456'),me:()=>new Promise(done=>resolve=done)});
  await auth.login('agent','password');const monitor=createIdentityMonitor(auth,environment());
  const pending=monitor.refresh();auth.clear();resolve(identity('#112233'));await pending;
  assert.equal(auth.state.identity,null);monitor.dispose();
});
