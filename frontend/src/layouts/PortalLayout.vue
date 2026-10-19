<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { identityScope, usePortal } from "../modules/auth/session.js";
import { useTheme } from "../shared/composables/theme.js";
import LanguagePicker from "../modules/preferences/LanguagePicker.vue";
import { usePreferences, usePreferencesTranslator } from "../modules/preferences/preferences-state.js";
import { navigationGroups, navIconPath, newNavigationState, synchronizeNavigation, toggleNavGroup, toggleSidebar } from "./navigation.js";
import { createNotificationCounts } from "./notification-counts.js";
import { createRoutePrefetch } from '../router/prefetch.js';
import {routeAllowed} from '../router/access.js';
import QuickActions from './QuickActions.vue';
import {presenceContextKey} from '../modules/maps/presence-context.js';
import "./shell-parity.css";
import {useBalancePrivacy} from '../shared/composables/balance-privacy.js';
import {ensurePosDeviceSession,clearPosDeviceSession} from '../shared/pos-device-session.js';
import {posBrandStyle} from './pos-brand.js';
import {posSystemAppearance} from './pos-system-appearance.js';
import {registerPosBackLayer} from '../shared/pos-back-navigation.js';
import PwaInstall from './PwaInstall.vue';
import {installPullRefresh,refreshBlocked} from '../shared/pull-refresh.js';
import {createRouteRefreshCoordinator,routeRefreshContextKey} from '../shared/route-refresh.js';
import {hasNativePrinter,getNativePrinterCapabilities,nativeRequest,configurePrinterRegistry,syncNativePrinterRegistry} from '../shared/native-print.js';

defineProps({ scopeLabel: { type: String, required: true } });
const { session } = usePortal();
const presence=inject(presenceContextKey,null);
const preferences = usePreferences(), t = usePreferencesTranslator();
const router = useRouter(), route = useRoute();
const prefetchRoute = createRoutePrefetch(router, target => !!session.state.identity&&routeAllowed(target,session));
const navigation = reactive(newNavigationState());
const error = ref(""), sidebar = ref(null), menuToggle = ref(null), mobileMenuToggle=ref(null);
const { dark, toggleTheme } = useTheme();
const identity = computed(() => session.state.identity);
const brand = computed(()=>identity.value?.brand);
const brandStyle = computed(()=>identity.value?.account.type==='pos'?posBrandStyle(brand.value?.color):null);
const brandKeys=Object.keys(posBrandStyle());
useBalancePrivacy(() => identity.value ? `${identity.value.user.id}:${identity.value.account.id}` : null);
let releasePrinterRegistry,devicePulse;
function registerCurrentDevice(){const own=identity.value?.account;if(own?.type==='pos'&&document.visibilityState==='visible')ensurePosDeviceSession(session.api,own.id).catch(error=>{if(identity.value?.account.id===own.id&&(error.status===401||['POS_LOGIN_DEVICE_MISMATCH','POS_LOGIN_PROOF_REQUIRED'].includes(error.code)))void session.refresh();});}

async function syncPrinterRegistry(){if(identity.value?.account.type!=='pos'||!hasNativePrinter())return;releasePrinterRegistry?.();releasePrinterRegistry=configurePrinterRegistry(session.api);try{await syncNativePrinterRegistry();}catch{/* The print action retries registry validation before sending any receipt. */}}
const pullProgress=ref(0),pullRefreshing=ref(false),pullFinished=ref(false);let pullFinishTimer;
const routeRefresh=createRouteRefreshCoordinator();provide(routeRefreshContextKey,routeRefresh);
const saleSearchExpanded=ref(false);
function toggleSaleSearch(){window.dispatchEvent(new Event('dananeer:toggle-sale-search'));}
function saleSearchState(event){saleSearchExpanded.value=event.detail?.expanded===true;}let removePull;
function canPullRefresh(){return !!identity.value&&!navigation.menuOpen&&!pullRefreshing.value&&routeRefresh.canRefresh()&&!refreshBlocked({pendingMutation:session.api.hasPendingMutation?.(),dialogOpen:!!document.querySelector('dialog[open],[role="dialog"][aria-modal="true"],.digital-receipt,[data-operation-uncertain="true"]')});}
async function pullRefresh(){
 if(!canPullRefresh())return;pullRefreshing.value=true;pullFinished.value=false;clearTimeout(pullFinishTimer);
 const page=route.fullPath,actor=identity.value?.account.id;error.value='';
 try{
  if(hasNativePrinter()){const capability=await getNativePrinterCapabilities();if(capability?.busy)return;}
  // A write or modal may have started while the native status was being read.
  if(page!==route.fullPath||actor!==identity.value?.account.id||session.api.hasPendingMutation?.()||!routeRefresh.canRefresh()||document.querySelector('dialog[open],.digital-receipt,[data-operation-uncertain="true"]'))return;
  const refreshed=await routeRefresh.refresh();
  if(refreshed&&page===route.fullPath&&actor===identity.value?.account.id){pullFinished.value=true;pullFinishTimer=setTimeout(()=>pullFinished.value=false,1100);refreshNotifications();}
 }catch(failure){if(failure.name!=='AbortError')error.value=failure.message||'تعذر تحديث الصفحة. حاول مرة أخرى.';}
 finally{pullRefreshing.value=false;}
}
watch(()=>[route.fullPath,identity.value?.user.id,identity.value?.account.id],()=>{routeRefresh.cancel();pullProgress.value=0;pullFinished.value=false;clearTimeout(pullFinishTimer);});
const posFullscreenDialog=ref(false);let appearanceObserver;
function refreshAppearanceOverlay(){
 if(identity.value?.account.type!=='pos'){posFullscreenDialog.value=false;return;}
 const dialog=[...document.querySelectorAll('dialog[open]')].at(-1),box=dialog?.getBoundingClientRect();
 posFullscreenDialog.value=!!box&&box.top<=1&&box.bottom>=window.innerHeight-1;
}
const posAppearance=computed(()=>identity.value?.account.type==='pos'?posSystemAppearance({home:route.name==='dashboard',dark:dark.value,hero:brandStyle.value['--pos-hero'],accent:brandStyle.value[dark.value?'--pos-link-dark':'--pos-link-light'],overlay:posFullscreenDialog.value||navigation.menuOpen}):null);
watch(posAppearance,appearance=>{if(typeof document==='undefined'||!appearance)return;document.querySelector('meta[name="theme-color"]')?.setAttribute('content',appearance.statusBarColor);if(hasNativePrinter())nativeRequest(appearance,{timeoutMs:3000}).catch(()=>{});},{immediate:true});
const userName = computed(() => identity.value?.user.name || "");
const initial = computed(() => Array.from(userName.value)[0] || "");
const allGroups = computed(() => navigationGroups({
  routes: router.getRoutes(), identity: identity.value, can: session.can,
}));
const pageTitle = computed(() => identity.value?.account.type==='pos' && router.currentRoute.value.name==='sales' ? 'سجل العمليات' : identity.value?.account.type==='pos' && router.currentRoute.value.name==='sell' ? 'البيع' : router.currentRoute.value.meta.title || allGroups.value.flatMap(group => group.items).find(item => item.routeName === router.currentRoute.value.name)?.label || 'لوحة التحكم');
watch(()=>t(pageTitle.value),title=>{if(typeof document!=='undefined')document.title=title;},{immediate:true});
const posLinks = computed(() => allGroups.value.flatMap(group => group.items).filter(item => ['dashboard','sell','sales','reports'].includes(item.id)).sort((a,b)=>['dashboard','sell','sales','reports'].indexOf(a.id)-['dashboard','sell','sales','reports'].indexOf(b.id)));
function posTabIcon(id){return id==='sell'?navIconPath('products'):id==='sales'?'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0M12 7v5l3 2':navIconPath(id);}
const posTypeName = computed(()=>identity.value?.account.name || identity.value?.account.pos_type_name || t('نقطة بيع'));
watch(brandStyle,style=>{if(typeof document==='undefined')return;for(const key of brandKeys){if(style)document.documentElement.style.setProperty(key,style[key]);else document.documentElement.style.removeProperty(key);}},{immediate:true});
const navGroups = allGroups;
const notifications = computed(() => allGroups.value.flatMap((group) => group.items).find((item) => item.id === "notifications"));
const notificationSummary = ref(null);
const notificationCounts = createNotificationCounts({ api: session.api, identity: () => identity.value ? identityScope(identity.value) : null, allowed: () => session.can('notifications.view'), publish: (value) => { notificationSummary.value = value; } });
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
let sidebarMedia, menuOpener, countsMounted = false;

function groupExpanded(group) {
  return !!group.direct || !navigation.collapsedGroups[group.id];
}
async function toggleMenu() {
  if (!navigation.menuOpen && navigation.mobile && typeof document !== 'undefined') menuOpener = document.activeElement;
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
let releaseMenuBack;
function openServicesMenu(){if(identity.value?.account.type==='pos'&&!navigation.menuOpen)void toggleMenu();}
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
  navigation.mobile = identity.value?.account.type === 'pos' || event.matches;
  navigation.menuOpen = false;
  navigation.hoverOpen = false;
}
watch(() => identity.value?.account.type, () => { if (sidebarMedia) updateViewport(sidebarMedia); });
async function signOut() {
  error.value = "";
  closeMenu();
  try { await session.logout(); await router.replace({ name: "login" }); }
  catch (failure) { error.value = failure.message; }
}
async function readPageNotices(name){const page=['agents','pos','users','staff'].includes(name)?'accounts':name;if(!session.can('notifications.view')||!['support','import','inventory','claims','exports','wallets','invoices','prices','sales','exceptions','accounts','security','branding'].includes(page))return;try{await notificationCounts.readPage(page);}catch{}}
watch(() => route.name, () => {
  if(route.name!=='sell')saleSearchExpanded.value=false;
  synchronizeNavigation(navigation, allGroups.value, route.name);
  navigation.hoverOpen = false;
  if (countsMounted) {void readPageNotices(route.name);void notificationCounts.refresh({ force: false });}
}, { immediate: true });
watch(() => identityScope(identity.value), () => { notificationCounts.clear(); if (countsMounted) refreshNotifications(); });
watch(() => navigation.menuOpen, async (open, previous) => {
  if (typeof document !== 'undefined') document.documentElement.classList.toggle('pos-menu-open', identity.value?.account.type==='pos' && open);
  if (!open && previous && navigation.mobile) {
    await nextTick();
    const visible = element => element?.isConnected && element.getClientRects().length && !element.closest('[inert]');
    const target = [menuOpener,mobileMenuToggle.value,menuToggle.value].find(visible) || document.querySelector('.pos-header-identity');
    target?.focus(); menuOpener = null;
  }
});
onMounted(() => {
  if(identity.value?.account.type==='pos'){
   releaseMenuBack=registerPosBackLayer({priority:100,state:()=>identity.value?.account.type==='pos'&&navigation.menuOpen?'open':'none',back:closeMenu});
   appearanceObserver=new MutationObserver(refreshAppearanceOverlay);appearanceObserver.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open']});
   window.addEventListener('resize',refreshAppearanceOverlay);refreshAppearanceOverlay();
  }
  void syncPrinterRegistry();
  registerCurrentDevice();devicePulse=setInterval(registerCurrentDevice,30000);document.addEventListener('visibilitychange',registerCurrentDevice);
  removePull=installPullRefresh({canStart:canPullRefresh,onRefresh:pullRefresh,onProgress:value=>pullProgress.value=value});
  countsMounted = true;
  void readPageNotices(route.name);
  refreshNotifications();
  sidebarMedia = window.matchMedia("(max-width:1100px)");
  updateViewport(sidebarMedia);
  sidebarMedia.addEventListener("change", updateViewport);
  window.addEventListener('masal:notifications-changed', refreshNotifications);
  window.addEventListener('dananeer:open-menu', openServicesMenu);
  window.addEventListener('dananeer:sale-search-state', saleSearchState);
  document.addEventListener('visibilitychange', visibleNotifications);
});
onBeforeUnmount(() => {
  appearanceObserver?.disconnect();window.removeEventListener('resize',refreshAppearanceOverlay);
  releaseMenuBack?.();
  document.documentElement.classList.remove('pos-menu-open');
  clearInterval(devicePulse);clearPosDeviceSession();document.removeEventListener('visibilitychange',registerCurrentDevice);
  releasePrinterRegistry?.();
  removePull?.();clearTimeout(pullFinishTimer);
  routeRefresh.cancel();
  if(typeof document!=='undefined')for(const key of brandKeys)document.documentElement.style.removeProperty(key);
  countsMounted = false;
  sidebarMedia?.removeEventListener("change", updateViewport);
  window.removeEventListener('masal:notifications-changed', refreshNotifications);
  window.removeEventListener('dananeer:open-menu', openServicesMenu);
  window.removeEventListener('dananeer:sale-search-state', saleSearchState);
  document.removeEventListener('visibilitychange', visibleNotifications);
  notificationCounts.dispose();
});
</script>

<template>
  <div class="portal-shell shell" :style="brandStyle" :class="{ 'sidebar-collapsed': railCollapsed, 'pos-app': identity?.account.type === 'pos' }">
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
        <div><strong>{{identity?.account.type==='pos'?(brand?.name||'دنانير'):'ماسال'}}</strong><span class="eyebrow">{{identity?.account.type==='pos'?'دنانير':'MASAL'}}</span></div>
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
        <p v-if="!navGroups.length" class="navigation-empty" role="status">{{ t("لا توجد وحدات مسموحة لهذا الحساب") }}</p>
      </nav>
      <div v-if="navigation.mobile && identity?.account.type==='pos'" class="pos-mobile-preferences"><LanguagePicker/><button type="button" class="btn small" @click="toggleTheme">{{t(dark?'الوضع النهاري':'الوضع الليلي')}}</button><button v-if="presence" type="button" class="btn small" :disabled="presence.state.checking" @click="presence.state.sharing?presence.stopLocationSharing():presence.startLocationSharing()">{{t(presence.state.sharing?'إيقاف مشاركة موقعي':'مشاركة موقعي مع الإدارة')}}</button><PwaInstall/></div>
      <div class="sidebar-account">
        <span class="account-avatar">{{ initial }}</span>
        <div><strong><RouterLink v-if="profileRoute" class="account-profile-link" :to="profileRoute" :title="t('تعديل حسابي')" :aria-label="t('تعديل حسابي') + ' · ' + userName" @click="closeMenu" @pointerenter="prefetchRoute(profileRoute)" @focus="prefetchRoute(profileRoute)">{{ userName }}</RouterLink><template v-else>{{ userName }}</template></strong></div>
        <button type="button" class="sidebar-logout" :disabled="session.state.busy" aria-label="تسجيل الخروج" title="تسجيل الخروج" @click="signOut">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4"></path></svg>
        </button>
        <button v-if="navigation.mobile" type="button" class="iconbtn" aria-label="إغلاق القائمة" @click="closeMenu">×</button>
      </div>
    </aside>
    <div class="portal-content main" :data-page="route.meta.navigationId || route.name" :inert="navigation.mobile && navigation.menuOpen ? true : null">
      <div v-if="pullProgress||pullRefreshing||pullFinished" class="pos-pull-indicator" role="status" :style="{'--pull-offset':pullProgress+'px'}"><span class="pos-pull-glyph" :class="{spinning:pullRefreshing,ready:pullProgress>=64}" :style="{'--pull-turn':Math.min(180,pullProgress*2)+'deg'}" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path v-if="pullFinished" d="m5 12 4 4L19 6"/><path v-else d="M20 7v5h-5M4 17v-5h5M6.1 7a7 7 0 0 1 11.6-1L20 9M4 15l2.3 3A7 7 0 0 0 18 17"/></svg></span>{{t(pullFinished?'تم تحديث الصفحة':pullRefreshing?'جارٍ تحديث الصفحة…':pullProgress>=64?'اترك للتحديث':'اسحب للتحديث')}}</div>
      <header v-if="identity?.account.type==='pos'" class="pos-mobile-header">
        <RouterLink :to="{name:'posMore'}" class="pos-header-identity" :aria-label="t('حسابي')"><span class="pos-header-avatar" aria-hidden="true">{{initial || 'د'}}</span><span class="pos-header-title"><small>{{route.name==='dashboard'?t('أهلاً بك في')+' '+(brand?.name||'دنانير'):posTypeName}}</small><strong>{{route.name==='dashboard'?posTypeName:t(pageTitle)}}</strong></span></RouterLink>
        <button v-if="route.name==='sell'" class="pos-header-button pos-search-toggle" type="button" aria-label="بحث الفئات" :aria-expanded="saleSearchExpanded" :aria-controls="saleSearchExpanded?'pos-sale-search':undefined" @click="toggleSaleSearch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg></button>
        <RouterLink v-if="notifications" class="pos-header-button notification-bell" :to="notifications.to" :aria-label="t('الإشعارات')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="navIconPath('notifications')"/></svg><span v-if="notificationSummary?.unread" class="notification-count">{{notificationSummary.unread>99?'99+':notificationSummary.unread}}</span></RouterLink>
      </header>
      <header class="portal-header topbar" :class="{'pos-desktop-header':identity?.account.type==='pos'}">
        <button ref="menuToggle" type="button" class="iconbtn menuToggle" :aria-label="t(menuLabel)" :aria-expanded="navigation.mobile ? navigation.menuOpen : !railCollapsed" aria-controls="main-sidebar" @click="toggleMenu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 6h14M5 12h14M5 18h14"></path></svg>
        </button>
        <div class="breadcrumb"><strong>{{ t(pageTitle) }}</strong></div>
        <div class="toptools">
          <button v-if="identity?.account.type!=='system' && presence" type="button" class="btn small location-share-control" :aria-label="t(presence.state.sharing ? 'إيقاف مشاركة موقعي' : 'مشاركة موقعي مع الإدارة')" :aria-pressed="presence.state.sharing" :title="t(presence.state.status)" :disabled="presence.state.checking" @click="presence.state.sharing ? presence.stopLocationSharing() : presence.startLocationSharing()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>{{t(presence.state.sharing ? 'إيقاف مشاركة موقعي' : 'مشاركة موقعي مع الإدارة')}}</span></button>
          <LanguagePicker />
          <QuickActions />
          <button type="button" class="iconbtn theme-toggle" :disabled="preferences?.state.loading || preferences?.state.busy" :aria-label="t(dark ? 'تفعيل الوضع النهاري' : 'تفعيل الوضع الليلي')" :title="t(dark ? 'الوضع النهاري' : 'الوضع الليلي')" :aria-pressed="dark" @click="toggleTheme">
            <svg v-if="!dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z"></path></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"></path></svg>
          </button>
          <RouterLink v-if="notifications" class="iconbtn notification-bell" :to="notifications.to" :aria-label="t(notificationSummary?.unread ? 'الإشعارات: ' + notificationSummary.unread + ' غير مقروءة' : 'الإشعارات')" title="الإشعارات">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="navIconPath('notifications')"></path></svg>
            <span v-if="notificationSummary?.unread" class="notification-count">{{ notificationSummary.unread }}</span>
          </RouterLink>
        </div>
      </header>
      <main id="main-content" class="portal-main">
        <p v-if="preferences?.state.error" class="notice notice-error" role="alert">{{ preferences.state.error }}</p>
        <p v-if="error" class="notice notice-error" role="alert">{{ error }}</p>
        <slot />
      </main>
      <nav v-if="identity?.account.type==='pos'" class="pos-app-nav" :aria-label="t('تنقل نقطة البيع')">
        <RouterLink v-for="item in posLinks" :key="item.id" class="pos-tab" :to="item.to" :aria-label="t(item.id==='reports'?'التقارير':item.label)" :aria-current="route.name===item.routeName?'page':null"><span class="pos-tab-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="posTabIcon(item.id)"/></svg></span><span class="pos-tab-label">{{t({dashboard:'الرئيسية',sell:'البيع',sales:'السجل',reports:'التقارير'}[item.id])}}</span></RouterLink>
        <RouterLink :to="{name:'posMore'}" class="pos-tab" :class="{'is-more-active':navigation.menuOpen || !posLinks.some(item=>item.routeName===route.name)}" :aria-label="t('المزيد')" :aria-current="route.name==='posMore'?'page':null"><span class="pos-tab-icon"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></span><span class="pos-tab-label">{{t('المزيد')}}</span></RouterLink>
      </nav>
      <footer class="footer app-footer"><span>© {{ new Date().getFullYear() }} جميع الحقوق محفوظة لشركة عراق تكنو للحلول البرمجية</span></footer>
    </div>
  </div>
</template>
