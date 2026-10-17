// Load code only, after foreground reads finish. No API calls or component mounts.
export function createRoutePreloader({ routes, allowed, ready, schedule, constrained = () => false }) {
  const loaded = new Set();
  let generation = 0;
  function start(names, current) {
    const expected = ++generation;
    if (constrained()) return;
    const queue = names.map(name => routes().find(route => route.name === name)).filter(route => route && route.name !== current);
    let attempts = 0;
    function next() {
      if (expected !== generation || constrained()) return;
      if (!ready()) { if (++attempts < 100) schedule(next); return; }
      const route = queue.shift();
      if (!route) return;
      const component = route.components?.default;
      if (!allowed(route) || loaded.has(component) || typeof component !== 'function') { schedule(next); return; }
      loaded.add(component);
      Promise.resolve().then(component).catch(() => loaded.delete(component)).finally(() => {
        if (expected === generation) schedule(next);
      });
    }
    schedule(next);
  }
  return { start };
}
