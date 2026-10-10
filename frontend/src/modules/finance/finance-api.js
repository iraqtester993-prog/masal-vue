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
    options: (signal) => api.request('/finance/options', { signal }),
    list: (kind, parameters = {}, signal) => api.request(`/finance/${kind}?${query(parameters)}`, { signal }),
    create: (kind, payload, signal) => api.mutate(`/finance/${kind}`, 'POST', payload, signal),
    action: (kind, id, action, payload, signal) => api.mutate(`/finance/${kind}/${id}/${action}`, 'POST', payload, signal),
    policy: (signal) => api.request('/finance/funding-policy', { signal }),
    savePolicy: (payload, signal) => api.mutate('/finance/funding-policy', 'PUT', payload, signal),
  };
}
export async function allPages(api, kind, parameters, signal) {
  const rows = [];
  let page = 1;
  while (true) {
    const result = await api.list(kind, { ...parameters, per_page: 100, page }, signal);
    if (!Array.isArray(result?.data)) throw new Error('استجابة البيانات غير متوقعة.');
    rows.push(...result.data);
    if (page >= (result.meta?.last_page ?? 1)) return rows;
    if (++page > 500) throw new Error('البيانات كبيرة؛ حدد حسابًا أو نطاقًا أصغر.');
  }
}
