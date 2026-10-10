import {queryString} from './communications.js';

export function createSupportApi(client) {
  const get = (path,signal) => client.request(`/support/${path}`,{signal});
  const post = (path,body,signal) => client.mutate(`/support/${path}`,'POST',body,signal);
  return {
    options: signal => get('options',signal),
    tickets: (parameters,signal) => get(`tickets?${queryString(parameters)}`,signal),
    ticket: (id,signal) => get(`tickets/${id}`,signal),
    create: (payload,signal) => post('tickets',payload,signal),
    broadcast: (payload,signal) => post('broadcasts',payload,signal),
    reply: (id,payload,signal) => post(`tickets/${id}/replies`,payload,signal),
    status: (id,payload,signal) => post(`tickets/${id}/status`,payload,signal),
    read: (id,signal) => post(`tickets/${id}/read`,{},signal),
    phones: (id,signal) => get(`phones/${id}`,signal),
    savePhones: (id,payload,signal) => client.mutate(`/support/phones/${id}`,'PUT',payload,signal),
    upload: (file,kind,signal) => {
      const body = new FormData(); body.append('file',file); body.append('kind',kind);
      return client.mutate('/support/attachments','POST',body,signal);
    },
  };
}
