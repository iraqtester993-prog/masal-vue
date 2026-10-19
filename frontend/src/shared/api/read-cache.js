// Session memory only: never persist balances or report data in browser storage.
export function createReadCache({ now = Date.now, ttl = 15000, limit = 60 } = {}) {
  const entries = new Map();
  let generation = 0;
  const clone = value => structuredClone(value);
  const superseded = () => new DOMException('A newer read replaced this request', 'AbortError');
  function clear() { generation++; entries.clear(); }
  function touch(key, entry) { entries.delete(key); entries.set(key, entry); }
  function has(key, maxAge = ttl) {
    const entry = entries.get(key), age = entry ? now() - entry.at : -1;
    return !entry?.refreshing && !entry?.failed && entry?.value !== undefined && age >= 0 && age < maxAge;
  }
  function peek(key, maxAge = ttl) {
    const entry = entries.get(key);
    const age = entry ? now() - entry.at : -1;
    if (entry?.value === undefined || age < 0 || age >= maxAge) return null;
    touch(key, entry);
    return clone(entry.value);
  }
  async function read(key, load, { signal, force = false } = {}) {
    signal?.throwIfAborted();
    const previous = entries.get(key);
    if (force && previous?.pending) previous.superseded = true;
    const cached = !force && has(key) ? peek(key) : null;
    if (cached !== null) return cached;
    let entry = entries.get(key);
    if (force || !entry?.pending) {
      const expected = generation;
      // Keep the previous display during revalidation, without serving it as fresh.
      entry = { at: entry?.at ?? 0, value: entry?.value, pending: null, refreshing: true, failed: false };
      touch(key, entry);
      while (entries.size > limit) entries.delete(entries.keys().next().value);
      entry.pending = Promise.resolve().then(load).then(value => {
        if (entry.superseded) throw superseded();
        if (expected === generation && entries.get(key) === entry) {
          entry.value = clone(value); entry.at = now(); entry.pending = null; entry.refreshing = false;
        }
        return { value, expected };
      }, error => {
        if (entry.superseded) throw superseded();
        if (entries.get(key) === entry) {
          const transient = !error.status || error.status >= 500 || [408, 429].includes(error.status);
          if (entry.value === undefined || !transient) entries.delete(key);
          else { entry.pending = null; entry.refreshing = false; entry.failed = true; }
        }
        throw error;
      });
    }
    touch(key, entry);
    // Leaving one page must not cancel another page's shared request.
    let abort;
    const interrupted = new Promise((_, reject) => {
      abort = () => reject(signal.reason ?? new DOMException('Aborted', 'AbortError'));
      signal?.addEventListener('abort', abort, { once: true });
    });
    try {
      const result = await Promise.race([entry.pending, interrupted]);
      signal?.throwIfAborted();
      if (result.expected !== generation) return read(key, load, { signal });
      return clone(result.value);
    } finally { signal?.removeEventListener('abort', abort); }
  }
  return { read, peek, has, clear };
}
