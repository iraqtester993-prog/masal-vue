import {queryString} from '../support/communications.js';
export function createNotificationsApi(client) {
  const get = (path,signal) => client.request(`/notifications${path}`,{signal});
  const post = (path,payload,signal) => client.mutate(`/notifications${path}`,'POST',payload,signal);
  return {
    options: signal => get('/options',signal),
    readPage: (page,ids,signal) => post('/read-page',{page,ids},signal),
    list: (parameters,signal) => get(`?${queryString(parameters)}`,signal),
    create: (payload,signal) => post('',payload,signal),
    read: (id,signal) => post(`/${id}/read`,{},signal),
    summary: signal => get('/summary',signal),
    export: (parameters,signal) => get(`/export?${queryString(parameters)}`,signal),
  };
}
