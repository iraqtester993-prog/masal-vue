export function query(values = {}) {
  const result = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) {
    if (value === '' || value === null || value === undefined) continue;
    if (Array.isArray(value)) value.forEach((item) => result.append(`${key}[]`, String(item)));
    else result.set(key, String(value));
  }
  return result.toString();
}
export function createReportApi(api) {
  return {
    options: (signal,options={}) => api.request('/reports/options?compact=1', { signal,...options }),
    peekOptions: () => api.peekRequest?.('/reports/options?compact=1') ?? null,
    hasFreshOptions: () => api.hasFreshRequest?.('/reports/options?compact=1') ?? false,
    summary: (filters, signal,options={}) => api.request(`/reports/summary?${query(filters)}`, { signal,...options }),
    peekSummary: filters => api.peekRequest?.(`/reports/summary?${query(filters)}`) ?? null,
    hasFreshSummary: filters => api.hasFreshRequest?.(`/reports/summary?${query(filters)}`) ?? false,
    rows: (id, filters, signal) => api.request(`/reports/sections/${encodeURIComponent(id)}/rows?${query(filters)}`, { signal }),
    row: (id, key, filters, signal) => api.request(`/reports/sections/${encodeURIComponent(id)}/rows/${encodeURIComponent(key)}?${query(filters)}`, { signal }),
    export: (filters, signal) => api.mutate('/reports/export', 'POST', Object.fromEntries(Object.entries(filters).filter(([,value])=>value!==''&&value!==null&&value!==undefined)), signal),
    dashboard: (currency, signal, fresh = false, view) => api.request(`/dashboard/summary?${query({ currency, view })}`, { signal, fresh }),
    activity: (limit, signal, cursor) => api.request(`/dashboard/activity?${query({limit,cursor})}`, {signal}),
  };
}
