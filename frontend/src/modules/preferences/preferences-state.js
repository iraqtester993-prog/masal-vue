import { computed, inject, reactive, readonly, watch } from 'vue';
import { createPreferencesApi } from './preferences-api.js';
import { translate, translateKnown, languages, prepareLanguage } from './translate.js';

export const preferencesContextKey = Symbol('masal.preferences');
const defaults = { language: 'ar', theme: 'light', version: 0 };

function accepted(data) {
  if (!data || !languages.some(language => language.id === data.language)
    || !['light', 'dark'].includes(data.theme) || !Number.isInteger(data.version) || data.version < 0) {
    throw Error('تعذر قراءة إعدادات المستخدم.');
  }
  return { language: data.language, theme: data.theme, version: data.version };
}

export function createUserPreferences(session, {
  surface = typeof document === 'undefined' ? null : document,
  prepare = prepareLanguage,
} = {}) {
  const api = createPreferencesApi(session.api);
  const state = reactive({ ...defaults, loading: false, busy: false, error: '' });
  let actorId = null, generation = 0, readRevision = 0, queued = 0, disposed = false;
  let controller = new AbortController(), loading = null, queue = Promise.resolve();

  function apply(data) {
    Object.assign(state, data);
    if (surface) {
      surface.documentElement.dataset.theme = state.theme;
      surface.documentElement.lang = state.language;
      surface.documentElement.dir = languages.find(language => language.id === state.language).direction;
    }
  }
  function current(expected, id) { return !disposed && generation === expected && actorId === id; }
  async function loadLanguage(language) { if (language !== 'ar') await prepare(language); }

  async function refresh() {
    const expected = generation, id = actorId;
    if (!id || disposed) return;
    const revision = ++readRevision;
    state.loading = true;
    const isLatest = () => current(expected, id) && revision === readRevision;
    const read = (async () => {
      try {
        const response = await api.read(controller.signal);
        if (!isLatest()) return;
        const data = accepted(response.data);
        await loadLanguage(data.language);
        if (isLatest()) { apply(data); state.error = ''; }
      } catch (error) {
        if (isLatest() && error.name !== 'AbortError') {
          state.error = error.message;
          if ([401, 403].includes(error.status)) await session.refresh();
        }
      } finally { if (isLatest()) state.loading = false; }
    })();
    loading = read;
    await read;
    if (loading === read) loading = null;
  }

  function update(field, value) {
    if (disposed || (field === 'language' && !languages.some(language => language.id === value))
      || (field === 'theme' && !['light', 'dark'].includes(value))) return Promise.resolve(false);
    const expected = generation, id = actorId;
    queued++;
    state.busy = true;
    const save = queue.then(async () => {
      if (loading) await loading;
      if (!current(expected, id)) return false;
      try {
        if (field === 'language') await loadLanguage(value);
        if (!current(expected, id)) return false;
        if (!id) { apply({ [field]: value }); state.error = ''; return true; }
        const response = await api.save({ language: state.language, theme: state.theme, [field]: value, version: state.version }, controller.signal);
        if (!current(expected, id)) return false;
        const data = accepted(response.data);
        readRevision++;
        state.loading = false;
        await loadLanguage(data.language);
        if (!current(expected, id)) return false;
        apply(data);
        state.error = '';
        return true;
      } catch (error) {
        if (!current(expected, id) || error.name === 'AbortError') return false;
        if (error.status === 409) {
          await refresh();
          if (current(expected, id)) state.error = 'تغيرت إعداداتك في جلسة أخرى؛ راجعها ثم أعد اختيارك.';
        } else {
          state.error = error.message;
          if ([401, 403].includes(error.status)) await session.refresh();
        }
        return false;
      } finally { if (current(expected, id)) { queued--; state.busy = queued > 0; } }
    });
    queue = save.catch(() => false);
    return save;
  }

  const stop = watch(() => session.state.identity?.user?.id || null, id => {
    generation++;
    readRevision++;
    controller.abort();
    controller = new AbortController();
    actorId = id;
    queued = 0;
    queue = Promise.resolve();
    loading = null;
    Object.assign(state, { ...defaults, error: '', loading: false, busy: false });
    apply(defaults);
    if (id) void refresh();
  }, { immediate: true });

  return {
    state: readonly(state), dark: computed(() => state.theme === 'dark'), refresh,
    setLanguage: language => update('language', language), setTheme: theme => update('theme', theme),
    toggleTheme: () => update('theme', state.theme === 'dark' ? 'light' : 'dark'),
    t: (value, protectedRecords = []) => translate(value, state.language, protectedRecords),
    dispose() { if (disposed) return; disposed = true; generation++; readRevision++; controller.abort(); stop(); },
  };
}

export function usePreferences() { return inject(preferencesContextKey, null); }
export function usePreferencesTranslator() {
  const preferences = usePreferences();
  return (value, protectedRecords = []) => preferences ? preferences.t(value, protectedRecords) : value;
}
export function usePreferencesMessageTranslator() {
  const preferences=usePreferences();
  return value=>translateKnown(value,preferences?.state.language||'ar');
}
