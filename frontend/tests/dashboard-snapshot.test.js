import test from 'node:test';
import assert from 'node:assert/strict';
import { createDashboardSnapshot } from '../src/shared/api/dashboard-snapshot.js';

const store = () => {
  const data = new Map();
  return { getItem: key => data.get(key) ?? null, setItem: (key, value) => data.set(key, value), removeItem: key => data.delete(key) };
};
const summary = { data: { cards: [{ key: 'topup', value: 94000 }] } };

test('refresh restores a recent display only after matching authenticated account and permissions', () => {
  const storage = store();
  const first = createDashboardSnapshot({ storage });
  first.setScope('agent14:owner:permissions1');
  first.save(summary, first.revision());
  const refresh = createDashboardSnapshot({ storage });
  assert.equal(refresh.peek(), null);
  refresh.setScope('agent14:owner:permissions1');
  assert.deepEqual(refresh.peek(), summary);
  refresh.setScope('agent14:owner:permissions2');
  assert.equal(refresh.peek(), null);
  first.setScope('agent14:owner:permissions1');
  assert.equal(first.peek(), null);
});

test('snapshots expire after two minutes and cannot cross accounts', () => {
  const storage = store(); let time = 100;
  const cache = createDashboardSnapshot({ storage, now: () => time });
  cache.setScope('pos1'); cache.save(summary, cache.revision());
  time += 120000; assert.equal(cache.peek(), null);
  cache.save(summary, cache.revision());
  cache.setScope('pos2'); assert.equal(cache.peek(), null);
});

test('writes/logout invalidate snapshots and a pending old read cannot restore them', () => {
  const storage = store(), cache = createDashboardSnapshot({ storage });
  cache.setScope('admin'); const revision = cache.revision();
  cache.save(summary, revision); cache.clear();
  cache.save(summary, revision); assert.equal(cache.peek(), null);
  cache.save(summary, cache.revision());
  cache.setScope('agent'); cache.save(summary, revision);
  assert.equal(cache.peek(), null);
});

test('blocked or corrupted browser storage does not prevent live dashboard loading', () => {
  const blocked = createDashboardSnapshot({ storage: { getItem() { throw Error('blocked'); }, setItem() { throw Error('blocked'); }, removeItem() { throw Error('blocked'); } } });
  blocked.setScope('agent'); blocked.save(summary, blocked.revision());
  assert.equal(blocked.peek(), null); blocked.clear();
  const storage = store(); storage.setItem('masal.dashboard.display.v1', '{broken');
  const corrupt = createDashboardSnapshot({ storage }); corrupt.setScope('agent');
  assert.equal(corrupt.peek(), null);
});
