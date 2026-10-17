import test from 'node:test';
import assert from 'node:assert/strict';
import { isCacheableRead, readCacheKey, READ_FRESH_MS, READ_DISPLAY_MS } from '../src/shared/api/read-policy.js';

test('navigation policy allows only audited summaries, sanitized lists, and shared options', () => {
  const safe = ['/accounts', '/dashboard/summary', '/dashboard/activity', '/catalog/products', '/catalog/providers', '/catalog/preferences', '/reference/governorates', '/reports/options', '/reports/summary', '/finance/ledger', '/finance/wallets', '/finance/transfers', '/sales', '/sales/products', '/sales/summary', '/digital/history', '/digital/orders', '/digital/orders/summary', '/digital/offers', '/stock/batches', '/stock/claims', '/stock/withdrawals', '/stock/summary'];
  for (const path of safe) {
    assert.equal(isCacheableRead(path), true, path);
    assert.equal(isCacheableRead(`${path}?page=2&q=filter`), true, path);
  }
  assert.equal(READ_FRESH_MS, 30000); assert.equal(READ_DISPLAY_MS, 120000);
});

test('authentication, secrets, credentials, dynamic details, cards, exports and recovery stay live', () => {
  const live = ['/auth/me', '/auth/login', '/accounts/1', '/accounts/1/staff', '/digital/connections', '/digital/orders/recovery', '/digital/orders/id', '/digital/orders/id/receipt', '/sales/id/receipt', '/sales/id', '/stock/cards', '/stock/batches/1/cards', '/stock/batches/selection', '/stock/orders/1', '/stock/orders/1/checked', '/reports/sections/sales/rows', '/reports/export', '/sales/export', '/finance/price-template', '/catalog/products/1', '/catalog/products/export', '/catalog/products/', '//catalog/products', '/catalog/%70roducts', 'https://example.test/sales', '/sales#receipt'];
  for (const path of live) assert.equal(isCacheableRead(path), false, path);
});

test('equivalent query order shares a key while filters and duplicate parameter values stay distinct', () => {
  assert.equal(readCacheKey('/digital/offers?'), '/digital/offers');
  assert.equal(readCacheKey('/digital/offers?page=2&service=topup'), readCacheKey('/digital/offers?service=topup&page=2'));
  assert.notEqual(readCacheKey('/digital/offers?page=2'), readCacheKey('/digital/offers?page=3'));
  assert.notEqual(readCacheKey('/sales?q=a%26page%3D2'), readCacheKey('/sales?q=a&page=2'));
  assert.notEqual(readCacheKey('/sales?sort=a&sort=b'), readCacheKey('/sales?sort=b&sort=a'));
});
