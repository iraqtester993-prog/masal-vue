import { inject, reactive, readonly } from 'vue';
import { ApiError, createApiClient } from '../../shared/api/client.js';

export const portalContextKey = Symbol('masal.portal');

export function createSession(portal, api = createApiClient()) {
  const state = reactive({ identity: null, checked: false, error: '', busy: false });
  let pendingCheck;
  let revision = 0;

  function acceptIdentity(response) {
    const identity = response?.data;
    if (!identity?.user?.id || !identity?.account?.id || identity.portal !== portal || !Array.isArray(identity.permissions)) {
      throw new ApiError('هذا الحساب غير متاح في هذه البوابة.', 403);
    }
    const scope = value => JSON.stringify([value?.user?.id, value?.account?.id, value?.membership, value?.permissions]);
    if (scope(state.identity) !== scope(identity)) api.clearReadCache?.();
    state.identity = identity;
    state.error = '';
    state.checked = true;
    return identity;
  }

  function clear() {
    revision += 1;
    api.clearReadCache?.();
    state.identity = null;
    state.checked = true;
  }

  async function refresh() {
    if (pendingCheck?.revision === revision) return pendingCheck.promise;
    const expectedRevision = revision;
    const check = { revision: expectedRevision, promise: null };
    check.promise = (async () => {
      try {
        const response = await api.me();
        if (expectedRevision !== revision) return state.identity;
        return acceptIdentity(response);
      } catch (error) {
        if (expectedRevision !== revision) return state.identity;
        clear();
        state.error = error.status === 401 ? '' : error.status === 403 ? 'هذا الحساب غير متاح في هذه البوابة.' : error.message;
        return null;
      } finally {
        if (pendingCheck === check) pendingCheck = null;
      }
    })();
    pendingCheck = check;
    return check.promise;
  }

  async function login(login, password) {
    revision += 1;
    state.busy = true;
    state.error = '';
    try {
      await api.csrf();
      return acceptIdentity(await api.login({ login, password }));
    } finally {
      state.busy = false;
    }
  }

  async function updateProfile(name, version, signal) {
    const expectedRevision = ++revision;
    state.busy = true;
    try {
      const response = await api.updateOwnProfile({name,version}, signal);
      if (expectedRevision !== revision) return state.identity;
      return acceptIdentity(response);
    } finally { state.busy = false; }
  }

  async function logout() {
    // A previous /me response must not restore the identity after logout.
    revision += 1;
    state.busy = true;
    try {
      await api.csrf();
      await api.logout();
      clear();
    } catch (error) {
      if (error.status === 401 || error.status === 403) {
        clear();
        return;
      }
      if (error.status === 419) {
        await refresh();
        if (!state.identity) return;
      }
      throw error;
    } finally {
      state.busy = false;
    }
  }

  return { state: readonly(state), api, refresh, login, logout, updateProfile, clear, can: (permission) => state.identity?.permissions.includes(permission) || false };
}

export function usePortal() {
  const context = inject(portalContextKey);
  if (!context) throw new Error('Portal context is missing');
  return context;
}
