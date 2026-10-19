export function createIdentityMonitor(session, environment = {}) {
  const surface = environment.surface ?? (typeof window === 'undefined' ? null : window);
  const document = environment.document ?? surface?.document;
  const setTimer = environment.setInterval ?? globalThis.setInterval;
  const clearTimer = environment.clearInterval ?? globalThis.clearInterval;
  let disposed = false, pending = null;
  async function refresh() {
    if (disposed || !session.state.identity || session.state.busy || document?.visibilityState === 'hidden' || session.api.hasPendingMutation?.()) return;
    if (pending) return pending;
    pending = (async () => {
      try { await session.refresh({background:true}); }
      catch { /* Keep the last confirmed appearance during a temporary connection failure. */ }
      finally { pending = null; }
    })();
    return pending;
  }
  const timer = setTimer(refresh, 5000);
  surface?.addEventListener('focus', refresh);
  document?.addEventListener('visibilitychange', refresh);
  return {refresh, dispose() {
    disposed = true;
    clearTimer(timer);
    surface?.removeEventListener('focus', refresh);
    document?.removeEventListener('visibilitychange', refresh);
  }};
}
