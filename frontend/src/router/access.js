import {passwordChangeRequired} from '../modules/auth/password-reset.js';

export function routeAllowed(route, session) {
  const identity = session.state.identity;
  if (!identity) return !route.meta?.requiresAuth;
  if (passwordChangeRequired(identity)) return route.name === 'password-change';
  const meta = route.meta || {};
  return (!meta.permission || session.can(meta.permission))
    && (!meta.accountTypes || meta.accountTypes.includes(identity.account.type))
    && (!meta.membershipKinds || meta.membershipKinds.includes(identity.membership?.kind));
}

export function landingRoute(router, session) {
  if (passwordChangeRequired(session.state.identity)) return {name:'password-change'};
  const routes = router.getRoutes();
  for (const name of ['dashboard', 'sell', 'digital', 'sales', 'wallets', 'support']) {
    const route = routes.find(item => item.name === name);
    if (route && routeAllowed(route, session)) return {name};
  }
  const route = routes.find(item => item.name && item.meta?.permission && routeAllowed(item, session));
  return {name: route?.name || 'no-access'};
}

export function createSessionNavigationGuard(session, router) {
  return async (to, from) => {
    if (to.name === 'session-unavailable') {
      if (session.state.accessConfirmed) return landingRoute(router, session);
      return;
    }
    if (!session.state.checked) await session.refresh();
    if (!session.state.checked && session.state.error) {
      if (session.state.identity && from.matched.length) return false;
      return {name:'session-unavailable',query:{next:to.fullPath}};
    }
    if (to.meta.requiresAuth && !session.state.identity) return {name:'login'};
    if (passwordChangeRequired(session.state.identity) && to.name !== 'password-change') return {name:'password-change'};
    if (to.name === 'password-change' && session.state.identity && !passwordChangeRequired(session.state.identity)) return landingRoute(router,session);
    if (to.name === 'login' && session.state.identity) return landingRoute(router, session);
    if (to.name === 'agents' && session.state.identity?.account.type === 'pos') return {name:'pos'};
    if (!routeAllowed(to, session)) return landingRoute(router, session);
  };
}
