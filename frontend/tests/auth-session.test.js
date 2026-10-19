import test from 'node:test';
import assert from 'node:assert/strict';
import { createSession } from '../src/modules/auth/session.js';
import { ApiError } from '../src/shared/api/client.js';

const identity = (portal = 'agents', userId = 1) => ({ data: {
  user: { id: userId, name: 'Test user', login: 'test' },
  account: { id: 10, name: 'Test account', type: 'main_agent' },
  portal, permissions: ['account.view'],
} });
const client = (overrides = {}) => ({
  csrf: async () => null,
  me: async () => identity(),
  login: async () => identity(),
  logout: async () => null,
  ...overrides,
});

test('a capability-only scope revocation invalidates previous page and read snapshots without logging out',async()=>{
 let clearCount=0,scopes=[],allowed=true;
 const current=()=>({data:{...identity().data,capabilities:{system_scope:allowed,management_account_id:allowed?10:44}}});
 const session=createSession('agents',client({login:async()=>current(),me:async()=>current(),clearReadCache:()=>clearCount++,setReadScope:value=>scopes.push(value)}));
 await session.login('test','secret');const previousScope=scopes.at(-1),priorClears=clearCount;
 await session.refresh({background:true});assert.equal(clearCount,priorClears);assert.equal(scopes.at(-1),previousScope);
 allowed=false;await session.refresh({background:true});assert.equal(clearCount,priorClears+1);assert.notEqual(scopes.at(-1),previousScope);assert.equal(session.state.identity.user.id,1);assert.equal(session.state.identity.capabilities.system_scope,false);
});

test('background identity refresh preserves the visible page and permissions until the server responds',async()=>{
 let finish;
 const session=createSession('agents',client({me:()=>new Promise(resolve=>finish=resolve)}));
 await session.login('test','password');const previous=session.state.identity,pending=session.refresh({background:true});
 assert.equal(session.state.identity,previous);assert.equal(session.state.checked,true);assert.equal(session.can('account.view'),true);
 finish({...identity(),data:{...identity().data,user:{...identity().data.user,name:'Updated'}}});await pending;
 assert.equal(session.state.identity.user.name,'Updated');assert.equal(session.state.checking,false);
});

test('a background network failure preserves data but a revoked identity is cleared',async()=>{
 let failure=new ApiError('Temporary outage',503);
 const session=createSession('agents',client({me:async()=>{throw failure;}}));
 await session.login('test','password');await session.refresh({background:true});
 assert.equal(session.state.checked,true);assert.equal(session.can('account.view'),true);assert.equal(session.state.error,'Temporary outage');
 failure=new ApiError('Account disabled',403);await session.refresh({background:true});
 assert.equal(session.state.identity,null);assert.equal(session.can('account.view'),false);
});

test('a foreground check joining a background refresh still fails closed on transient failure',async()=>{
 let fail;
 const session=createSession('agents',client({me:()=>new Promise((_,reject)=>fail=reject)}));
 await session.login('test','password');const background=session.refresh({background:true}),foreground=session.refresh();
 assert.equal(session.can('account.view'),false);fail(new ApiError('Unavailable',503));await Promise.all([background,foreground]);
 assert.equal(session.state.checked,false);assert.equal(session.can('account.view'),false);
});

test('restores only the identity belonging to the requested portal', async () => {
  const session = createSession('agents', client());
  await session.refresh();
  assert.equal(session.state.identity.portal, 'agents');
  assert.equal(session.can('account.view'), true);
  assert.equal(session.can('wallet.transfer'), false);
});

test('rejects a server identity from another portal', async () => {
  const session = createSession('pos', client({mutate:async path=>({data:path.endsWith('/challenge')?{challenge_id:'proof',challenge:'exact-challenge'}:{allowed:true}})}),{readIdentity:async()=>({installation_id:'00000000-0000-4000-8000-000000000001',proof_public_key:'public',proof_key_kind:'webcrypto'}),signChallenge:async()=>'signature'});
  await session.refresh();
  assert.equal(session.state.identity, null);
  assert.equal(session.state.checked, true);
  assert.ok(session.state.error);
});

test('401 clears identity and does not expose a server error as login failure', async () => {
  const session = createSession('agents', client({ me: async () => { throw new ApiError('Unauthenticated', 401); } }));
  await session.refresh();
  assert.equal(session.state.identity, null);
  assert.equal(session.state.error, '');
});

test('initial concurrent session checks share one request', async () => {
  let count = 0;
  let resolve;
  const session = createSession('agents', client({ me: () => { count += 1; return new Promise((done) => { resolve = done; }); } }));
  const first = session.refresh();
  const second = session.refresh();
  resolve(identity());
  await Promise.all([first, second]);
  assert.equal(count, 1);
});

test('obtains CSRF before submitting login credentials', async () => {
  const events = [];
  const session = createSession('agents', client({
    csrf: async () => { events.push('csrf'); },
    login: async (credentials) => { events.push(credentials); return identity(); },
  }));
  await session.login('test', 'password');
  assert.deepEqual(events, ['csrf', { login: 'test', password: 'password' }]);
  assert.equal(session.state.busy, false);
});

test('a late /me response cannot restore a logged-out session', async () => {
  let resolve;
  const session = createSession('agents', client({ me: () => new Promise((done) => { resolve = done; }) }));
  await session.login('test', 'password');
  const pending = session.refresh();
  await session.logout();
  resolve(identity());
  await pending;
  assert.equal(session.state.identity, null);
});

test('an old session response cannot replace a new login', async () => {
  let resolve;
  const session = createSession('agents', client({
    me: () => new Promise((done) => { resolve = done; }),
    login: async () => identity('agents', 2),
  }));
  const pending = session.refresh();
  await session.login('new-user', 'password');
  resolve(identity('agents', 1));
  await pending;
  assert.equal(session.state.identity.user.id, 2);
});

test('failed logout keeps the session visible so the user can retry', async () => {
  const session = createSession('agents', client({ logout: async () => { throw new ApiError('Network failure'); } }));
  await session.login('test', 'password');
  await assert.rejects(session.logout());
  assert.equal(session.state.identity.user.id, 1);
  assert.equal(session.state.busy, false);
});

test('expired logout uses a new check instead of an old pending /me request', async () => {
  let resolveOld;
  let checks = 0;
  const session = createSession('agents', client({
    me: () => {
      checks += 1;
      if (checks === 1) return new Promise((done) => { resolveOld = done; });
      return Promise.reject(new ApiError('Unauthenticated', 401));
    },
    logout: async () => { throw new ApiError('CSRF expired', 419); },
  }));
  await session.login('test', 'password');
  const pending = session.refresh();
  await session.logout();
  assert.equal(checks, 2);
  resolveOld(identity());
  await pending;
  assert.equal(session.state.identity, null);
});

test('revoking account access clears a previously authenticated identity', async () => {
  const session = createSession('agents', client({ me: async () => { throw new ApiError('Account disabled', 403); } }));
  await session.login('test', 'password');
  await session.refresh();
  assert.equal(session.state.identity, null);
  assert.ok(session.state.error);
});

test('transient server and network failures retain the account caption without granting any cached permission', async () => {
  for (const failure of [new ApiError('Unavailable', 503), new ApiError('Connection interrupted')]) {
    let clears = 0;
    const session = createSession('agents', client({
      me: async () => { throw failure; },
      clearReadCache: () => { clears += 1; },
    }));
    await session.login('test', 'password');
    assert.equal(session.can('account.view'), true);
    await session.refresh();
    assert.equal(session.state.identity.user.id, 1);
    assert.equal(session.state.checked, false);
    assert.equal(session.state.accessConfirmed, false);
    assert.equal(session.can('account.view'), false);
    assert.equal(session.can('sell.create'), false);
    assert.equal(session.state.error, failure.message);
    assert.equal(clears, 1);
  }
});

test('a fresh identity check restores only server-confirmed permissions after a transient failure', async () => {
  let checks = 0, finish;
  const session = createSession('agents', client({
    me: () => ++checks === 1
      ? Promise.reject(new ApiError('Unavailable', 503))
      : new Promise(resolve => { finish = resolve; }),
  }));
  await session.login('test', 'password');
  await session.refresh();
  const checking = session.refresh();
  assert.equal(session.can('account.view'), false);
  const changed = identity();
  changed.data.permissions = ['reports.view'];
  finish(changed);
  await checking;
  assert.equal(session.state.checked, true);
  assert.equal(session.state.accessConfirmed, true);
  assert.equal(session.state.error, '');
  assert.equal(session.can('account.view'), false);
  assert.equal(session.can('reports.view'), true);
  assert.equal(session.can('sell.create'), false);
});

test('authentication expiry after a transient outage still clears the retained identity', async () => {
  let checks = 0;
  const session = createSession('agents', client({
    me: async () => { throw new ApiError(checks++ ? 'Unauthenticated' : 'Unavailable', checks > 1 ? 401 : 503); },
  }));
  await session.login('test', 'password');
  await session.refresh();
  assert.equal(session.state.identity.user.id, 1);
  await session.refresh();
  assert.equal(session.state.identity, null);
  assert.equal(session.state.accessConfirmed, false);
  assert.equal(session.can('account.view'), false);
});

test('logout clears identity when the server rejects revoked account access', async () => {
  const session = createSession('agents', client({ logout: async () => { throw new ApiError('Account disabled', 403); } }));
  await session.login('test', 'password');
  await session.logout();
  assert.equal(session.state.identity, null);
  assert.equal(session.state.busy, false);
});
test('a pending session check cannot overwrite the owner name returned by an update', async () => {
  let resolveOld;
  const changed=identity();changed.data.user.name='Changed name';
  const session=createSession('agents',client({me:()=>new Promise((resolve)=>{resolveOld=resolve;}),updateOwnProfile:async(payload)=>{assert.deepEqual(payload,{name:'Changed name',version:4});return changed;}}));
  await session.login('test','password');const pending=session.refresh();
  await session.updateProfile('Changed name',4);resolveOld(identity());await pending;
  assert.equal(session.state.identity.user.name,'Changed name');
});
test('a late owner profile update cannot restore identity after logout', async () => {
  let resolveUpdate;
  const session=createSession('agents',client({updateOwnProfile:()=>new Promise((resolve)=>{resolveUpdate=resolve;})}));
  await session.login('test','password');const pending=session.updateProfile('Changed name',4);
  await session.logout();resolveUpdate(identity());await pending;
  assert.equal(session.state.identity,null);
});
