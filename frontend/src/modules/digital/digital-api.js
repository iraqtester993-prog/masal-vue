import { ApiError } from '../../shared/api/client.js';
import { queryString } from '../sales/sales-api.js';
export function createDigitalApi(api) {
  const base='/digital',read=(path,params={},signal)=>api.request(`${base}${path}?${queryString(params)}`,{signal});
  const write=(path,method,payload,signal)=>api.mutate(`${base}${path}`,method,payload,signal,{timeoutMs:120000});
  return {
    recovery:signal=>read('/orders/recovery',{},signal),acknowledge:(id,signal)=>write(`/orders/${id}/acknowledge`,'POST',{},signal),
    options:(signal,params={})=>read('/options',params,signal),connections:(params,signal)=>read('/connections',params,signal),offers:signal=>read('/offers',{},signal),
    orders:(params,signal)=>read('/orders',params,signal),history:(params,signal)=>read('/history',params,signal),summary:(params,signal)=>read('/orders/summary',params,signal),show:(id,signal)=>read(`/orders/${id}`,{},signal),receipt:(id,signal)=>read(`/orders/${id}/receipt`,{},signal),printAuthorization:(id,signal)=>read(`/orders/${id}/print-authorization`,{},signal),
    configure:(id,payload,signal)=>write(`/connections${id?`/${id}`:''}`,id?'PUT':'POST',payload,signal),sync:(id,version,signal)=>write(`/connections/${id}/sync`,'POST',{version},signal),syncCatalog:(id,version,signal)=>write(`/connections/${id}/sync`,'POST',{version,catalog_only:true},signal),catalog:(id,version,signal)=>write(`/connections/${id}/catalog`,'POST',{version},signal),balance:(id,signal)=>write(`/connections/${id}/balance`,'POST',{},signal),
    grant:(id,target,payload,signal)=>write(`/connections/${id}/grants/${target}`,'PUT',payload,signal),create:(payload,signal)=>write('/orders','POST',payload,signal),action:(id,action,payload,signal)=>write(`/orders/${id}/${action}`,'POST',payload,signal),heartbeat:(payload,signal)=>write('/device-session','POST',payload,signal),
    async export(params,signal) {
      const root=(import.meta.env?.VITE_API_BASE_URL||'/api/v1').replace(/\/$/,'');
      const response=await fetch(`${root}${base}/orders/export?${queryString(params)}`,{credentials:'include',headers:{Accept:'text/csv','X-Requested-With':'XMLHttpRequest'},signal});
      if(!response.ok){const result=await response.json().catch(()=>null);throw new ApiError(result?.message||'تعذر تنزيل سجل الخدمات.',response.status,result?.errors);}
      if(!response.headers.get('content-type')?.includes('text/csv'))throw new ApiError('استجابة التنزيل غير متوقعة.');
      return response.blob();
    },
  };
}
