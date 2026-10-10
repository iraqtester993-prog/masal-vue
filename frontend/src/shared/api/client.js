import { createReadCache } from './read-cache.js';

export class ApiError extends Error {
  constructor(message, status = 0, errors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
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
  const reads = createReadCache();
  const cacheable = path => /^\/(dashboard\/summary|reports\/(options|summary)|finance\/(options|wallets))(\?|$)/.test(path);

  async function request(path, options = {}) {
    const method = options.method ?? 'GET';
    const writing = !['GET', 'HEAD'].includes(method);
    const invalidate = (writing && !/\/device-session(?:\?|$)/.test(path)) || /^\/digital\/orders\/(?!summary(?:\?|$)|export(?:\?|$))[^/?]+(?:\?|$)/.test(path);
    if (invalidate) reads.clear();
    try {
      if (method === 'GET' && !options.absolutePath && cacheable(path)) {
        return await reads.read(path, () => send(path, { ...options, signal: undefined }), { signal: options.signal, force: options.fresh });
      }
      return await send(path, options);
    } catch (error) {
      if ([401, 403].includes(error.status)) reads.clear();
      throw error;
    } finally { if (invalidate) reads.clear(); }
  }

  async function send(path, { method = 'GET', body, signal, absolutePath = false, timeoutMs = 15000, keepalive = false } = {}) {
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
    try {
      response = await fetch(absolutePath ? path : `${base}${path}`, {
        method, headers, credentials: 'include', cache: 'no-store', signal: requestController.signal, keepalive,
        ...(body !== undefined ? { body: isFormData ? body : JSON.stringify(body) } : {}),
      });
      const isJson = response.headers.get('content-type')?.includes('application/json');
      const payload = isJson ? await response.json().catch(() => null) : null;
      requestController.signal.throwIfAborted();
      if (!response.ok) {
        const fallback = response.status >= 500 ? 'تعذر إكمال الطلب. حاول مرة أخرى.' : 'تعذر إكمال الطلب.';
        throw new ApiError(response.status >= 500 ? fallback : payload?.message || fallback, response.status, payload?.errors || {});
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
  async function mutate(path, method, body, signal, options = {}) {
    if (!cookieValue('XSRF-TOKEN')) await request('/sanctum/csrf-cookie', { absolutePath: true, signal });
    return request(path, { ...options, method, body, signal });
  }
  return {
    request,
    peekRequest: (path, maxAge) => cacheable(path) ? reads.peek(path, maxAge) : null,
    clearReadCache: reads.clear,
    mutate,
    csrf: () => request('/sanctum/csrf-cookie', { absolutePath: true }),
    login: (credentials) => request('/auth/login', { method: 'POST', body: credentials }),
    me: () => request('/auth/me'),
    logout: () => request('/auth/logout', { method: 'POST' }),
    accounts: (parameters = 1, signal) => request(`/accounts?${query(typeof parameters === 'number' ? { page: parameters } : parameters)}`, { signal }),
    account: (id, signal) => request(`/accounts/${id}`, { signal }),
    accountOptions: (signal) => request('/account-options', { signal }),
    permissionCatalog: (signal) => request('/permission-catalog', { signal }),
    createAccount: (payload, signal) => mutate('/accounts', 'POST', payload, signal),
    updateAccount: (id, payload, signal) => mutate(`/accounts/${id}`, 'PATCH', payload, signal),
    setAccountStatus: (id, payload, signal) => mutate(`/accounts/${id}/status`, 'PATCH', payload, signal),
    updateAccountLogin: (id, payload, signal) => mutate(`/accounts/${id}/login`, 'PATCH', payload, signal),
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
