export function createFinanceApi(api) {
  const query = (values) => {
    const parameters = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
      if (value === '' || value === null || value === undefined) continue;
      if (Array.isArray(value)) value.forEach((item) => parameters.append(`${key}[]`, String(item)));
      else parameters.set(key, String(value));
    }
    return parameters.toString();
  };
  return {
    options: (signal, { fresh = false } = {}) => api.request('/finance/options?compact=1', { signal, fresh }),
    peekList: (kind,parameters={}) => api.peekRequest?.(`/finance/${kind}?${query(parameters)}`),
    list: (kind, parameters = {}, signal, { fresh = false } = {}) => api.request(`/finance/${kind}?${query(parameters)}`, { signal, fresh }),
    create: (kind, payload, signal) => api.mutate(`/finance/${kind}`, 'POST', payload, signal),
    action: (kind, id, action, payload, signal) => api.mutate(`/finance/${kind}/${id}/${action}`, 'POST', payload, signal),
    policy: (signal) => api.request('/finance/funding-policy', { signal }),
    savePolicy: (payload, signal) => api.mutate('/finance/funding-policy', 'PUT', payload, signal),
  };
}
export async function allPages(api, kind, parameters, signal, options = {}) {
  const rows = [];
  let page = 1;
  while (true) {
    const result = await api.list(kind, { ...parameters, per_page: 100, page }, signal, options);
    if (!Array.isArray(result?.data)) throw new Error('استجابة البيانات غير متوقعة.');
    rows.push(...result.data);
    if (page >= (result.meta?.last_page ?? 1)) return rows;
    if (++page > 500) throw new Error('البيانات كبيرة؛ حدد حسابًا أو نطاقًا أصغر.');
  }
}

export function accountParameters(filters={}) {return {account_city:filters.city,account_type:filters.kind,account_status:filters.active,account_query:filters.query,ancestor_id:filters.network,account_id:filters.account};}
