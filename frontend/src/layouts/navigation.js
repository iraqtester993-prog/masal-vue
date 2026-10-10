// Destination labels, order and groups follow the final legacy navigation.
// Routes are shown only after their real page is registered and authorized.
export const NAVIGATION_DESTINATIONS = Object.freeze([
  { id: "dashboard", label: "لوحة التحكم", permission: null },
  { id: "reports", label: "مركز التقارير والعمليات" },
  { id: "company", label: "موقع الشركة" },
  { id: "wallets", label: "المحافظ والتحويلات" },
  { id: "inventory", label: "المخزون" },
  { id: "import", label: "الطلبيات" },
  { id: "products", label: "الفئات" },
  { id: "prices", label: "الأسعار" },
  { id: "providers", label: "الشركات", accountTypes: ["system"] },
  { id: "sources", label: "المصادر" },
  { id: "sell", label: "البيع والطباعة" },
  { id: "sales", label: "سجل العمليات", accountTypes: ["pos"] },
  { id: "digital", label: "خدمات API" },
  { id: "topupAllocation", label: "تخصيص الفئات للوكلاء", path: "/topup/allocation", permission: "integrations.edit", accountTypes: ["system"], membershipKinds: ["owner"] },
  { id: "topupDistribution", label: "توزيع الفئات للتابعين", path: "/topup/distribution", permission: "digital.assign", accountTypes: ["main_agent", "sub_agent", "sub_branch"] },
  { id: "topupAvailable", label: "الفئات الممنوحة", path: "/topup/available", permission: "digital.view", accountTypes: ["pos"] },
  { id: "topupCategories", label: "فئات الشركة", path: "/topup/categories", permission: "integrations.edit", accountTypes: ["system"], membershipKinds: ["owner"] },
  { id: "exports", label: "تصدير البطاقات" },
  { id: "claims", label: "البطاقات التالفة" },
  { id: "exceptions", label: "استثناءات الطباعة" },
  { id: "agents", label: "الوكلاء والفروع", permission: "account.view", excludedAccountTypes: ["pos"] },
  { id: "pos", label: "نقاط البيع والأجهزة", permission: "account.view" },
  { id: "representatives", label: "المندوبون" },
  { id: "posTypes", label: "أنواع نقاط البيع", path: "/pos-types" },
  { id: "map", label: "خريطة المستخدمين" },
  { id: "support", label: "الدعم الفني" },
  { id: "notifications", label: "الإشعارات والتنبيهات" },
  { id: "users", label: "مستخدمو النظام", routeNames: ["users", "staff"], permission: "staff.view" },
  { id: "permissions", label: "أنواع الصلاحيات", permission: "permission_profile.view" },
  { id: "accountTime", label: "أوقات صلاحية الحساب", path: "/account-times" },
  { id: "deletedAccounts", label: "أرشيف المحذوفات", path: "/deleted-accounts" },
  { id: "security", label: "الحماية والأمان" },
  { id: "backup", label: "النسخ الاحتياطي" },
  { id: "governorates", label: "المحافظات" },
  { id: "branding", label: "تصميم البطاقة" },
  { id: "printPolicies", label: "ضوابط الطباعة", path: "/print-policies" },
  { id: "company-settings", label: "تعديل بروفايل الشركة", permission: "company.edit", accountTypes: ["system"] },
].map((item) => Object.freeze({
  path: `/${item.id}`,
  routeNames: [item.id],
  permission: `${item.id}.view`,
  ...item,
})));

export const NAVIGATION_GROUPS = Object.freeze([
  { id: "overview", title: "نظرة عامة", direct: true, icon: "dashboard", ids: ["dashboard", "reports", "company", "wallets"] },
  { id: "catalog", title: "البطاقات والمخزون", icon: "inventory", ids: ["inventory", "import", "products", "prices", "providers", "sources"] },
  { id: "operations", title: "عمليات البطاقات", icon: "sell", ids: ["sell", "sales", "digital", "exports", "claims", "exceptions"] },
  { id: "topup", title: "Topup — آسياسيل", icon: "sell", ids: ["topupCategories", "topupAllocation", "topupDistribution", "topupAvailable"] },
  { id: "network", title: "شبكة التوزيع", icon: "agents", ids: ["agents", "pos", "representatives", "posTypes", "map"] },
  { id: "communication", title: "التواصل والدعم", icon: "support", ids: ["support", "notifications"] },
  { id: "identity", title: "المستخدمون والصلاحيات", icon: "users", ids: ["users", "permissions", "accountTime", "deletedAccounts"] },
  { id: "system", title: "إعدادات النظام", icon: "security", ids: ["security", "backup", "governorates"] },
  { id: "appearance", title: "إعدادات البطاقات والموقع", icon: "branding", ids: ["branding", "printPolicies", "company-settings"] },
].map((group) => Object.freeze({ ...group, ids: Object.freeze(group.ids) })));

const icons = Object.freeze({
  company: "M4 21V4h16v17M8 8h3m3 0h2M8 12h3m3 0h2M9 21v-5h6v5",
  dashboard: "M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z",
  reports: "M4 3v18h17M8 16v-5m5 5V6m5 10V9",
  sell: "M3 5h18v14H3ZM3 10h18M7 15h3",
  sales: "M8 3H5v18h14V3h-3M9 3h6v4H9ZM8 11h8m-8 4h8",
  inventory: "m3 7 9-4 9 4-9 4ZM3 7v10l9 4 9-4V7M12 11v10",
  import: "M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5",
  products: "M3 3h7v7H3Zm11 0h7v7h-7ZM3 14h7v7H3Zm11 0h7v7h-7Z",
  providers: "M4 21V4h11v17M15 10h5v11M2 21h20M8 8h3m-3 4h3m-3 4h3",
  prices: "M3 7h18M3 17h18M8 3v8m8 2v8",
  exceptions: "m12 3 10 18H2ZM12 9v5m0 3h.01",
  claims: "M6 3h9l4 4v14H6ZM14 3v5h5M9 12h7m-7 4h5",
  exports: "M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6",
  agents: "M9 3h6v5H9ZM3 16h6v5H3Zm12 0h6v5h-6ZM12 8v4M6 16v-4h12v4",
  pos: "M5 3h14v18H5ZM8 6h8v6H8Zm0 10h1m3 0h1m3 0h.01",
  wallets: "M3 6h17v15H3ZM3 6V3h14v3m-2 7h6v5h-6ZM17 15h.01",
  map: "m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2ZM9 3v16m6-14v16",
  support: "M4 13v-2a8 8 0 0 1 16 0v2M4 12H2v6h4v-6Zm16 0h2v6h-4v-6ZM20 18v3h-7",
  notifications: "M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6",
  users: "M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-3a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v3",
  permissions: "M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6ZM8 12l3 3 5-6",
  security: "M5 10h14v11H5ZM8 10V6a4 4 0 0 1 8 0v4M12 14v3",
  audit: "M5 3h14v18H5ZM8 7h8m-8 5h8m-8 5h5",
  monitoring: "M3 3h18v14H3ZM8 21h8m-4-4v4M5 11h3l2-5 4 8 2-4h3",
  branding: "M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 1-4 2 2 0 0 1 1-4h2a3 3 0 0 0 3-3 9 9 0 0 0-9-7ZM7 9h.01M11 6h.01M16 7h.01M6 14h.01",
  backup: "M7 18H5a4 4 0 0 1-1-8 8 8 0 0 1 15-1 4.5 4.5 0 0 1 0 9h-2M12 12v9m-3-3 3 3 3-3",
});

export function navIconPath(id) {
  return icons[id] || "M4 4h16v16H4Z";
}

export function navigationGroups({ routes, identity, can, search = "", labelFor = value => value }) {
  if (!identity?.account || !identity?.user) return [];
  const query = search.trim().toLocaleLowerCase("ar");
  const registered = new Map();
  for (const item of NAVIGATION_DESTINATIONS) {
    const route = routes.find((candidate) => candidate.meta?.navigationId === item.id)
      || item.routeNames.map((name) => routes.find((candidate) => candidate.name === name)).find(Boolean)
      || routes.find((candidate) => candidate.path === item.path && !candidate.redirect);
    if (!route || !route.meta?.requiresAuth) continue;
    const type = identity.account.type;
    if (item.accountTypes && !item.accountTypes.includes(type)) continue;
    if (item.membershipKinds && !item.membershipKinds.includes(identity.membership?.kind)) continue;
    if (item.excludedAccountTypes?.includes(type)) continue;
    if (route.meta.accountTypes && !route.meta.accountTypes.includes(type)) continue;
    if (route.meta.membershipKinds && !route.meta.membershipKinds.includes(identity.membership?.kind)) continue;
    const permission = route.meta.permission ?? item.permission;
    if (permission && !can(permission)) continue;
    if (query && !`${item.label} ${labelFor(item.label)}`.toLocaleLowerCase().includes(query)) continue;
    registered.set(item.id, {
      ...item,
      routeName: route.name,
      to: route.name ? { name: route.name } : route.path,
    });
  }
  return NAVIGATION_GROUPS.map((group) => ({
    ...group,
    items: group.ids.map((id) => registered.get(id)).filter(Boolean),
  })).filter((group) => group.items.length);
}

export function newNavigationState() {
  return {
    menuOpen: false,
    collapsed: false,
    hoverOpen: false,
    mobile: false,
    search: "",
    collapsedGroups: Object.fromEntries(NAVIGATION_GROUPS.filter((group) => !group.direct).map((group) => [group.id, true])),
  };
}

export function toggleSidebar(state) {
  if (state.mobile) state.menuOpen = !state.menuOpen;
  else {
    state.collapsed = !state.collapsed;
    state.hoverOpen = false;
  }
}

export function toggleNavGroup(state, id) {
  if (!(id in state.collapsedGroups)) return;
  const opening = (!state.mobile && state.collapsed) || state.collapsedGroups[id];
  if (!state.mobile && state.collapsed) state.collapsed = false;
  state.hoverOpen = false;
  for (const groupId of Object.keys(state.collapsedGroups)) state.collapsedGroups[groupId] = true;
  state.collapsedGroups[id] = !opening;
}

export function synchronizeNavigation(state, groups, routeName) {
  for (const group of NAVIGATION_GROUPS) {
    if (group.direct) continue;
    const visible = groups.find((item) => item.id === group.id);
    state.collapsedGroups[group.id] = !visible?.items.some((item) => item.routeName === routeName);
  }
  state.menuOpen = false;
}
