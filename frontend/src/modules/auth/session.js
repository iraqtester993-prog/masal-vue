import { inject, reactive, readonly } from 'vue';
import { ApiError, createApiClient } from '../../shared/api/client.js';
import {bootstrapPosDeviceSession,clearPosDeviceSession,signedPosDevicePayload} from '../../shared/pos-device-session.js';
import {passwordChangeRequired,passwordResetState} from './password-reset.js';

export const portalContextKey = Symbol('masal.portal');

export function identityScope(identity) {
  return JSON.stringify([identity?.portal, identity?.user?.id, identity?.account?.id, identity?.membership, identity?.permissions, identity?.capabilities, identity?.password_reset]);
}

export function loginErrorMessage(failure) {
  if (['POS_LOGIN_DEVICE_MISMATCH','POS_LOGIN_PROOF_REQUIRED'].includes(failure.code)) return failure.message;
  return failure.status === 401 || failure.status === 422 ? 'تحقق من اسم الدخول وكلمة المرور.'
    : failure.status === 403 ? 'هذا الحساب غير مسموح له بالدخول إلى هذه البوابة.'
    : failure.status === 419 ? 'انتهت صلاحية محاولة الدخول. حاول مرة أخرى.'
    : failure.status === 429 ? 'محاولات الدخول كثيرة. انتظر قليلًا وحاول مرة أخرى.'
    : failure.message;
}

export function createSession(portal, api = createApiClient(), deviceProof = {}) {
  const state = reactive({ identity: null, checked: false, accessConfirmed: false, checking: false, error: '', busy: false });
  let pendingCheck;
  let revision = 0;

  function acceptIdentity(response) {
    let identity = response?.data;
    if (!identity?.user?.id || !identity?.account?.id || identity.portal !== portal || !Array.isArray(identity.permissions)) {
      throw new ApiError('هذا الحساب غير متاح في هذه البوابة.', 403);
    }
    const previous=state.identity,previousReset=passwordResetState(previous?.password_reset),nextReset=passwordResetState(identity.password_reset);
    if(previous?.user.id===identity.user.id&&previous?.account.id===identity.account.id&&previousReset
      &&(!nextReset||nextReset.version<previousReset.version||(previousReset.required&&nextReset.version===previousReset.version&&!nextReset.required)))
      identity={...identity,password_reset:previousReset};
    const scope = value => JSON.stringify([value?.user?.id, value?.account?.id, value?.membership, value?.permissions, value?.capabilities, value?.password_reset]);
    if (state.identity && scope(state.identity) !== scope(identity)) api.clearReadCache?.();
    api.setReadScope?.(JSON.stringify([portal, scope(identity)]));
    state.identity = identity;
    state.error = '';
    state.checked = true;
    state.accessConfirmed = true;
    return identity;
  }

  function clear() {
    revision += 1;
    if (portal === 'pos') clearPosDeviceSession();
    api.clearReadCache?.();
    state.identity = null;
    state.checked = true;
    state.accessConfirmed = false;
  }

  function requirePasswordChange(value) {
    const next = passwordResetState(value);
    if (!state.identity || !next?.required || (state.identity.password_reset?.version ?? -1) > next.version) return;
    if (passwordChangeRequired(state.identity) && state.identity.password_reset.version === next.version) return;
    revision++;
    acceptIdentity({data:{...state.identity,password_reset:next}});
  }
  api.setPasswordResetHandler?.(requirePasswordChange);

  async function applyPasswordResetStatus(value) {
    const next = passwordResetState(value);
    if (!next || !state.identity || next.version < (state.identity.password_reset?.version ?? -1)) return;
    if (next.required) requirePasswordChange(next);
    else if (passwordChangeRequired(state.identity)) await refresh();
    else if(next.version>(state.identity.password_reset?.version??-1))acceptIdentity({data:{...state.identity,password_reset:next}});
  }

  async function completePasswordReset(password, confirmation, version, signal) {
    const expectedRevision = ++revision;
    state.busy = true;
    try {
      const response = await api.completePasswordReset({version,password,password_confirmation:confirmation},signal);
      if (expectedRevision !== revision) return state.identity;
      if (portal === 'pos') clearPosDeviceSession();
      return acceptIdentity(response);
    } finally { state.busy = false; }
  }

  async function refresh({background=false}={}) {
    if (pendingCheck?.revision === revision) { if(!background)state.accessConfirmed=false; return pendingCheck.promise; }
    const expectedRevision = revision;
    const backgroundCheck=background&&!!state.identity&&state.accessConfirmed;
    if(!backgroundCheck)state.accessConfirmed = false;
    state.checking = true;
    const check = { revision: expectedRevision, promise: null };
    check.promise = (async () => {
      try {
        let provedDevice = false;
        if (portal === 'pos' && !state.identity) {
          await api.csrf?.();
          if (expectedRevision !== revision) return state.identity;
          await bootstrapPosDeviceSession(api, deviceProof);
          provedDevice = true;
          if (expectedRevision !== revision) return state.identity;
        }
        let response;
        try { response = await api.me(); }
        catch (error) {
          if (expectedRevision !== revision) return state.identity;
          if (portal !== 'pos' || provedDevice || error.code !== 'POS_LOGIN_PROOF_REQUIRED') throw error;
          await bootstrapPosDeviceSession(api, deviceProof);
          if (expectedRevision !== revision) return state.identity;
          response = await api.me();
        }
        if (expectedRevision !== revision) return state.identity;
        return acceptIdentity(response);
      } catch (error) {
        if (expectedRevision !== revision) return state.identity;
        if (error.code === 'PASSWORD_CHANGE_REQUIRED' && state.identity) {
          requirePasswordChange(error.passwordReset || state.identity.password_reset);
          return state.identity;
        }
        if ([401, 403].includes(error.status) || ['POS_LOGIN_DEVICE_MISMATCH','POS_LOGIN_PROOF_REQUIRED'].includes(error.code)) clear();
        else if(!(backgroundCheck&&state.identity&&state.accessConfirmed)) {
          // Keep the account caption during an outage, but no permission is
          // usable until a fresh server check confirms this identity again.
          api.clearReadCache?.();
          state.checked = false;
          state.accessConfirmed = false;
        }
        state.error = ['POS_LOGIN_DEVICE_MISMATCH','POS_LOGIN_PROOF_REQUIRED'].includes(error.code) ? error.message : error.status === 401 ? '' : error.status === 403 ? 'هذا الحساب غير متاح في هذه البوابة.' : error.message;
        return null;
      } finally {
        if (pendingCheck === check) { pendingCheck = null; state.checking = false; }
      }
    })();
    pendingCheck = check;
    return check.promise;
  }

  async function login(login, password, remember = false) {
    const expectedRevision = ++revision;
    if (portal === 'pos') clearPosDeviceSession();
    state.busy = true;
    state.error = '';
    try {
      await api.csrf();
      const credentials = {login,password};
      const proof = portal === 'pos' ? await signedPosDevicePayload(api, {...deviceProof, credentials}) : {};
      if (expectedRevision !== revision) return state.identity;
      const response = await api.login({...credentials,...proof,...(remember ? {remember:true} : {})});
      if (expectedRevision !== revision) return state.identity;
      return acceptIdentity(response);
    } finally {
      state.busy = false;
    }
  }

  async function updateProfile(name, version, signal, fields = {}) {
    const expectedRevision = ++revision;
    state.busy = true;
    try {
      const response = await api.updateOwnProfile({name,version,...fields}, signal);
      if (expectedRevision !== revision) return state.identity;
      if (portal === 'pos' && fields.password) clearPosDeviceSession();
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

  return { state: readonly(state), api, refresh, login, logout, updateProfile, clear, requirePasswordChange, applyPasswordResetStatus, completePasswordReset,
    can: (permission) => state.accessConfirmed && !passwordChangeRequired(state.identity) && state.identity?.permissions.includes(permission) || false };
}

export function usePortal() {
  const context = inject(portalContextKey);
  if (!context) throw new Error('Portal context is missing');
  return context;
}
