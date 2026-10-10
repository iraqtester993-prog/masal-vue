export function createRoutePrefetch(router, canLoad, connection = globalThis.navigator?.connection) {
  const pending = new Map();
  return function prefetch(to) {
    if (connection?.saveData || ['slow-2g', '2g'].includes(connection?.effectiveType)) return;
    const route = router.resolve(to);
    if (!canLoad(route)) return;
    for (const record of route.matched) {
      const load = record.components?.default;
      if (typeof load !== 'function' || pending.has(load)) continue;
      pending.set(load, Promise.resolve().then(load).catch(() => { pending.delete(load); }));
    }
  };
}
