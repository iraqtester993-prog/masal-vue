function query(parameters) { return new URLSearchParams(Object.entries(parameters).filter(([,value])=>value!==''&&value!==undefined&&value!==null)).toString(); }
export function createCompanyApi(client) {
  return {
    publicProfile: signal => client.request('/company/public',{signal}),
    profile: signal => client.request('/company/profile',{signal}),
    save: (data,signal) => client.mutate('/company/profile','PUT',data,signal),
    inquiry: (data,signal) => client.mutate('/company/inquiries','POST',data,signal),
    inquiries: (parameters,signal) => client.request('/company/inquiries?'+query(parameters),{signal}),
    review: (id,data,signal) => client.mutate('/company/inquiries/'+encodeURIComponent(id),'PATCH',data,signal),
    track: (token,signal) => client.mutate('/company/inquiries/track','POST',{token},signal),
    followup: (payload,signal) => client.mutate('/company/inquiries/followup','POST',payload,signal),
    upload: (file,signal) => { const form = new FormData(); form.append('file',file); return client.mutate('/company/assets','POST',form,signal); },
  };
}