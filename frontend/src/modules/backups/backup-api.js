export function createBackupApi(client) {
  const longRequest = { timeoutMs: 300000 };
  return {
    list: signal => client.request('/backups', {signal}),
    create: signal => client.mutate('/backups', 'POST', {}, signal, longRequest),
    preview: (source,signal) => {
      if(source instanceof FormData) return client.mutate('/backups/previews','POST',source,signal,longRequest);
      return client.mutate('/backups/previews','POST',{backup_id:source},signal,longRequest);
    },
    restore: (data,signal) => client.mutate('/backups/restore-jobs','POST',data,signal),
    job: (id,signal) => client.request('/backups/restore-jobs/'+encodeURIComponent(id),{signal}),
  };
}