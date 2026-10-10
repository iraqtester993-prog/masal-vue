export function createPreferencesApi(client) {
  return {read:signal=>client.request('/preferences',{signal}),save:(body,signal)=>client.mutate('/preferences','PUT',body,signal)};
}
