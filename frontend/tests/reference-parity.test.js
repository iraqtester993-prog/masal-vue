import test from 'node:test';
import assert from 'node:assert/strict';
import { matchesPrice, priceDifference } from '../src/modules/finance/price-filters.js';
import { quickActions } from '../src/layouts/quick-actions.js';
import { localizeVueSource } from '../src/modules/preferences/ui-localization-plugin.js';

test('price filters combine face value, product, state and Baghdad effective date', () => {
  const row = { name: 'بطاقة', product_id: 4, provider_id: 2, face_value: '5000', currency: 'IQD', price: '4800.00', price_updated_at: '2026-10-06T22:00:00Z' };
  assert.equal(matchesPrice(row, { query: '5000', product: '4', provider: '2', status: 'priced', from: '2026-10-07', to: '2026-10-07' }), true);
  for (const filter of [{ product: '8' }, { status: 'unpriced' }, { to: '2026-10-06' }, { from: '2026-10-08' }, { currency: 'USD' }]) assert.equal(matchesPrice(row, filter), false);
  assert.equal(matchesPrice({ ...row, price: null, price_updated_at: null }, { status: 'unpriced' }), true);
  assert.equal(matchesPrice({ ...row, price: null, price_updated_at: null }, { from: '2026-10-01' }), false);
  assert.equal(priceDifference('10.00', null), null);
  assert.equal(priceDifference('10.00', '0.00'), '10.00');
});

test('quick actions obey hierarchy and all relevant view/write permissions', () => {
  const actions = (type, permissions) => quickActions({ account: { type, status: 'active' } }, p => permissions.includes(p)).map(a => a.id);
  assert.deepEqual(actions('system', []), []);
  assert.deepEqual(actions('pos', ['account.view', 'account.create', 'sell.create']), []);
  assert.deepEqual(actions('pos', ['sell.view', 'sell.create']), ['sell']);
  assert.deepEqual(actions('sub_branch', ['account.view', 'account.create']), ['pos']);
  assert.deepEqual(actions('main_agent', ['account.view', 'account.create']), ['agent', 'pos']);
  assert.deepEqual(actions('main_agent', ['wallets.view', 'wallets.deposit']), []);
  assert.deepEqual(actions('system', ['wallets.view', 'wallets.deposit']), ['deposit']);
});

test('dynamic UI status labels are localized while user content remains unchanged', () => {
  const result = localizeVueSource('<template><b>{{ statuses[row.status] || row.status }}</b><p>{{row.name}}</p><p>{{ticket.description}}</p><code>{{statuses[row.status]}}</code></template>');
  assert.match(result, /__masalUiTr\(statuses\[row.status\] \|\| row.status\)/);
  assert.match(result, /\{\{row.name\}\}/);
  assert.match(result, /\{\{ticket.description\}\}/);
  assert.match(result, /<code>\{\{statuses\[row.status\]\}\}<\/code>/);
});
