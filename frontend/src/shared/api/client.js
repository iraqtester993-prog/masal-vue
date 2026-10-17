import { createReadCache } from './read-cache.js';
import { createDashboardSnapshot } from './dashboard-snapshot.js';
import { navigationTiming } from './navigation-timing.js';
import { isCacheableRead, readCacheKey, READ_FRESH_MS, READ_DISPLAY_MS } from './read-policy.js';

export class ApiError extends Error {
  constructor(message, status = 0, errors = {}, code = null, passwordReset = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.code = typeof code === 'string' ? code : null;
    this.passwordReset = passwordReset;
  }
}

export function cookieValue(name) {
  const value = document.cookie.split('; ').find((entry) => entry.startsWith(`${name}=`));
  if (!value) return '';
  try { return decodeURIComponent(value.slice(name.length + 1)); }
  catch { return ''; }
}

export function createApiClient(baseUrl = import.meta.env.VITE_API_BASE_URL || '/api/v1') {
  const base = baseUrl.replace(/\/$/, '');
  const reads = createReadCache({ ttl: READ_FRESH_MS });
  let storage;
  try { storage = globalThis.sessionStorage; } catch {}
  const dashboard = createDashboardSnapshot({ storage });
  const dashboardCards = createDashboardSnapshot({ storage, key: 'masal.dashboard.cards.v1' });
  const dashboardPath = '/dashboard/summary?currency=IQD';
  const cardsPath = dashboardPath + '&view=cards';
  const snapshotFor = path => readCacheKey(path) === readCacheKey(dashboardPath) ? dashboard : readCacheKey(path) === readCacheKey(cardsPath) ? dashboardCards : null;
  const clearReads = () => { reads.clear(); dashboard.clear(); dashboardCards.clear(); };
  let readScope, scopeRevision = 0;
  let passwordResetHandler;
  const clearReadCache = () => { scopeRevision++; clearReads(); };
  let pendingMutations=0;
  const setReadScope = scope => {
    if (scope !== readScope) { scopeRevision++; reads.clear(); }
    readScope = scope;
    dashboard.setScope(scope);
    dashboardCards.setScope(scope);
  };

  async function request(path, options = {}) {
    const method = options.method ?? 'GET';
    const finishTiming=navigationTiming?.read(path,method);let readFailed=false;
    const writing = !['GET', 'HEAD'].includes(method);
    const telemetry = /^\/(device\/(?:heartbeat|challenge)|maps\/(heartbeat|location|disconnect)|operations\/activity|account-lookup)(?:\?|$)/.test(path);
    const notificationRead = writing && /^\/notifications\/read-page(?:\?|$)/.test(path);
    const protectedMutation=writing&&!telemetry;
    if(protectedMutation)pendingMutations++;
    const invalidate = (writing && !telemetry && !notificationRead && !/\/device-session(?:\?|$)/.test(path)) || /^\/digital\/orders\/(?!summary(?:\?|$)|export(?:\?|$)|recovery(?:\?|$))[^/?]+(?:\?|$)/.test(path);
    if (writing && /^\/auth\/(?:login|logout)(?:\?|$)/.test(path)) clearReadCache();
    if (invalidate) clearReads();
    try {
      if (method === 'GET' && !options.absolutePath && isCacheableRead(path)) {
        const key = readCacheKey(path), revision = scopeRevision;
        const assertScope = () => { if (revision !== scopeRevision) throw new DOMException('The session scope changed', 'AbortError'); };
        const snapshot = snapshotFor(path), expected = snapshot?.revision();
        const cached = !options.fresh && reads.has(key);
        const response = await reads.read(key, async () => {
          assertScope();
          try { const value = await send(path, { ...options, signal: undefined }); assertScope(); return value; }
          catch (error) { assertScope(); throw error; }
        }, { signal: options.signal, force: options.fresh });
        assertScope();
        if (!cached) snapshot?.save(response, expected);
        return response;
      }
      return await send(path, options);
    } catch (error) {
      readFailed=error.name!=='AbortError';
      if ([401, 403].includes(error.status)) clearReadCache();
      throw error;
    } finally { if(protectedMutation)pendingMutations--;finishTiming?.(readFailed);if (invalidate) clearReads(); }
  }

  async function send(path, { method = 'GET', body, signal, absolutePath = false, timeoutMs = 15000, keepalive = false } = {}) {
    const expectedScope = scopeRevision;
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;
    if (body !== undefined && !isFormData) headers['Content-Type'] = 'application/json';
    if (!['GET', 'HEAD'].includes(method)) {
      const token = cookieValue('XSRF-TOKEN');
      if (token) headers['X-XSRF-TOKEN'] = token;
    }

    let response;
    const requestController = new AbortController();
    const abortFromCaller = () => requestController.abort(signal?.reason);
    if (signal?.aborted) abortFromCaller();
    else signal?.addEventListener('abort', abortFromCaller, { once: true });
    const deadline = setTimeout(() => requestController.abort(), timeoutMs);
    const started = globalThis.performance?.now();
    const finishNetwork = navigationTiming?.network(path, method);
    try {
      response = await fetch(absolutePath ? path : `${base}${path}`, {
        method, headers, credentials: 'include', cache: 'no-store', signal: requestController.signal, keepalive,
        ...(body !== undefined ? { body: isFormData ? body : JSON.stringify(body) } : {}),
      });
      const isJson = response.headers.get('content-type')?.includes('application/json');
      const payload = isJson ? await response.json().catch(() => null) : null;
      const serverDuration = response.headers.get('server-timing')?.match(/(?:^|,)\s*app;dur=([\d.]+)/)?.[1];
      finishNetwork?.(globalThis.performance?.now() - started, serverDuration === undefined ? null : Number(serverDuration));
      requestController.signal.throwIfAborted();
      if (!response.ok) {
        if (response.status === 403 && payload?.code === 'PASSWORD_CHANGE_REQUIRED' && expectedScope === scopeRevision)
          passwordResetHandler?.(payload.password_reset);
        const fallback = response.status >= 500 ? 'تعذر إكمال الطلب. حاول مرة أخرى.' : 'تعذر إكمال الطلب.';
        throw new ApiError(response.status >= 500 ? fallback : payload?.message || fallback, response.status, payload?.errors || {}, payload?.code, payload?.password_reset);
      }
      if (response.status !== 204 && !isJson) throw new ApiError('استجابة السيرفر غير متوقعة. حاول مرة أخرى.', response.status);
      return payload;
    } catch (error) {
      if (signal?.aborted || error instanceof ApiError) throw error;
      throw new ApiError('تعذر الاتصال بالسيرفر. تحقق من الاتصال وحاول مرة أخرى.');
    } finally {
      clearTimeout(deadline);
      signal?.removeEventListener('abort', abortFromCaller);
    }
  }

  const query = (parameters) => {
    const entries = Object.entries(parameters).filter(([, value]) => value !== '' && value !== null && value !== undefined);
    return new URLSearchParams(entries).toString();
  };
  const accountsPath = (parameters = 1) => `/accounts?${query(typeof parameters === 'number' ? { page: parameters } : parameters)}`;
  const peekRequest = (path, maxAge = READ_DISPLAY_MS) => {
    if (!isCacheableRead(path)) return null;
    const age = Math.max(0, Math.min(Number(maxAge) || 0, READ_DISPLAY_MS));
    return reads.peek(readCacheKey(path), age) ?? snapshotFor(path)?.peek(age) ?? null;
  };
  async function mutate(path, method, body, signal, options = {}) {
    if (!cookieValue('XSRF-TOKEN')) await request('/sanctum/csrf-cookie', { absolutePath: true, signal });
    return request(path, { ...options, method, body, signal });
  }
  return {
    request,
    hasPendingMutation:()=>pendingMutations>0,
    peekRequest,
    hasFreshRequest: path => isCacheableRead(path) && reads.has(readCacheKey(path)),
    peekFreshRequest: path => isCacheableRead(path) && reads.has(readCacheKey(path)) ? reads.peek(readCacheKey(path)) : null,
    peekAccounts: (parameters = 1, maxAge = READ_DISPLAY_MS) => peekRequest(accountsPath(parameters), maxAge),
    clearReadCache,
    setReadScope,
    setPasswordResetHandler:handler=>{passwordResetHandler=typeof handler==='function'?handler:null;},
    mutate,
    csrf: () => request('/sanctum/csrf-cookie', { absolutePath: true }),
    login: (credentials) => request('/auth/login', { method: 'POST', body: credentials }),
    me: () => request('/auth/me'),
    logout: () => request('/auth/logout', { method: 'POST' }),
    accounts: (parameters = 1, signal) => request(accountsPath(parameters), { signal }),
    account: (id, signal) => request(`/accounts/${id}`, { signal }),
    accountOptions: (signal) => request('/account-options', { signal }),
    permissionCatalog: (signal) => request('/permission-catalog', { signal }),
    createAccount: (payload, signal) => mutate('/accounts', 'POST', payload, signal),
    updateAccount: (id, payload, signal) => mutate(`/accounts/${id}`, 'PATCH', payload, signal),
    setAccountStatus: (id, payload, signal) => mutate(`/accounts/${id}/status`, 'PATCH', payload, signal),
    updateAccountLogin: (id, payload, signal) => mutate(`/accounts/${id}/login`, 'PATCH', payload, signal),
    resetAccountPassword: (id, payload, signal) => mutate(`/accounts/${id}/password-reset`, 'POST', payload, signal),
    passwordResetStatus: () => request('/auth/password-reset/status'),
    completePasswordReset: (payload, signal) => mutate('/auth/password-reset/complete', 'POST', payload, signal),
    passwordRecoveryStatus: signal => request('/auth/password-reset/request-status',{signal}),
    requestPasswordRecovery: (payload, signal) => mutate('/auth/password-reset/request','POST',payload,signal),
    completePasswordRecovery: (payload, signal) => mutate('/auth/password-reset/complete-request','POST',payload,signal),
    setAccountPermissions: (id, payload, signal) => mutate(`/accounts/${id}/permissions`, 'PATCH', payload, signal),
    updateOwnProfile: (payload, signal) => mutate('/auth/profile', 'PATCH', payload, signal),
    staff: (id, parameters = {}, signal) => request(`/accounts/${id}/staff?${query(parameters)}`, { signal }),
    createStaff: (id, payload, signal) => mutate(`/accounts/${id}/staff`, 'POST', payload, signal),
    updateStaff: (id, membershipId, payload, signal) => mutate(`/accounts/${id}/staff/${membershipId}`, 'PATCH', payload, signal),
    setStaffStatus: (id, membershipId, payload, signal) => mutate(`/accounts/${id}/staff/${membershipId}/status`, 'PATCH', payload, signal),
    profiles: (id, parameters = {}, signal) => request(`/accounts/${id}/permission-profiles?${query(parameters)}`, { signal }),
    createProfile: (id, payload, signal) => mutate(`/accounts/${id}/permission-profiles`, 'POST', payload, signal),
    updateProfile: (id, profileId, payload, signal) => mutate(`/accounts/${id}/permission-profiles/${profileId}`, 'PATCH', payload, signal),
    setProfileStatus: (id, profileId, payload, signal) => mutate(`/accounts/${id}/permission-profiles/${profileId}/status`, 'PATCH', payload, signal),
    deleteProfile: (id, profileId, payload, signal) => mutate(`/accounts/${id}/permission-profiles/${profileId}`, 'DELETE', payload, signal),
    attachments: (id, signal) => request(`/accounts/${id}/attachments`, {signal}),
    uploadAttachment: (id, payload, signal) => mutate(`/accounts/${id}/attachments`, 'POST', payload, signal),
    deleteAttachment: (id, attachmentId, payload, signal) => mutate(`/accounts/${id}/attachments/${attachmentId}`, 'DELETE', payload, signal),
  };
}
