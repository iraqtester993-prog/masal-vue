import {navigationGroups} from './navigation.js';
import {routeAllowed} from '../router/access.js';

const bottomTabs = new Set(['dashboard', 'sell', 'sales', 'reports']);
const shortcutIds = ['company', 'support', 'notifications'];
const hiddenServices = new Set(['pos', 'users', 'permissions']);

export function posMoreNavigation({routes, identity, can}) {
  const items = navigationGroups({routes, identity, can}).flatMap(group => group.items);
  const available = new Set(items.map(item => item.id));
  const shortcuts = shortcutIds.map(id => items.find(item => item.id === id)).filter(Boolean);
  const profileRoute = routes.find(route => route.name === 'profile' && !route.redirect && route.meta?.requiresAuth);
  const profile = items.find(item => item.id === 'profile')
    || (identity?.user && identity?.account && profileRoute && routeAllowed(profileRoute, {state: {identity}, can})
      ? {id: 'profile', label: 'حسابي', to: {name: profileRoute.name}} : null);

  const services = items.filter(item => {
    if (bottomTabs.has(item.id) || shortcutIds.includes(item.id) || hiddenServices.has(item.id) || item.id === 'profile') return false;
    if (available.has('dashboard') && ['topupAvailable', 'wallets'].includes(item.id)) return false;
    if (available.has('sales') && item.id === 'exceptions') return false;
    // The unified seller and history together cover the digital page. A reader
    // without either destination still needs its direct digital entry.
    if (item.id === 'digital' && available.has('sell') && (can('sell.create')||can('digital.view'))
      && available.has('sales') && (can('sales.view')||can('digital.view'))) return false;
    return true;
  });

  return {profile, shortcuts, services};
}
