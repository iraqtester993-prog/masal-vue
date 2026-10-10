<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { usePortal } from "../modules/auth/session.js";
import { useTheme } from "../shared/composables/theme.js";
import LanguagePicker from "../modules/preferences/LanguagePicker.vue";
import { usePreferences, usePreferencesTranslator } from "../modules/preferences/preferences-state.js";
import { navigationGroups, navIconPath, newNavigationState, synchronizeNavigation, toggleNavGroup, toggleSidebar } from "./navigation.js";
import { createNotificationCounts } from "./notification-counts.js";
import { createRoutePrefetch } from '../router/prefetch.js';
import QuickActions from './QuickActions.vue';
import {presenceContextKey} from '../modules/maps/presence-context.js';
import "./shell-parity.css";
import "./pos-app.css";

defineProps({ scopeLabel: { type: String, required: true } });
const { session } = usePortal();
const presence=inject(presenceContextKey,null);
const preferences = usePreferences(), t = usePreferencesTranslator();
const router = useRouter(), route = useRoute();
const prefetchRoute = createRoutePrefetch(router, target => !!session.state.identity
  && (!target.meta.permission || session.can(target.meta.permission))
  && (!target.meta.accountTypes || target.meta.accountTypes.includes(session.state.identity.account.type))
  && (!target.meta.membershipKinds || target.meta.membershipKinds.includes(session.state.identity.membership.kind)));
const navigation = reactive(newNavigationState());
const error = ref(""), sidebar = ref(null), menuToggle = ref(null), mobileMenuToggle=ref(null);
const { dark, toggleTheme } = useTheme();
const identity = computed(() => session.state.identity);
const userName = computed(() => identity.value?.user.name || "");
const initial = computed(() => Array.from(userName.value)[0] || "");
const allGroups = computed(() => navigationGroups({
  routes: router.getRoutes(), identity: identity.value, can: session.can,
}));
const pageTitle = computed(() => identity.value?.account.type==='pos' && router.currentRoute.value.name==='sales' ? 'سجل العمليات' : identity.value?.account.type==='pos' && router.currentRoute.value.name==='sell' ? 'البيع' : router.currentRoute.value.meta.title || allGroups.value.flatMap(group => group.items).find(item => item.routeName === router.currentRoute.value.name)?.label || 'لوحة التحكم');
const searchResults = computed(() => {
  const query = navigation.search.trim().toLocaleLowerCase();
  return query ? allGroups.value.flatMap(group => group.items).filter(item => `${item.label} ${t(item.label)}`.toLocaleLowerCase().includes(query)) : [];
});
const searchOpen = ref(false);
function navigateSearch(item = searchResults.value[0]) { if (!item) return; searchOpen.value = false; navigation.search = ''; closeMenu(); router.push(item.to); }
watch(()=>t(pageTitle.value),title=>{if(typeof document!=='undefined')document.title=title;},{immediate:true});
const posLinks = computed(() => allGroups.value.flatMap(group => group.items).filter(item => ['dashboard','sell','sales','wallets'].includes(item.id)).sort((a,b)=>['dashboard','sell','sales','wallets'].indexOf(a.id)-['dashboard','sell','sales','wallets'].indexOf(b.id)));
const navGroups = computed(() => navigationGroups({
  routes: router.getRoutes(), identity: identity.value, can: session.can, search: navigation.search, labelFor: t,
}));
const notifications = computed(() => allGroups.value.flatMap((group) => group.items).find((item) => item.id === "notifications"));
const notificationSummary = ref(null);
const notificationCounts = createNotificationCounts({ api: session.api, identity: () => identity.value, allowed: () => session.can('notifications.view'), publish: (value) => { notificationSummary.value = value; } });
const itemCount = (item) => notificationSummary.value?.counts[item.id] || 0;
const groupCount = (group) => group.items.reduce((total, item) => total + itemCount(item), 0);
const refreshNotifications = () => { void notificationCounts.refresh(); };
function visibleNotifications() { if (document.visibilityState === 'visible') refreshNotifications(); }
const profileRoute = computed(() => {
  const item = router.getRoutes().find((candidate) => candidate.name === "profile");
  if (!item?.meta.requiresAuth || (item.meta.permission && !session.can(item.meta.permission))) return null;
  return { name: "profile" };
});
const railCollapsed = computed(() => !navigation.mobile && navigation.collapsed && !navigation.hoverOpen);
const menuLabel = computed(() => navigation.mobile
  ? navigation.menuOpen ? "إغلاق القائمة" : "فتح القائمة"
  : navigation.collapsed ? "إظهار القائمة" : "طي القائمة");
let sidebarMedia, countsMounted = false;

function groupExpanded(group) {
  return !!group.direct || !navigation.collapsedGroups[group.id];
}
async function toggleMenu() {
  toggleSidebar(navigation);
  prefetchVisible();
  if (navigation.mobile && navigation.menuOpen) {
    await nextTick();
    sidebar.value?.querySelector(".sidebar-navigation button, .sidebar-navigation a")?.focus();
  }
}
function prefetchGroup(group) { for (const item of group.items.slice(0, 2)) prefetchRoute(item.to); }
function openGroup(group) { toggleNavGroup(navigation, group.id); if (groupExpanded(group)) prefetchGroup(group); }
function prefetchVisible() { for (const group of allGroups.value) if (groupExpanded(group)) prefetchGroup(group); }
function closeMenu() { navigation.menuOpen = false; }
function onSidebarKeydown(event) {
  if (!navigation.mobile || !navigation.menuOpen) return;
  if (event.key === "Escape") { event.preventDefault(); closeMenu(); return; }
  if (event.key !== "Tab") return;
  const controls = [...sidebar.value.querySelectorAll("button, a[href], input, select, [tabindex]")]
    .filter((element) => !element.disabled && !element.closest("[inert]") && element.getClientRects().length);
  const first = controls[0], last = controls.at(-1);
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
}
function updateViewport(event) {
  navigation.mobile = event.matches;
  navigation.menuOpen = false;
  navigation.hoverOpen = false;
}
async function signOut() {
  error.value = "";
  closeMenu();
  try { await session.logout(); await router.replace({ name: "login" }); }
  catch (failure) { error.value = failure.message; }
}
watch(() => route.name, () => {
  synchronizeNavigation(navigation, allGroups.value, route.name);
  navigation.hoverOpen = false;
  if (countsMounted) void notificationCounts.refresh({ force: false });
}, { immediate: true });
watch(identity, () => { notificationCounts.clear(); if (countsMounted) refreshNotifications(); });
watch(() => navigation.search, (query) => {
  if (!query.trim()) {
    synchronizeNavigation(navigation, allGroups.value, route.name);
    return;
  }
  for (const group of navGroups.value) {
    if (!group.direct) navigation.collapsedGroups[group.id] = false;
  }
});
watch(() => navigation.menuOpen, async (open, previous) => {
  if (!open && previous && navigation.mobile) { await nextTick(); (mobileMenuToggle.value?.getClientRects().length ? mobileMenuToggle.value : menuToggle.value)?.focus(); }
});
onMounted(() => {
  countsMounted = true;
  refreshNotifications();
  sidebarMedia = window.matchMedia("(max-width:1100px)");
  updateViewport(sidebarMedia);
  sidebarMedia.addEventListener("change", updateViewport);
  window.addEventListener('masal:notifications-changed', refreshNotifications);
  document.addEventListener('visibilitychange', visibleNotifications);
});
onBeforeUnmount(() => {
  countsMounted = false;
  sidebarMedia?.removeEventListener("change", updateViewport);
  window.removeEventListener('masal:notifications-changed', refreshNotifications);
  document.removeEventListener('visibilitychange', visibleNotifications);
  notificationCounts.dispose();
});
</script>

<template>
  <div class="portal-shell shell" :class="{ 'sidebar-collapsed': railCollapsed, 'pos-app': identity?.account.type === 'pos' }">
    <button v-if="navigation.menuOpen" type="button" class="mobile-shade" aria-label="إغلاق القائمة الجانبية" @click="closeMenu"></button>
    <aside
      id="main-sidebar" ref="sidebar" class="portal-sidebar sidebar"
      :class="{ open: navigation.menuOpen }" :aria-label="t(scopeLabel)"
      :inert="navigation.mobile && !navigation.menuOpen ? true : null"
      @mouseenter="!navigation.mobile && navigation.collapsed && (navigation.hoverOpen = true)"
      @mouseleave="navigation.hoverOpen = false" @keydown="onSidebarKeydown"
    >
      <div class="brand">
        <div class="brandmark">
          <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <rect x="2" y="5" width="20" height="14" rx="3"></rect>
            <path d="M2 10h20M7 15h1m3 0h2"></path>
          </svg>
        </div>
        <div><strong>ماسال</strong><span class="eyebrow">MASAL</span></div>
      </div>
      <nav class="sidebar-navigation" aria-label="القائمة الرئيسية">
        <section v-for="group in navGroups" :key="group.id" class="nav-section" :class="{ 'is-open': groupExpanded(group), 'direct-section': group.direct }">
          <button
            v-if="!group.direct" type="button" class="navgroup"
            :class="{ active: group.items.some((item) => item.routeName === route.name) }"
            :title="t(group.title)" :aria-label="t(group.title)" :aria-expanded="groupExpanded(group)"
            :aria-controls="'nav-group-' + group.id" @click="openGroup(group)" @pointerenter="prefetchGroup(group)" @focus="prefetchGroup(group)"
          >
            <span class="navicon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path :d="navIconPath(group.icon)"></path></svg></span>
            <span class="group-label">{{ t(group.title) }}</span>
            <span v-if="groupCount(group)" class="nav-count">{{ groupCount(group) }}</span>
            <svg class="nav-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 8 4 4 4-4"></path></svg>
          </button>
          <div :id="'nav-group-' + group.id" class="nav-accordion" :inert="!group.direct && (!groupExpanded(group) || railCollapsed) ? true : null">
            <div class="navlinks">
              <RouterLink
                v-for="item in group.items" :key="item.id" class="navitem"
                :to="item.to" :title="t(item.label)" :aria-label="t(item.label)"
                :class="{ active: item.routeName === route.name }"
                :aria-current="item.routeName === route.name ? 'page' : undefined" @click="closeMenu"
                @pointerenter="prefetchRoute(item.to)" @focus="prefetchRoute(item.to)" @touchstart.passive="prefetchRoute(item.to)"
              >
                <span class="navicon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path :d="navIconPath(item.id)"></path></svg></span>
                <span class="nav-label">{{ t(item.label) }}</span>
                <span v-if="itemCount(item)" class="nav-count">{{ itemCount(item) }}</span>
              </RouterLink>
            </div>
          </div>
        </section>
        <p v-if="!navGroups.length" class="navigation-empty" role="status">{{ t(navigation.search ? "لا توجد نتائج مطابقة" : "لا توجد وحدات مسموحة لهذا الحساب") }}</p>
      </nav>
      <div v-if="navigation.mobile && identity?.account.type==='pos'" class="pos-mobile-preferences"><LanguagePicker/><button type="button" class="btn small" @click="toggleTheme">{{t(dark?'الوضع النهاري':'الوضع الليلي')}}</button><button v-if="presence" type="button" class="btn small" :disabled="presence.state.checking" @click="presence.state.sharing?presence.stopLocationSharing():presence.startLocationSharing()">{{t(presence.state.sharing?'إيقاف مشاركة موقعي':'مشاركة موقعي مع الإدارة')}}</button></div>
      <div class="sidebar-account">
        <span class="account-avatar">{{ initial }}</span>
        <div><strong><RouterLink v-if="profileRoute" class="account-profile-link" :to="profileRoute" @click="closeMenu">{{ userName }}</RouterLink><template v-else>{{ userName }}</template></strong><small>الحساب الحالي</small></div>
        <button type="button" class="sidebar-logout" :disabled="session.state.busy" aria-label="تسجيل الخروج" title="تسجيل الخروج" @click="signOut">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4"></path></svg>
        </button>
        <button v-if="navigation.mobile" type="button" class="iconbtn" aria-label="إغلاق القائمة" @click="closeMenu">×</button>
      </div>
    </aside>
    <div class="portal-content main" :data-page="route.meta.navigationId || route.name" :inert="navigation.mobile && navigation.menuOpen ? true : null">
      <header v-if="identity?.account.type==='pos'" class="pos-mobile-header">
        <button ref="mobileMenuToggle" type="button" class="pos-header-button" :aria-label="t(menuLabel)" :aria-expanded="navigation.menuOpen" aria-controls="main-sidebar" @click="toggleMenu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="pos-header-title"><strong>{{t(pageTitle)}}</strong><small>{{identity.account.name}}</small></div>
        <RouterLink v-if="profileRoute" :to="profileRoute" class="pos-header-account" :aria-label="t('حسابي')" :title="userName">{{initial}}</RouterLink><span v-else class="pos-header-account" :title="userName">{{initial}}</span>
      </header>
      <header class="portal-header topbar" :class="{'pos-desktop-header':identity?.account.type==='pos'}">
        <button ref="menuToggle" type="button" class="iconbtn menuToggle" :aria-label="t(menuLabel)" :aria-expanded="navigation.mobile ? navigation.menuOpen : !railCollapsed" aria-controls="main-sidebar" @click="toggleMenu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 6h14M5 12h14M5 18h14"></path></svg>
        </button>
        <div class="breadcrumb"><strong>{{ t(pageTitle) }}</strong></div>
        <div class="toptools">
          <button v-if="identity?.account.type!=='system' && presence" type="button" class="btn small location-share-control" :aria-pressed="presence.state.sharing" :title="t(presence.state.status)" :disabled="presence.state.checking" @click="presence.state.sharing ? presence.stopLocationSharing() : presence.startLocationSharing()">{{t(presence.state.sharing ? 'إيقاف مشاركة موقعي' : 'مشاركة موقعي مع الإدارة')}}</button>
          <LanguagePicker />
          <QuickActions />
          <button type="button" class="iconbtn theme-toggle" :disabled="preferences?.state.loading || preferences?.state.busy" :aria-label="t(dark ? 'تفعيل الوضع النهاري' : 'تفعيل الوضع الليلي')" :title="t(dark ? 'الوضع النهاري' : 'الوضع الليلي')" :aria-pressed="dark" @click="toggleTheme">
            <svg v-if="!dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z"></path></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"></path></svg>
          </button>
          <div class="portal-search" @focusout="event => { if (!event.currentTarget.contains(event.relatedTarget)) searchOpen = false; }">
            <input v-model="navigation.search" type="search" class="search" placeholder="ابحث في وحدات النظام…" aria-label="بحث الوحدات" autocomplete="off" maxlength="200" :aria-expanded="searchOpen && !!navigation.search.trim()" aria-controls="portal-search-results" @focus="searchOpen=true" @input="searchOpen=true" @keydown.enter.prevent="navigateSearch()" @keydown.esc="searchOpen=false" />
            <div v-if="searchOpen && navigation.search.trim()" id="portal-search-results" class="portal-search-results">
              <button v-for="item in searchResults" :key="item.id" type="button" @click="navigateSearch(item)">{{ t(item.label) }}</button>
              <p v-if="!searchResults.length" role="status">{{ t('لا توجد نتائج مطابقة') }}</p>
            </div>
          </div>
          <RouterLink v-if="notifications" class="iconbtn notification-bell" :to="notifications.to" :aria-label="t(notificationSummary?.unread ? 'الإشعارات: ' + notificationSummary.unread + ' غير مقروءة' : 'الإشعارات')" title="الإشعارات">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="navIconPath('notifications')"></path></svg>
            <span v-if="notificationSummary?.unread" class="notification-count">{{ notificationSummary.unread }}</span>
          </RouterLink>
          <RouterLink v-if="profileRoute" class="avatar" :to="profileRoute" aria-label="حسابي" :title="userName">{{ initial }}</RouterLink>
          <div v-else class="avatar" :title="userName">{{ initial }}</div>
          <RouterLink v-if="profileRoute" class="profile-picker account-identity" :to="profileRoute" :title="userName + ' · ' + t(scopeLabel)">{{ userName }}</RouterLink>
          <span v-else class="profile-picker account-identity" :title="userName + ' · ' + t(scopeLabel)">{{ userName }}</span>
        </div>
      </header>
      <main id="main-content" class="portal-main">
        <p v-if="preferences?.state.error" class="notice notice-error" role="alert">{{ preferences.state.error }}</p>
        <p v-if="error" class="notice notice-error" role="alert">{{ error }}</p>
        <slot />
      </main>
      <nav v-if="identity?.account.type==='pos'" class="pos-app-nav" :aria-label="t('تنقل نقطة البيع')">
        <RouterLink v-for="item in posLinks" :key="item.id" class="pos-tab" :to="item.to" :aria-label="t(item.label)" :aria-current="route.name===item.routeName?'page':null"><span class="pos-tab-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="navIconPath(item.id)"/></svg></span><span class="pos-tab-label">{{t({dashboard:'الرئيسية',sell:'البيع',sales:'السجل',wallets:'المحفظة'}[item.id])}}</span></RouterLink>
        <button type="button" class="pos-tab" :class="{'is-more-active':navigation.menuOpen || !posLinks.some(item=>item.routeName===route.name)}" :aria-label="t('المزيد')" :aria-expanded="navigation.menuOpen" aria-controls="main-sidebar" @click="toggleMenu"><span class="pos-tab-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span><span class="pos-tab-label">{{t('المزيد')}}</span></button>
      </nav>
      <footer class="footer app-footer"><span>© {{ new Date().getFullYear() }} جميع الحقوق محفوظة لشركة عراق تكنو للحلول البرمجية</span></footer>
    </div>
  </div>
</template>
