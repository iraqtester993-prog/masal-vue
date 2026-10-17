// A short-lived display snapshot, never a source for financial operations.
export function createDashboardSnapshot({ storage, now = Date.now, ttl = 120000, key = 'masal.dashboard.display.v1' } = {}) {
  let scope = '', generation = 0;
  const remove = () => { try { storage?.removeItem(key); } catch {} };
  function clear() { generation++; remove(); }
  function setScope(next) {
    if (scope !== next) { generation++; scope = next; }
    try {
      const saved = JSON.parse(storage?.getItem(key) || 'null');
      if (saved && saved.scope !== scope) remove();
    } catch { remove(); }
  }
  function peek(maxAge = ttl) {
    if (!scope) return null;
    try {
      const saved = JSON.parse(storage?.getItem(key) || 'null');
      if (!saved || saved.scope !== scope || now() - saved.at >= Math.min(maxAge, ttl) || saved.at > now() || !Array.isArray(saved.value?.data?.cards)) return null;
      return saved.value;
    } catch { return null; }
  }
  function save(value, expected) {
    if (!scope || expected !== generation || !Array.isArray(value?.data?.cards)) return;
    try { storage?.setItem(key, JSON.stringify({ scope, at: now(), value })); } catch {}
  }
  return { clear, setScope, peek, save, revision: () => generation };
}
