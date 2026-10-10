export function createNotificationCounts({ api, identity, allowed, publish, now = Date.now }) {
  let reader, revision = 0, disposed = false, lastSuccess = -Infinity, lastActor;
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
  function clear() { revision += 1; reader?.abort(); reader = null; lastSuccess = -Infinity; lastActor = null; publish(null); }
  function dispose() { disposed = true; clear(); }
  return { refresh, clear, dispose };
}
