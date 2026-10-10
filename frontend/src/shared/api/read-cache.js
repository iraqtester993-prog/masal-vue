// Session memory only: never persist balances or report data in browser storage.
export function createReadCache({ now = Date.now, ttl = 15000, limit = 60 } = {}) {
  const entries = new Map();
  let generation = 0;
  const clone = value => structuredClone(value);
  function clear() { generation++; entries.clear(); }
  function peek(key, maxAge = ttl) {
    const entry = entries.get(key);
    return entry?.value !== undefined && now() - entry.at < maxAge ? clone(entry.value) : null;
  }
  async function read(key, load, { signal, force = false } = {}) {
    signal?.throwIfAborted();
    if (force) entries.delete(key);
    const cached = peek(key);
    if (cached !== null) return cached;
    let entry = entries.get(key);
    if (!entry?.pending) {
      const expected = generation;
      entry = { at: 0, pending: null };
      entries.set(key, entry);
      while (entries.size > limit) entries.delete(entries.keys().next().value);
      entry.pending = Promise.resolve().then(load).then(value => {
        if (expected === generation && entries.get(key) === entry) {
          entry.value = clone(value); entry.at = now(); entry.pending = null;
        }
        return { value, expected };
      }, error => {
        if (entries.get(key) === entry) entries.delete(key);
        throw error;
      });
    }
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
  return { read, peek, clear };
}
