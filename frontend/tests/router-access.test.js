import test from 'node:test';
import assert from 'node:assert/strict';
import {landingRoute, routeAllowed} from '../src/router/access.js';
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
