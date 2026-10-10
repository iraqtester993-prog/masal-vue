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

test('restores only the identity belonging to the requested portal', async () => {
  const session = createSession('agents', client());
  await session.refresh();
  assert.equal(session.state.identity.portal, 'agents');
  assert.equal(session.can('account.view'), true);
  assert.equal(session.can('wallet.transfer'), false);
});

test('rejects a server identity from another portal', async () => {
  const session = createSession('pos', client());
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
