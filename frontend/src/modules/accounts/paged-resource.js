import { reactive } from 'vue';
export function createPagedResource(fetcher, onFailure = async (error) => error.message) {
  const state = reactive({ rows: [], meta: {current_page:1,last_page:1,total:0}, loading: false, error: '' });
  let controller, revision = 0;
  async function load(parameters = {}) {
    controller?.abort();
    controller = new AbortController();
    const own = controller, expected = ++revision;
    state.rows = []; state.error = ''; state.loading = true;
    try {
      const result = await fetcher(parameters, own.signal);
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
  return {state,load,dispose};
}
