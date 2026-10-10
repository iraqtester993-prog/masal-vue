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
    options: (signal) => api.request('/reports/options', { signal }),
    summary: (filters, signal) => api.request(`/reports/summary?${query(filters)}`, { signal }),
    rows: (id, filters, signal) => api.request(`/reports/sections/${encodeURIComponent(id)}/rows?${query(filters)}`, { signal }),
    row: (id, key, filters, signal) => api.request(`/reports/sections/${encodeURIComponent(id)}/rows/${encodeURIComponent(key)}?${query(filters)}`, { signal }),
    export: (filters, signal) => api.mutate('/reports/export', 'POST', Object.fromEntries(Object.entries(filters).filter(([,value])=>value!==''&&value!==null&&value!==undefined)), signal),
    dashboard: (currency, signal, fresh = false) => api.request(`/dashboard/summary?${query({ currency })}`, { signal, fresh }),
    activity: (limit, signal) => api.request(`/dashboard/activity?${query({limit})}`, {signal}),
  };
}
