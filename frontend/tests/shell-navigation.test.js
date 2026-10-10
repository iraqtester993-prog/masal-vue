import test from "node:test";
import assert from "node:assert/strict";
import {
  NAVIGATION_DESTINATIONS,
  NAVIGATION_GROUPS,
  navigationGroups,
  newNavigationState,
  synchronizeNavigation,
  toggleNavGroup,
  toggleSidebar,
} from "../src/layouts/navigation.js";

const identity = (type = "system") => ({ user: { id: 1 }, account: { id: 1, type }, membership:{kind:"owner"} });
const route = (name, permission) => ({ name, path: `/${name}`, meta: { requiresAuth: true, ...(permission ? { permission } : {}) } });
const visibleIds = (groups) => groups.flatMap((group) => group.items.map((item) => item.id));

test('POS history is discoverable only with current read grants and translated navigation search works',()=>{
  const routes=[route('sales'),route('sell'),route('dashboard')];
  const ids=visibleIds(navigationGroups({routes,identity:identity('pos'),can:()=>true}));
  assert.ok(ids.includes('sales'));
  assert.ok(!visibleIds(navigationGroups({routes,identity:identity('pos'),can:permission=>permission!=='sales.view'})).includes('sales'));
  assert.ok(!visibleIds(navigationGroups({routes,identity:identity('main_agent'),can:()=>true})).includes('sales'));
  assert.deepEqual(visibleIds(navigationGroups({routes,identity:identity('pos'),can:()=>true,search:'transactions',labelFor:value=>value==='سجل العمليات'?'Transactions':value})),['sales']);
});

test("final original navigation retains original destinations and adds the isolated Topup category group", () => {
  assert.equal(NAVIGATION_DESTINATIONS.length, 37);
  const groups = navigationGroups({ routes: NAVIGATION_DESTINATIONS.map((item) => route(item.id)), identity: identity(), can: () => true });
  assert.deepEqual(groups.map((group) => group.title), ["نظرة عامة", "البطاقات والمخزون", "عمليات البطاقات", "Topup — آسياسيل", "شبكة التوزيع", "التواصل والدعم", "المستخدمون والصلاحيات", "إعدادات النظام", "إعدادات البطاقات والموقع"]);
  assert.equal(new Set(visibleIds(groups)).size, 34);
  assert.equal(visibleIds(groups).length, 34);
  assert.deepEqual(groups.find((group) => group.id === "catalog").items.map((item) => item.id), ["inventory", "import", "products", "prices", "providers", "sources"]);
  assert.equal(groups[0].direct, true);
  assert.equal(groups.slice(1).some((group) => group.direct), false);
});

test("routes, authorization and account portal restrictions must all allow a destination", () => {
  const routes = [route("dashboard"), route("products"), route("providers"), route("agents"), route("pos"), route("permissions"), { name: "sell", path: "/sell", redirect: "/dashboard", meta: {} }];
  const allowed = new Set(["products.view", "providers.view", "account.view"]);
  const can = (permission) => allowed.has(permission);
  assert.deepEqual(visibleIds(navigationGroups({ routes, identity: identity(), can })), ["dashboard", "products", "providers", "agents", "pos"]);
  assert.deepEqual(visibleIds(navigationGroups({ routes, identity: identity("pos"), can })), ["dashboard", "products", "pos"]);
  assert.deepEqual(visibleIds(navigationGroups({ routes, identity: identity("main_agent"), can })), ["dashboard", "products", "agents", "pos"]);
  assert.deepEqual(navigationGroups({ routes, identity: null, can: () => true }), []);
  assert.equal(visibleIds(navigationGroups({ routes, identity: identity(), can: () => true })).includes("sell"), false);
});

test("registered route permission metadata and navigation ids support isolated module names without bypassing authorization", () => {
  const sources = { ...route("reference-sources", "reference.view"), meta: { requiresAuth: true, navigationId: "sources", permission: "reference.view", accountTypes: ["system"] } };
  const routes = [sources, route("staff", "staff.view")];
  const groups = navigationGroups({ routes, identity: identity(), can: (permission) => ["reference.view", "staff.view"].includes(permission) });
  assert.deepEqual(visibleIds(groups), ["sources", "users"]);
  assert.deepEqual(groups[0].items[0].to, { name: "reference-sources" });
  assert.deepEqual(groups[1].items[0].to, { name: "staff" });
  assert.deepEqual(navigationGroups({ routes, identity: identity("main_agent"), can: (permission) => permission === "reference.view" }), []);
  assert.deepEqual(navigationGroups({ routes, identity: identity(), can: () => false }), []);
});

test("search shows permitted labels only, preserves their groups and never creates unavailable links", () => {
  const routes = [route("dashboard"), route("products"), route("providers"), route("agents"), route("pos"), route("staff")];
  const groups = navigationGroups({ routes, identity: identity(), can: (permission) => permission !== "products.view", search: "  البيع  " });
  assert.deepEqual(visibleIds(groups), ["pos"]);
  assert.equal(groups[0].id, "network");
  assert.deepEqual(navigationGroups({ routes, identity: identity(), can: () => false, search: "الفئات" }), []);
  assert.deepEqual(navigationGroups({ routes, identity: identity(), can: () => true, search: "لا يوجد" }), []);
});

test("owner-only route metadata excludes employees even when they have the same grant", () => {
  const governorates = { ...route("governorates", "reference.view"), meta: { requiresAuth: true, permission: "reference.view", accountTypes: ["system"], membershipKinds: ["owner"] } };
  const allowed = (kind, type = "system") => navigationGroups({ routes: [governorates], identity: { ...identity(type), membership: { kind } }, can: () => true });
  assert.deepEqual(visibleIds(allowed("owner")), ["governorates"]);
  assert.deepEqual(allowed("employee"), []);
  assert.deepEqual(allowed(undefined), []);
  assert.deepEqual(allowed("owner", "main_agent"), []);
});

test("accordion interaction opens a single group, toggles it closed and expands a collapsed rail", () => {
  const state = newNavigationState();
  assert.equal(Object.values(state.collapsedGroups).every(Boolean), true);
  toggleNavGroup(state, "catalog");
  assert.equal(state.collapsedGroups.catalog, false);
  toggleNavGroup(state, "network");
  assert.equal(state.collapsedGroups.catalog, true);
  assert.equal(state.collapsedGroups.network, false);
  toggleNavGroup(state, "network");
  assert.equal(state.collapsedGroups.network, true);
  state.collapsed = true;
  state.hoverOpen = true;
  toggleNavGroup(state, "network");
  assert.equal(state.collapsed, false);
  assert.equal(state.hoverOpen, false);
  assert.equal(state.collapsedGroups.network, false);
  toggleNavGroup(state, "overview");
  assert.equal(state.collapsedGroups.network, false);
});

test("mobile hamburger state stays separate from the desktop rail and navigation closes it", () => {
  const state = newNavigationState();
  toggleSidebar(state);
  assert.equal(state.collapsed, true);
  state.mobile = true;
  toggleSidebar(state);
  assert.equal(state.menuOpen, true);
  assert.equal(state.collapsed, true);
  const groups = navigationGroups({ routes: [route("products"), route("agents")], identity: identity(), can: () => true });
  synchronizeNavigation(state, groups, "agents");
  assert.equal(state.menuOpen, false);
  assert.equal(state.collapsedGroups.network, false);
  assert.equal(state.collapsedGroups.catalog, true);
  state.mobile = false;
  state.hoverOpen = true;
  toggleSidebar(state);
  assert.equal(state.collapsed, false);
  assert.equal(state.hoverOpen, false);
});

test("navigating among pages restores the active section without changing the original group order", () => {
  const state = newNavigationState();
  const groups = navigationGroups({ routes: [route("products"), route("agents"), route("staff")], identity: identity(), can: () => true });
  synchronizeNavigation(state, groups, "products");
  assert.equal(state.collapsedGroups.catalog, false);
  synchronizeNavigation(state, groups, "staff");
  assert.equal(state.collapsedGroups.catalog, true);
  assert.equal(state.collapsedGroups.identity, false);
  assert.deepEqual(groups.map((group) => group.id), ["catalog", "network", "identity"]);
  assert.equal(NAVIGATION_GROUPS[0].id, "overview");
});

test("Topup distribution separates owner, downstream agents and POS surfaces", () => {
  const routes = NAVIGATION_DESTINATIONS.filter(item => item.id.startsWith('topup')).map(item => route(item.id));
  const ids = (type, kind='owner', can=()=>true) => visibleIds(navigationGroups({routes,identity:{...identity(type),membership:{kind}},can}));
  assert.deepEqual(ids('system'), ['topupCategories','topupAllocation']);
  assert.deepEqual(ids('system','employee'), []);
  for (const type of ['main_agent','sub_agent','sub_branch']) assert.deepEqual(ids(type), ['topupDistribution']);
  assert.deepEqual(ids('pos'), ['topupAvailable']);
  assert.deepEqual(ids('main_agent','owner',()=>false), []);
});
