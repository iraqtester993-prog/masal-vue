import { ApiError } from '../../shared/api/client.js';
export function queryString(values = {}) {
  const query = new URLSearchParams();
  for (const [key,value] of Object.entries(values)) {
    if (value === '' || value === null || value === undefined) continue;
    if (Array.isArray(value)) value.forEach(item => query.append(`${key}[]`,String(item)));
    else query.set(key,String(value));
  }
  return query.toString();
}
export function createSalesApi(api) {
  const base = '/sales';
  return {
    options: signal => api.request(`${base}/options`,{signal}),
    configuration: signal => api.request(`${base}/configuration-options`,{signal}),
    products: signal => api.request(`${base}/products`,{signal}),
    summary: (params,signal) => api.request(`${base}/summary?${queryString(params)}`,{signal}),
    list: (kind = '',params = {},signal) => api.request(`${base}${kind ? `/${kind}` : ''}?${queryString(params)}`,{signal}),
    show: (id,signal) => api.request(`${base}/${id}`,{signal}),
    receipt: (id,signal) => api.request(`${base}/${id}/receipt`,{signal}),
    create: (payload,reserve = false,signal) => api.mutate(`${base}${reserve ? '/reservations' : ''}`,'POST',payload,signal),
    action: (id,action,payload,signal) => api.mutate(`${base}/${id}/${action}`,'POST',payload,signal),
    reservation: (id,action,payload,signal) => api.mutate(`${base}/reservations/${id}/${action}`,'POST',payload,signal),
    review: (id,action,payload,signal) => api.mutate(`${base}/reprint-requests/${id}/${action}`,'POST',payload,signal),
    heartbeat: (payload,signal) => api.mutate(`${base}/device-session`,'POST',payload,signal),
    policy: signal => api.request(`${base}/print-policy`,{signal}),
    savePolicy: (payload,signal) => api.mutate(`${base}/print-policy`,'PUT',payload,signal),
    saveRule: (id,payload,signal) => api.mutate(`${base}/print-rules${id ? `/${id}` : ''}`,id ? 'PUT' : 'POST',payload,signal),
    saveLimit: (payload,signal) => api.mutate(`${base}/limits`,'PUT',payload,signal),
    layout: (id,params = {},signal) => api.request(`${base}/receipt-layouts/${id}?${queryString(params)}`,{signal}),
    saveLayout: (id,payload,signal,image = null) => {
      if (!image) return api.mutate(`${base}/receipt-layouts/${id}`,'PUT',payload,signal);
      const body = new FormData(); body.set('_method','PUT'); body.set('payload',JSON.stringify(payload)); body.set('image',image);
      return api.mutate(`${base}/receipt-layouts/${id}`,'POST',body,signal);
    },
    async export(params,signal) {
      const root = (import.meta.env?.VITE_API_BASE_URL || '/api/v1').replace(/\/$/,'');
      const response = await fetch(`${root}${base}/export?${queryString(params)}`,{credentials:'include',headers:{Accept:'text/csv','X-Requested-With':'XMLHttpRequest'},signal});
      if (!response.ok) {const body = await response.json().catch(()=>null); throw new ApiError(body?.message || 'تعذر تنزيل سجل المبيعات.',response.status,body?.errors);}
      if (!response.headers.get('content-type')?.includes('text/csv')) throw new ApiError('استجابة التنزيل غير متوقعة.');
      return response.blob();
    },
  };
}
