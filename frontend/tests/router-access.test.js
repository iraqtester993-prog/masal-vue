import test from 'node:test';
import assert from 'node:assert/strict';
import {createRouter,createMemoryHistory,NavigationFailureType,isNavigationFailure} from 'vue-router';
import {createSession} from '../src/modules/auth/session.js';
import {ApiError} from '../src/shared/api/client.js';
import {createSessionNavigationGuard,landingRoute, routeAllowed} from '../src/router/access.js';
const routes = [
  {name:'dashboard',meta:{requiresAuth:true,permission:'dashboard.view'}},
  {name:'sell',meta:{requiresAuth:true,permission:'sell.create',accountTypes:['pos']}},
  {name:'digital',meta:{requiresAuth:true,permission:'digital.view'}},
  {name:'backup',meta:{requiresAuth:true,permission:'backup.view',accountTypes:['system'],membershipKinds:['owner']}},
];
const router = {getRoutes:()=>routes};
const session = (permissions, type='pos', kind='owner') => ({state:{identity:{account:{type},membership:{kind}}},can:key=>permissions.includes(key)});
test('POS without dashboard permission opens sale and keeps dashboard blocked', () => {
  const auth=session(['sell.create']);
  assert.deepEqual(landingRoute(router,auth),{name:'sell'});
  assert.equal(routeAllowed(routes[0],auth),false);
  assert.equal(routeAllowed(routes[1],auth),true);
});
test('digital-only POS opens API services; no permissions has a stable fallback', () => {
  assert.deepEqual(landingRoute(router,session(['digital.view'])),{name:'digital'});
  assert.deepEqual(landingRoute(router,session([])),{name:'no-access'});
});
test('landing respects account type and membership restrictions', () => {
  assert.deepEqual(landingRoute(router,session(['sell.create','backup.view'],'main_agent')),{name:'no-access'});
  assert.deepEqual(landingRoute(router,session(['backup.view'],'system','employee')),{name:'no-access'});
  assert.deepEqual(landingRoute(router,session(['dashboard.view','sell.create'])),{name:'dashboard'});
});

function outageFixture(){
  const identity=permissions=>({data:{user:{id:1},account:{id:10,type:'pos'},portal:'pos',permissions}});
  let next=()=>Promise.resolve(identity(['sell.create','reports.view'])),reads=0,writes=0;
  const auth=createSession('pos',{
    me:()=>{reads++;return next();},
    csrf:async()=>{},login:async()=>identity(['sell.create','reports.view']),
    mutate:path=>{if(['/auth/device-challenge','/device/challenge'].includes(path))return {data:{challenge_id:'fixture-proof',challenge:'exact-challenge'}};if(path==='/device/heartbeat')return {data:{allowed:true}};writes++;throw Error('No business writes are allowed during navigation checks');},
  },{readIdentity:async()=>({installation_id:'00000000-0000-4000-8000-000000000001',proof_public_key:'fixture-public',proof_key_kind:'webcrypto'}),signChallenge:async()=>'fixture-signature'});
  const pages=[...routes,{name:'reports',meta:{requiresAuth:true,permission:'reports.view'}},{name:'login',meta:{}},{name:'session-unavailable',meta:{}},{name:'no-access',meta:{requiresAuth:true}}];
  const memory=createRouter({history:createMemoryHistory(),routes:pages.map(route=>({...route,path:`/${route.name}`,component:{render:()=>null}}))});
  memory.beforeEach(createSessionNavigationGuard(auth,memory));
  return {auth,memory,fail:status=>{next=()=>Promise.reject(new ApiError('Unavailable',status));},recover:permissions=>{next=()=>Promise.resolve(identity(permissions));},reads:()=>reads,writes:()=>writes};
}

test('a transient check failure cancels navigation on the current page and fresh access cannot restore a revoked sale permission',async()=>{
  const f=outageFixture();
  let pendingPath=null;
  f.memory.beforeEach((to,from)=>{pendingPath=to.path===from.path?null:to.fullPath;});
  f.memory.afterEach(to=>{if(pendingPath===to.fullPath)pendingPath=null;});
  f.memory.onError(()=>{pendingPath=null;});
  await f.auth.login('test','password');
  await f.memory.push('/sell');
  f.fail(503);
  await f.auth.refresh();
  const interrupted=await f.memory.push('/reports');
  assert.equal(isNavigationFailure(interrupted,NavigationFailureType.aborted),true);
  assert.equal(f.memory.currentRoute.value.name,'sell');
  assert.equal(pendingPath,null,'Cancelled session navigation cannot leave the current page hidden as a pending transition');
  assert.equal(f.auth.state.identity.account.id,10);
  assert.equal(f.auth.can('sell.create'),false);
  assert.equal(f.auth.state.checked,false);
  f.recover(['reports.view']);
  await f.memory.push('/dashboard');
  assert.equal(f.memory.currentRoute.value.name,'reports');
  assert.equal(f.auth.can('sell.create'),false);
  assert.equal(f.auth.can('reports.view'),true);
  assert.equal(f.writes(),0);
});

test('first-open server outage shows a retry route, and authentication expiry still opens login after connection recovers',async()=>{
  const f=outageFixture();
  f.fail(503);
  await f.memory.push('/sell');
  await f.memory.isReady();
  assert.equal(f.memory.currentRoute.value.name,'session-unavailable');
  assert.equal(f.memory.currentRoute.value.query.next,'/sell');
  assert.equal(f.auth.state.identity,null);
  assert.equal(f.auth.can('sell.create'),false);
  assert.equal(f.reads(),1,'The retry route must not loop through session checks');
  f.fail(401);
  await f.memory.push('/sell');
  assert.equal(f.memory.currentRoute.value.name,'login');
  assert.equal(f.writes(),0);
});
