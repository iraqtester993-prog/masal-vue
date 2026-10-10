export function allowedReportRoute(routes, name, identity, can) {
  const route = routes.find((candidate) => candidate.name === name && !candidate.redirect);
  if (!route || !identity?.account || !identity?.membership) return null;
  const meta = route.meta || {};
  if (meta.permission && !can(meta.permission)) return null;
  if (meta.accountTypes && !meta.accountTypes.includes(identity.account.type)) return null;
  if (meta.membershipKinds && !meta.membershipKinds.includes(identity.membership.kind)) return null;
  return route;
}

export function reportDestination(sectionId, routes, identity, can, filters = {}) {
  const special = {
    'sales-services': ['digital'],
    'operations-integrations': ['integrations', 'digital'],
    'network-archive': ['deletedAccounts', 'archive'],
    'network-presence': ['map'],
    'users-times': ['accountTime'],
    'operations-security': ['security'],
    'operations-printing': ['printPolicies'],
    'operations-notifications': ['notifications'],
    'operations-sessions': ['staff'],
    'network-categories': ['pos-types'],
  };
  const prefix = sectionId.split('-')[0];
  const general = { network: sectionId === 'network-pos' ? 'pos' : 'agents', inventory: 'inventory', claims: 'claims', wallets: 'wallets', sales: 'sales', prices: 'prices', support: 'support', users: 'staff', audit: 'audit', operations: 'security' };
  const setup = identity?.account?.type === 'system' && identity?.membership?.kind === 'owner' && can('integrations.edit');
  const names = (special[sectionId] || [general[prefix]]).filter((name) => name !== 'integrations' || setup);
  const route = names.map((name) => allowedReportRoute(routes, name, identity, can)).find(Boolean);
  if (!route) return null;
  let query = { ...filters };
  if (route.name === 'digital' || route.name === 'integrations') {
    query = Object.fromEntries(['from', 'to', 'product_id', 'status', 'q'].filter((key) => filters[key] !== undefined && filters[key] !== '').map((key) => [key, filters[key]]));
    if (filters.pos_id) query.account_id = filters.pos_id;
    if (filters.main_account_id) query.main_account_id = filters.main_account_id;
    else if (identity.account.type === 'main_agent' && Number(filters.agent_id) === Number(identity.account.id)) query.main_account_id = identity.account.id;
    const assign = ['main_agent', 'sub_agent', 'sub_branch'].includes(identity.account.type) && can('digital.assign');
    query.tab = sectionId === 'operations-integrations' && (setup || assign) ? 'settings' : 'log';
  } else if (route.name === 'map') {
    query = {};
    if (filters.agent_id || filters.pos_id) query.branch_id = filters.pos_id || filters.agent_id;
    if (filters.q) query.query = filters.q;
  }
  return { route, to: { name: route.name, query } };
}

export function reportColumnHelp(column) {
  return [...new Set([column.reason, column.source_note].filter(Boolean))].join(' ');
}

export function reportSourceNotes(columns = []) {
  return [...new Set(columns.flatMap((column) => [column.reason, column.source_note]).filter(Boolean))];
}
