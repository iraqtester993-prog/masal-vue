export function createStockApi(api) {
  const query = (values = {}) => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null)).toString();
  const post = (path, payload, signal) => api.mutate(`/stock/${path}`, 'POST', payload, signal, {timeoutMs:120000});
  return {
    options: (signal) => api.request('/stock/options', {signal}),
    summary: (filters, signal) => api.request(`/stock/summary?${query(filters)}`, {signal}),
    list: (kind, filters = {}, signal) => api.request(`/stock/${kind}?${query(filters)}`, {signal}),
    get: (kind, id, signal) => api.request(`/stock/${kind}/${id}`, {signal}),
    cards: (id, filters, signal) => api.request(`/stock/batches/${id}/cards?${query(filters)}`, {signal}),
    selection: (id, filters, signal) => api.request(`/stock/batches/${id}/selection?${query(filters)}`, {signal}),
    read: (accountId, file, signal) => { const body = new FormData(); body.set('account_id', String(accountId)); body.set('file', file); return post('read', body, signal); },
    preview: (draft, signal) => post('orders/preview', draft, signal),
    submit: (draft, signal) => post('orders', draft, signal),
    resubmit: (id, draft, signal) => post(`orders/${id}/resubmit`, draft, signal),
    review: (id, payload, signal) => post(`orders/${id}/review`, payload, signal),
    batchAction: (id, payload, signal) => post(`batches/${id}/actions`, payload, signal),
    previewAction: (id, payload, signal) => post(`batches/${id}/actions/preview`, payload, signal),
    copy: (id, payload, signal) => post(`batches/${id}/copy`, payload, signal),
    claim: (id, payload, signal) => post(`batches/${id}/claims`, payload, signal),
    settleClaim: (id, payload, signal) => post(`claims/${id}/settle`, payload, signal),
    withdrawal: (payload, signal) => post('withdrawals', payload, signal),
    reviewWithdrawal: (id, payload, signal) => post(`withdrawals/${id}/review`, payload, signal),
    downloadWithdrawal: (id, payload, signal) => post(`withdrawals/${id}/download`, payload, signal),
  };
}
