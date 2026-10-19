export function createNotificationCounts({ api, identity, allowed, publish, now = Date.now }) {
  let reader, revision = 0, disposed = false, lastSuccess = -Infinity, lastActor;
  const marking = new Map();
  function readPage(page) {
    const actor = identity();
    if (disposed || !actor || !allowed()) return Promise.resolve();
    if (marking.has(page)) return marking.get(page);
    const pending = api.mutate('/notifications/read-page', 'POST', {page}).then(async response => {
      if (!disposed && identity() === actor && allowed() && response?.data?.count > 0) await refresh({force:true});
    }).finally(() => { if (marking.get(page) === pending) marking.delete(page); });
    marking.set(page, pending);
    return pending;
  }
  async function refresh({ force = true } = {}) {
    if (disposed) return;
    const actor = identity();
    if (!force && actor && allowed() && actor === lastActor && now() - lastSuccess < 30000) return;
    if (!force && reader && !reader.signal.aborted) return;
    reader?.abort();
    const current = ++revision;
    if (!actor || !allowed()) { publish(null); return; }
    const controller = new AbortController();
    reader = controller;
    try {
      const response = await api.request('/notifications/summary', { signal: controller.signal });
      if (disposed || controller.signal.aborted || current !== revision || identity() !== actor || !allowed()) return;
      const data = response?.data;
      if (!Number.isSafeInteger(data?.unread) || data.unread < 0 || !data.sidebar_counts || typeof data.sidebar_counts !== 'object' || Array.isArray(data.sidebar_counts)) throw new TypeError('Invalid notification summary');
      const counts = Object.create(null);
      for (const [key, value] of Object.entries(data.sidebar_counts)) {
        if (/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(key) && Number.isSafeInteger(value) && value >= 0) counts[key] = value;
      }
      lastSuccess = now(); lastActor = actor;
      publish({ unread: data.unread, counts });
    } catch {
      if (!disposed && current === revision && !controller.signal.aborted) publish(null);
    } finally {
      if (reader === controller) reader = null;
    }
  }
  function clear() { revision += 1; reader?.abort(); reader = null; marking.clear(); lastSuccess = -Infinity; lastActor = null; publish(null); }
  function dispose() { disposed = true; clear(); }
  return { refresh, readPage, clear, dispose };
}
