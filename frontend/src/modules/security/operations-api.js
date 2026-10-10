import {queryString} from '../support/communications.js';

export function createOperationsApi(client) {
  const get = (path,signal) => client.request(`/operations/${path}`,{signal});
  const post = (path,body,signal) => client.mutate(`/operations/${path}`,'POST',body,signal);
  return {
    security:(parameters={},signal) => get(`security?${queryString(parameters)}`,signal),
    createStop:(body,signal) => post('security/stops',body,signal),
    resume:(id,body,signal) => post(`security/stops/${Number(id)}/resume`,body,signal),
    direct:(id,body,signal) => client.mutate(`/operations/security/accounts/${Number(id)}`,'PUT',body,signal),
    restoreGlobal:(body,signal) => post('security/restore-global',body,signal),
    times:signal => get('account-times',signal),
    time:(id,signal) => get(`account-times/${Number(id)}`,signal),
    saveTime:(id,body,signal) => client.mutate(`/operations/account-times/${Number(id)}`,'PUT',body,signal),
    activity:signal => post('activity',{},signal),
    archives:(parameters={},signal) => get(`archive?${queryString(parameters)}`,signal),
    archive:(id,signal) => get(`archive/${Number(id)}`,signal),
    eligibility:(id,signal) => get(`archive/accounts/${Number(id)}/eligibility`,signal),
    createArchive:(id,body,signal) => post(`archive/accounts/${Number(id)}`,body,signal),
  };
}
