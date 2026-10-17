// Only sanitized list/summary responses belong in the navigation cache.
// Receipts, card secrets, exports, authentication, and dynamic detail routes stay live.
export const READ_FRESH_MS = 30000;
export const READ_DISPLAY_MS = 120000;

const paths = new Set([
  '/dashboard/summary', '/dashboard/activity', '/accounts', '/account-options', '/permission-catalog',
  '/catalog/options', '/catalog/configuration-options', '/catalog/products', '/catalog/providers', '/catalog/preferences',
  '/reference/options', '/reference/configuration-options', '/reference/governorates', '/reference/sources', '/reference/pos-types', '/reference/representatives',
  '/reports/options', '/reports/summary',
  '/finance/options', '/finance/accounts', '/finance/wallets', '/finance/ledger', '/finance/invoices', '/finance/prices', '/finance/transfers', '/finance/recoveries', '/finance/funding-requests', '/finance/price-requests',
  '/stock/options', '/stock/configuration-options', '/stock/batches', '/stock/claims', '/stock/withdrawals', '/stock/summary',
  '/sales', '/sales/options', '/sales/configuration-options', '/sales/products', '/sales/summary',
  '/digital/options', '/digital/configuration-options', '/digital/history', '/digital/orders', '/digital/orders/summary', '/digital/offers',
  '/notifications/options', '/notifications/configuration-options', '/support/options', '/support/configuration-options',
]);

export function isCacheableRead(path) {
  return typeof path === 'string' && !path.includes('#') && paths.has(path.split('?')[0]);
}

export function readCacheKey(path) {
  const separator = path.indexOf('?');
  if (separator < 0) return path;
  const parameters = new URLSearchParams(path.slice(separator + 1));
  parameters.sort();
  const query = parameters.toString();
  return path.slice(0, separator) + (query ? `?${query}` : '');
}
