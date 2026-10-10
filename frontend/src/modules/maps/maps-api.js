export function createMapsApi(client) {
  const parameters = values => {
    const query = new URLSearchParams();
    for (const [key,value] of Object.entries(values||{})) {
      if (Array.isArray(value)) value.forEach((id,index) => query.append(`${key}[${index}]`,String(id)));
      else if(value!==''&&value!==null&&value!==undefined) query.append(key,String(value));
    }
    return query.toString();
  };
  return {
    users:(filters={},signal) => client.request(`/maps/users?${parameters(filters)}`,{signal}),
    own:signal => client.request('/maps/own',{signal}),
    heartbeat:(metadata={},signal) => client.mutate('/maps/heartbeat','POST',metadata,signal),
    locate:(body,signal) => client.mutate('/maps/location','POST',body,signal),
    disconnect:(signal,options={}) => client.mutate('/maps/disconnect','POST',{},signal,{timeoutMs:3000,...options}),
    saveLocation:(id,body,signal) => client.mutate(`/maps/accounts/${Number(id)}/location`,'PUT',body,signal),
  };
}
