import test from 'node:test';
import assert from 'node:assert/strict';
import {createNotificationsApi} from '../src/modules/notifications/notifications-api.js';
import {movementKinds} from '../src/modules/finance/finance-model.js';

test('reading visible notifications preserves ids and the abort signal', async () => {
  const controller = new AbortController();
  let received;
  const api = createNotificationsApi({mutate: async (...args) => {
    received = args;
    args[3]?.addEventListener('abort', () => {});
    return {unread: 0};
  }});
  await api.readPage('notifications', [12, 13], controller.signal);
  assert.deepEqual(received.slice(0, 3), ['/notifications/read-page', 'POST', {page: 'notifications', ids: [12, 13]}]);
  assert.equal(received[3], controller.signal);
});

test('wallet sales and stock revaluation have Arabic labels', () => {
  assert.equal(movementKinds.sale, 'بيع');
  assert.equal(movementKinds['stock-revaluation'], 'إعادة تقييم المخزون');
});
