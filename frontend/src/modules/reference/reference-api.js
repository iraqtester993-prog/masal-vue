export function createReferenceApi(api) {
  const query = (values = {}) => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null)).toString();
  return {
    options: (network, signal) => api.request(`/reference/options?${query({network_account_id: network})}`, {signal}),
    posOptions: (parent, signal) => api.request(`/reference/options?${query({agent_account_id: parent})}`, {signal}),
    list: (kind, parameters, signal) => api.request(`/reference/${kind}?${query(parameters)}`, {signal}),
    getProfile: (id, signal) => api.request(`/accounts/${id}/reference-profile`, {signal}),
    save: (kind, id, payload, signal) => api.mutate(`/reference/${kind}${id ? `/${id}` : ''}`, id ? 'PATCH' : 'POST', payload, signal),
    status: (kind, row, signal) => api.mutate(`/reference/${kind}/${row.id}/status`, 'PATCH', {version: row.version, status: row.active ? 'disabled' : 'active'}, signal),
    uploadPhoto: (id, version, file, signal) => { const body = new FormData(); body.set('version', String(version)); body.set('file', file); return api.mutate(`/reference/representatives/${id}/photos`, 'POST', body, signal); },
    deletePhoto: (id, photoId, version, signal) => api.mutate(`/reference/representatives/${id}/photos/${photoId}`, 'DELETE', {version}, signal),
    profile: (id, payload, signal) => api.mutate(`/accounts/${id}/reference-profile`, 'PUT', payload, signal),
    export: (kind, parameters, signal) => api.request(`/reference/${kind}/export?${query(parameters)}`, {signal}),
  };
}

export function referenceKind(routeName) {
  return {governorates: 'governorates', sources: 'sources', 'pos-types': 'pos-types', representatives: 'representatives'}[routeName];
}

export function referencePermission(kind) {
  return kind === 'pos-types' ? 'posTypes' : kind;
}

export function referenceDraft(kind, row = null) {
  const shared = row ? {id: row.id, version: row.version} : {};
  if (kind === 'sources') return {...shared, name: row?.name || '', provider_id: row?.provider_id || '', network_account_id: row?.network_account_id || ''};
  if (kind === 'pos-types') return {...shared, name: row?.name || '', status: row?.status || 'active'};
  return {...shared, name: row?.name || '', phone: row?.phone || '', address: row?.address || '', agent_account_id: row?.agent_account_id || ''};
}

export function referencePayload(kind, draft, network) {
  const payload = {name: draft.name.trim(), ...(draft.id ? {version: draft.version} : {})};
  if (kind === 'sources') { payload.provider_id = Number(draft.provider_id); if (!draft.id && network) payload.network_account_id = Number(network); }
  else if (kind === 'pos-types') payload.status = draft.status;
  else Object.assign(payload, {phone: draft.phone.trim(), address: draft.address.trim(), agent_account_id: Number(draft.agent_account_id)});
  return payload;
}
