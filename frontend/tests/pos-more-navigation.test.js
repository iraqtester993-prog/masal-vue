import test from 'node:test';
import assert from 'node:assert/strict';
import {posMoreNavigation} from '../src/layouts/pos-more-navigation.js';
import {navigationGroups} from '../src/layouts/navigation.js';

const identity = (type = 'pos', kind = 'owner') => ({user: {id: 1}, account: {id: 2, type}, membership: {kind}});
const route = (name, permission, extra = {}) => ({
  name, path: `/${name}`, meta: {requiresAuth: true, ...(permission ? {permission} : {}), ...extra},
});
const routes = [
  route('dashboard', 'dashboard.view'),
  route('sell', 'sell.create', {accountTypes: ['sub_agent', 'sub_branch', 'pos']}),
  route('sales', 'sales.view'),
  route('reports', 'reports.view'),
  route('company', 'company.view'),
  route('wallets', 'wallets.view'),
  route('profile'),
  route('products', 'products.view'),
  route('prices', 'prices.view'),
  route('digital', 'digital.view'),
  route('topupAvailable', 'digital.view', {navigationId: 'topupAvailable', accountTypes: ['pos']}),
  route('exceptions', 'exceptions.view'),
  route('pos', 'account.view'),
  route('map', 'map.view'),
  route('support', 'support.view'),
  route('notifications', 'notifications.view'),
  route('staff', 'staff.view'),
  route('permissions', 'permission_profile.view'),
  route('governorates', 'governorates.view', {accountTypes: ['system'], membershipKinds: ['owner']}),
  route('topupDistribution', 'digital.assign', {accountTypes: ['main_agent', 'sub_agent', 'sub_branch']}),
  route('branding', 'branding.view', {accountTypes: ['system', 'main_agent']}),
];
const defaultGrants = [
  'dashboard.view', 'sell.create', 'sales.view', 'reports.view', 'company.view', 'wallets.view',
  'digital.view', 'exceptions.view', 'account.view', 'support.view', 'notifications.view',
  'staff.view', 'permission_profile.view',
];
function navigation(grants = defaultGrants, options = {}) {
  const allowed = new Set(grants);
  return posMoreNavigation({routes, identity: identity(), can: permission => allowed.has(permission), ...options});
}
const ids = items => items.map(item => item.id);
const without = (...permissions) => defaultGrants.filter(permission => !permissions.includes(permission));

test('the normal POS menu removes duplicate service entries and hides the three management destinations', () => {
  const result = navigation();
  assert.deepEqual(result.services, []);
  assert.deepEqual(ids(result.shortcuts), ['company', 'support', 'notifications']);
  assert.deepEqual(result.profile.to, {name: 'profile'});
  const all = [result.profile, ...result.services, ...result.shortcuts];
  assert.equal(new Set(ids(all)).size, all.length);
});

test('wallets and granted Topup categories remain directly accessible when the home permission is missing', () => {
  const result = navigation(without('dashboard.view'));
  assert.deepEqual(ids(result.services), ['wallets', 'topupAvailable']);
});

test('unregistered and restricted home routes cannot hide the only wallet and Topup entries', () => {
  const variants = [
    routes.filter(item => item.name !== 'dashboard'),
    routes.map(item => item.name === 'dashboard' ? route('dashboard', 'dashboard.view', {accountTypes: ['system']}) : item),
    routes.map(item => item.name === 'dashboard' ? route('dashboard', 'dashboard.view', {membershipKinds: ['owner']}) : item),
  ];
  for (const registered of variants) {
    const result = navigation(defaultGrants, {routes: registered, identity: identity('pos', 'employee')});
    assert.ok(ids(result.services).includes('wallets'));
    assert.ok(ids(result.services).includes('topupAvailable'));
  }
});

test('the print handling and digital entries remain when the combined operations history is not authorized', () => {
  const result = navigation(without('sales.view'));
  assert.deepEqual(ids(result.services), ['digital', 'exceptions']);
});

test('digital readers retain their direct entry without a permitted seller even if history is available', () => {
  const result = navigation(without('sell.create'));
  assert.deepEqual(ids(result.services), ['digital']);
  assert.deepEqual(result.services.find(item => item.id === 'digital').to, {name: 'digital'});
});

test('missing or restricted alternate seller and history routes cannot suppress their service fallback', () => {
  for (const missing of ['sell', 'sales']) {
    const result = navigation(defaultGrants, {routes: routes.filter(item => item.name !== missing)});
    assert.ok(ids(result.services).includes('digital'));
    if (missing === 'sales') assert.ok(ids(result.services).includes('exceptions'));
  }
  const restricted = routes.map(item => ['sell', 'sales'].includes(item.name)
    ? route(item.name, item.meta.permission, {membershipKinds: ['owner']}) : item);
  const result = navigation(defaultGrants, {routes: restricted, identity: identity('pos', 'employee')});
  assert.ok(ids(result.services).includes('digital'));
  assert.ok(ids(result.services).includes('exceptions'));
});

test('limited users keep exactly their permitted unique service and no management grant is invented', () => {
  const cases = [
    [['wallets.view'], ['wallets']],
    [['digital.view'], ['digital', 'topupAvailable']],
    [['exceptions.view'], ['exceptions']],
    [['staff.view'], []],
    [['permission_profile.view'], []],
    [['account.view'], []],
    [['products.view'], ['products']],
    [['prices.view'], ['prices']],
    [['map.view'], ['map']],
    [[], []],
  ];
  for (const [grants, expected] of cases) {
    const result = navigation(grants, {identity: identity('pos', 'employee')});
    assert.deepEqual(ids(result.services), expected, grants.join(', '));
    assert.deepEqual(result.shortcuts, []);
    assert.equal(result.profile.id, 'profile');
  }
});

test('authorized unique catalog and map routes remain in the direct list while other account types stay excluded', () => {
  const result = navigation([...defaultGrants, 'products.view', 'prices.view', 'map.view', 'governorates.view', 'digital.assign', 'branding.view']);
  assert.deepEqual(ids(result.services), ['products', 'prices', 'map']);
  assert.ok(!ids(result.services).some(id => ['governorates', 'topupDistribution', 'branding'].includes(id)));
});

test('the three management destinations stay hidden in More even with every grant and every route available', () => {
  for (const kind of ['owner', 'employee']) {
    const result = navigation(defaultGrants, {can: () => true, identity: identity('pos', kind)});
    assert.deepEqual(ids(result.services), ['products', 'prices', 'map']);
    const displayed = [result.profile, ...result.services, ...result.shortcuts].filter(Boolean);
    assert.ok(!ids(displayed).some(id => ['pos', 'users', 'permissions'].includes(id)));
  }
});

test('More visibility does not change the shared management navigation or grant permissions to limited users', () => {
  const grants = ['account.view', 'staff.view', 'permission_profile.view'];
  const before = [...grants];
  for (const type of ['pos', 'main_agent', 'system']) {
    const actor = identity(type), can = permission => grants.includes(permission);
    const shared = navigationGroups({routes, identity: actor, can}).flatMap(group => group.items);
    for (const id of ['pos', 'users', 'permissions']) assert.ok(ids(shared).includes(id));
    assert.deepEqual(navigation(grants, {identity: actor}).services, []);
    const unchanged = navigationGroups({routes, identity: actor, can}).flatMap(group => group.items);
    assert.deepEqual(unchanged, shared);
  }
  assert.deepEqual(grants, before);
  assert.deepEqual(navigation([]).services, []);
});

test('shortcut authorization is preserved and each selected route remains in just one section', () => {
  const result = navigation(['support.view', 'notifications.view']);
  assert.deepEqual(ids(result.shortcuts), ['support', 'notifications']);
  assert.deepEqual(result.services, []);
  const denied = navigation(['support.view'], {routes: routes.map(item => item.name === 'support'
    ? route('support', 'support.view', {membershipKinds: ['owner']}) : item), identity: identity('pos', 'employee')});
  assert.deepEqual(denied.shortcuts, []);
});

test('profile appears once even when it is also supplied by the main navigation registry', () => {
  const result = navigation(defaultGrants, {identity: identity('main_agent')});
  assert.equal(result.profile.id, 'profile');
  assert.equal(result.services.some(item => item.id === 'profile'), false);
  assert.equal(result.shortcuts.some(item => item.id === 'profile'), false);
});

test('the existing own-profile entry respects its real route authorization and registration', () => {
  const variants = [
    routes.filter(item => item.name !== 'profile'),
    routes.map(item => item.name === 'profile' ? {...item, redirect: '/dashboard'} : item),
    routes.map(item => item.name === 'profile' ? {...item, meta: {}} : item),
    routes.map(item => item.name === 'profile' ? route('profile', 'profile.private') : item),
    routes.map(item => item.name === 'profile' ? route('profile', null, {accountTypes: ['system']}) : item),
    routes.map(item => item.name === 'profile' ? route('profile', null, {membershipKinds: ['owner']}) : item),
  ];
  for (const registered of variants) {
    assert.equal(navigation(defaultGrants, {routes: registered, identity: identity('pos', 'employee')}).profile, null);
  }
  const approved = navigation(['profile.private'], {routes: [route('profile', 'profile.private')]});
  assert.equal(approved.profile.id, 'profile');
  assert.equal(navigation(defaultGrants, {identity: null}).profile, null);
  assert.deepEqual(navigation(defaultGrants, {identity: null}).services, []);
});
