export function routeAllowed(route, session) {
  const identity = session.state.identity;
  if (!identity) return !route.meta?.requiresAuth;
  const meta = route.meta || {};
  return (!meta.permission || session.can(meta.permission))
    && (!meta.accountTypes || meta.accountTypes.includes(identity.account.type))
    && (!meta.membershipKinds || meta.membershipKinds.includes(identity.membership?.kind));
}

export function landingRoute(router, session) {
  const routes = router.getRoutes();
  for (const name of ['dashboard', 'sell', 'digital', 'sales', 'wallets', 'support']) {
    const route = routes.find(item => item.name === name);
    if (route && routeAllowed(route, session)) return {name};
  }
  const route = routes.find(item => item.name && item.meta?.permission && routeAllowed(item, session));
  return {name: route?.name || 'no-access'};
}
