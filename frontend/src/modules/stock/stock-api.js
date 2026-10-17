export function createStockApi(api) {
  const query = (values = {}) => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null)).toString();
  const post = (path, payload, signal) => api.mutate(`/stock/${path}`, 'POST', payload, signal, {timeoutMs:120000});
  return {
    options: (signal,options={}) => api.request('/stock/options', {signal,...options}),
    peekOptions: () => api.peekRequest?.('/stock/options',120000) ?? null,
    hasFreshOptions: () => api.hasFreshRequest?.('/stock/options') ?? false,
    summary: (filters, signal,options={}) => api.request(`/stock/summary?${query(filters)}`, {signal,...options}),
    peekSummary: filters => api.peekRequest?.(`/stock/summary?${query(filters)}`,120000) ?? null,
    hasFreshSummary: filters => api.hasFreshRequest?.(`/stock/summary?${query(filters)}`) ?? false,
    list: (kind, filters = {}, signal,options={}) => api.request(`/stock/${kind}?${query(filters)}`, {signal,...options}),
    peekList: (kind,filters={}) => api.peekRequest?.(`/stock/${kind}?${query(filters)}`,120000) ?? null,
    hasFreshList: (kind,filters={}) => api.hasFreshRequest?.(`/stock/${kind}?${query(filters)}`) ?? false,
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
