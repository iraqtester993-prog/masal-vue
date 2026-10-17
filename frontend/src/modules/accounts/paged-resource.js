import { reactive } from 'vue';
export function createPagedResource(fetcher, onFailure = async (error) => error.message, {peek} = {}) {
  const state = reactive({ rows: [], meta: {current_page:1,last_page:1,total:0}, loading: false, error: '' });
  let controller, revision = 0;
  function hydrate(parameters={}) {
    const cached=peek?.(parameters);
    state.rows = Array.isArray(cached?.data)?cached.data:[];
    state.meta = cached?.meta || {current_page:1,last_page:1,total:state.rows.length};
    return cached;
  }
  async function load(parameters = {}, {fresh=false} = {}) {
    controller?.abort();
    controller = new AbortController();
    const own = controller, expected = ++revision;
    const cached=hydrate(parameters);
    state.error = ''; state.loading = fresh||!cached;
    try {
      const result = await fetcher(parameters, own.signal,{fresh});
      if (expected !== revision) return;
      if (!Array.isArray(result?.data)) throw new Error('تعذر قراءة القائمة. حاول مرة أخرى.');
      state.rows = result.data;
      state.meta = result.meta || {current_page:1,last_page:1,total:result.data.length};
    } catch (failure) {
      if (expected !== revision || failure.name === 'AbortError') return;
      const message = await onFailure(failure);
      if (expected === revision) state.error = message;
    } finally { if (expected === revision) state.loading = false; }
  }
  function dispose() { revision += 1; controller?.abort(); state.rows = []; state.loading = false; }
  return {state,hydrate,load,dispose};
}
