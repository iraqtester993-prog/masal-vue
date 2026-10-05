<script>
import registeredComponents from "./components/index.js";
for (const component of registeredComponents) {
  if (typeof component.render !== "function")
    throw new Error("Vue component render missing");
}
import DashboardView from "./views/DashboardView.vue";
import CatalogView from "./views/CatalogView.vue";
import ImportView from "./views/ImportView.vue";
import InventoryView from "./views/InventoryView.vue";
import PricesView from "./views/PricesView.vue";
import SellView from "./views/SellView.vue";
import SalesView from "./views/SalesView.vue";
import ClaimsView from "./views/ClaimsView.vue";
import PermissionsView from "./views/PermissionsView.vue";
import IntegrationsView from "./views/IntegrationsView.vue";
import NotificationsView from "./views/NotificationsView.vue";
import DeletedAccountsView from "./views/DeletedAccountsView.vue";
import SupportView from "./views/SupportView.vue";
import MapView from "./views/MapView.vue";
import ReportsView from "./views/ReportsView.vue";
import AuditView from "./views/AuditView.vue";
import MonitoringView from "./views/MonitoringView.vue";
import BackupView from "./views/BackupView.vue";
import CardDesignPreviewDialog from "./components/dialogs/CardDesignPreviewDialog.vue";
import ReportSettingsDialog from "./components/dialogs/ReportSettingsDialog.vue";
import ProfileReviewDialog from "./components/dialogs/ProfileReviewDialog.vue";
import AccountDetailsDialog from "./components/dialogs/AccountDetailsDialog.vue";
import PermissionReviewDialog from "./components/dialogs/PermissionReviewDialog.vue";
import QuickActionsDialog from "./components/dialogs/QuickActionsDialog.vue";
import TransferReviewDialog from "./components/dialogs/TransferReviewDialog.vue";
import AgentProductsDialog from "./components/dialogs/AgentProductsDialog.vue";
import DashboardDetailsDialog from "./components/dialogs/DashboardDetailsDialog.vue";
import SaleDetailsDialog from "./components/dialogs/SaleDetailsDialog.vue";
import NoticeDetailsDialog from "./components/dialogs/NoticeDetailsDialog.vue";
import POSDetailsDialog from "./components/dialogs/POSDetailsDialog.vue";
import InspectDialog from "./components/dialogs/InspectDialog.vue";
import BatchDetailsDialog from "./components/dialogs/BatchDetailsDialog.vue";
import ReceiptDialog from "./components/dialogs/ReceiptDialog.vue";
import TicketDialog from "./components/dialogs/TicketDialog.vue";
import InventoryManagerDialog from "./components/dialogs/InventoryManagerDialog.vue";
import InventoryActionDialog from "./components/dialogs/InventoryActionDialog.vue";
import ConfirmationDialog from "./components/dialogs/ConfirmationDialog.vue";
import DigitalServicesView from "./views/DigitalServicesView.vue";
const options = globalThis.MasalAppOptions;
Object.assign(options.components, {
  DigitalServicesView,
  DashboardView,
  CatalogView,
  ImportView,
  InventoryView,
  PricesView,
  SellView,
  SalesView,
  ClaimsView,
  PermissionsView,
  IntegrationsView,
  NotificationsView,
  DeletedAccountsView,
  SupportView,
  MapView,
  ReportsView,
  AuditView,
  MonitoringView,
  BackupView,
  CardDesignPreviewDialog,
  ReportSettingsDialog,
  ProfileReviewDialog,
  AccountDetailsDialog,
  PermissionReviewDialog,
  QuickActionsDialog,
  TransferReviewDialog,
  AgentProductsDialog,
  DashboardDetailsDialog,
  SaleDetailsDialog,
  NoticeDetailsDialog,
  POSDetailsDialog,
  InspectDialog,
  BatchDetailsDialog,
  ReceiptDialog,
  TicketDialog,
  InventoryManagerDialog,
  InventoryActionDialog,
  ConfirmationDialog,
});
export default options;
</script>

<template>
  <div
    id="app"
    :class="{
      'digital-workspace': ['digital', 'integrations'].includes(page),
      'pos-mobile-app': posMobileEnabled,
      'pos-simple-app': posMobileEnabled,
    }"
    v-cloak=""
    :style="{ '--accent': s.settings.theme }"
  >
    <location-gate></location-gate><password-reset-panel></password-reset-panel
    ><document-image-preview></document-image-preview>
    <section v-if="loginScreen" class="login-page" dir="rtl">
      <button
        class="login-theme iconbtn"
        @click="toggleTheme"
        :aria-label="$root.tr('تبديل المظهر')"
      >
        ◐
      </button>
      <div class="login-card">
        <div class="login-form-panel">
          <form @submit.prevent="submitLogin">
            <h1>{{ $root.tr("تسجيل الدخول") }}</h1>
            <template v-if="!loginChallenge"
              ><label for="login-name">{{
                $root.tr("البريد الإلكتروني أو اسم المستخدم")
              }}</label>
              <div class="login-field">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.5"
                >
                  <circle cx="12" cy="8" r="3"></circle>
                  <path d="M5 21v-3a7 7 0 0 1 14 0v3"></path></svg
                ><input
                  id="login-name"
                  v-model="loginName"
                  autocomplete="username"
                  required=""
                />
              </div>
              <label for="login-password">{{ $root.tr("كلمة المرور") }}</label>
              <div class="login-field">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.5"
                >
                  <rect x="5" y="10" width="14" height="11" rx="2"></rect>
                  <path d="M8 10V6a4 4 0 0 1 8 0v4"></path></svg
                ><input
                  id="login-password"
                  :type="loginReveal ? 'text' : 'password'"
                  v-model="loginPassword"
                  autocomplete="current-password"
                  required=""
                /><button
                  type="button"
                  @click="loginReveal = !loginReveal"
                  :aria-label="
                    $root.tr(
                      loginReveal ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور',
                    )
                  "
                  :aria-pressed="loginReveal"
                >
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                  >
                    <path
                      d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"
                    ></path>
                    <circle cx="12" cy="12" r="3"></circle>
                    <path v-if="!loginReveal" d="m3 3 18 18"></path>
                  </svg>
                </button>
              </div>
              <label class="login-remember"
                ><input type="checkbox" v-model="loginRemember" />{{
                  $root.tr("تذكرني")
                }}</label
              ></template
            ><template v-else=""
              ><label for="login-otp">{{ $root.tr("رمز التحقق") }}</label
              ><input
                id="login-otp"
                v-model="loginCode"
                inputmode="numeric"
                autocomplete="one-time-code"
                required=""
              />
              <p class="caption">
                {{ $root.tr("رمز المحاكاة المحلية: ")
                }}{{ $root.tr(loginChallenge.demoCode) }}
              </p></template
            >
            <p v-if="loginError" class="login-error" role="alert">
              {{ $root.tr(loginError) }}
            </p>
            <button class="login-submit" :disabled="loginBusy">
              {{
                $root.tr(
                  loginBusy
                    ? "جارٍ التحقق…"
                    : loginChallenge
                      ? "تأكيد الدخول"
                      : "تسجيل الدخول",
                )
              }}
              <span aria-hidden="true">←</span></button
            ><button
              v-if="loginDemoAvailable&amp;&amp;!loginChallenge"
              type="button"
              class="login-demo"
              @click="enterLoginDemo"
            >
              {{ $root.tr("الدخول للنسخة التجريبية") }}
            </button>
          </form>
        </div>
        <div class="login-welcome">
          <div class="login-brand">
            <div class="login-logo">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
              >
                <rect x="2" y="5" width="20" height="14" rx="4"></rect>
                <path d="M2 10h20M7 15h3m3 0h3"></path>
              </svg>
            </div>
            <div>
              <strong>{{ $root.tr("ماسال") }}</strong
              ><small>{{ $root.tr("لوحة إدارة شبكة التوزيع") }}</small>
            </div>
          </div>
          <div class="login-card-scene" aria-hidden="true">
            <div class="login-orbit"></div>
            <div class="floating-voucher voucher-mobile">
              <div class="voucher-top">
                <span>{{ $root.tr("ماسال") }}</span
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <rect x="7" y="2" width="10" height="20" rx="3"></rect>
                  <path d="M10 18h4"></path>
                </svg>
              </div>
              <div class="voucher-chip"><i></i><i></i><i></i></div>
              <b>{{ $root.tr("بطاقات الاتصال") }}</b>
              <div class="voucher-bottom">
                <span>•••• &nbsp; •••• &nbsp; ••••</span
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path d="M8 7a7 7 0 0 1 0 10m4-14a12 12 0 0 1 0 18"></path>
                </svg>
              </div>
            </div>
            <div class="floating-voucher voucher-games">
              <div class="voucher-top">
                <span>{{ $root.tr("ماسال") }}</span
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M6 7h12c3 0 5 10 3 12-2 2-5-3-6-3H9c-1 0-4 5-6 3C1 17 3 7 6 7Z"
                  ></path>
                  <path d="M6 10v5m-2-2h4m8-2h.1m3 3h.1"></path>
                </svg>
              </div>
              <div class="voucher-chip"><i></i><i></i><i></i></div>
              <b>{{ $root.tr("بطاقات الألعاب") }}</b>
              <div class="voucher-bottom">
                <span>•••• &nbsp; •••• &nbsp; ••••</span
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path d="M8 7a7 7 0 0 1 0 10m4-14a12 12 0 0 1 0 18"></path>
                </svg>
              </div>
            </div>
            <div class="floating-voucher voucher-internet">
              <div class="voucher-top">
                <span>{{ $root.tr("ماسال") }}</span
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M3 8a15 15 0 0 1 18 0M6 12a10 10 0 0 1 12 0m-9 4a5 5 0 0 1 6 0"
                  ></path>
                  <circle cx="12" cy="20" r="1"></circle>
                </svg>
              </div>
              <div class="voucher-chip"><i></i><i></i><i></i></div>
              <b>{{ $root.tr("باقات الإنترنت") }}</b>
              <div class="voucher-bottom">
                <span>•••• &nbsp; •••• &nbsp; ••••</span
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path d="M8 7a7 7 0 0 1 0 10m4-14a12 12 0 0 1 0 18"></path>
                </svg>
              </div>
            </div>
          </div>
          <h2 class="login-scene-heading">
            {{ $root.tr("عالم البطاقات، بين يديك") }}
          </h2>
          <p class="login-scene-copy">
            {{ $root.tr("إدارة البطاقات الإلكترونية وشبكة التوزيع") }}
          </p>
          <div class="login-scene-tags">
            <span>{{ $root.tr("اتصالات") }}</span
            ><span>{{ $root.tr("ألعاب") }}</span
            ><span>{{ $root.tr("إنترنت") }}</span>
          </div>
        </div>
      </div>
      <footer class="login-footer">
        {{ $root.tr("جميع الحقوق محفوظة لشركة عراق تكنو للحلول البرمجية") }}
      </footer>
    </section>
    <div
      v-if="!loginScreen"
      class="shell"
      :class="{'sidebar-collapsed':sidebarCollapsed&amp;&amp;!sidebarHoverOpen}"
      :inert="modal ? true : null"
    >
      <button
        v-if="menuOpen"
        class="mobile-shade"
        @click="menuOpen = false"
        :aria-label="tr('إغلاق القائمة الجانبية')"
      ></button>
      <aside
        id="main-sidebar"
        class="sidebar"
        @mouseenter="!viewportMobile&amp;&amp;sidebarCollapsed&amp;&amp;(sidebarHoverOpen=true)"
        @mouseleave="sidebarHoverOpen = false"
        :class="{ open: menuOpen }"
        :inert="viewportMobile&amp;&amp;!menuOpen?true:null"
      >
        <div class="brand">
          <div class="brandmark">
            <svg
              width="25"
              height="25"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.2"
              stroke-linecap="round"
            >
              <rect x="2" y="5" width="20" height="14" rx="3"></rect>
              <path d="M2 10h20M7 15h1m3 0h2"></path>
            </svg>
          </div>
          <div>
            <strong>{{ tr("ماسال") }}</strong
            ><span class="eyebrow">MASAL</span>
          </div>
        </div>
        <nav class="sidebar-navigation" :aria-label="tr('القائمة الرئيسية')">
          <section
            v-for="(group, gi) in navGroups"
            class="nav-section"
            :class="{
              'is-open': !collapsedGroups[group.title],
              'direct-section': group.direct,
            }"
          >
            <button
              v-if="!group.direct"
              class="navgroup"
              :class="{active:group.items.some(n=&gt;n.id===page)}"
              :title="tr(group.title)"
              :aria-label="tr(group.title)"
              @click="toggleNavGroup(group.title)"
              :aria-expanded="!collapsedGroups[group.title]"
              :aria-controls="'nav-group-' + gi"
            >
              <span class="navicon" aria-hidden="true"
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.7"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    :d="navIconPath(group.icon || 'security')"
                  ></path></svg></span
              ><span class="group-label">{{ tr(group.title) }}</span
              ><svg
                class="nav-chevron"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
              >
                <path d="m6 8 4 4 4-4"></path>
              </svg>
            </button>
            <div
              class="nav-accordion"
              :id="'nav-group-' + gi"
              :inert="!group.direct&amp;&amp;(collapsedGroups[group.title]||(!viewportMobile&amp;&amp;sidebarCollapsed&amp;&amp;!sidebarHoverOpen))?true:null"
            >
              <div class="navlinks">
                <button
                  v-for="n in group.items"
                  class="navitem"
                  :title="tr(n.label)"
                  :aria-label="tr(n.label)"
                  :class="{active:page===n.id||(page==='audit'&amp;&amp;n.id==='reports')||(page==='monitoring'&amp;&amp;n.id==='sales')}"
                  :aria-current="page === n.id ? 'page' : undefined"
                  @click="go(n.id)"
                >
                  <span class="navicon" aria-hidden="true"
                    ><svg
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.7"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path :d="navIconPath(n.id)"></path></svg></span
                  ><span class="nav-label">{{ tr(n.label) }}</span>
                </button>
              </div>
            </div>
          </section>
        </nav>
        <div class="sidebar-account">
          <span class="account-avatar">{{
            $root.tr(actor.name.slice(0, 1))
          }}</span>
          <div>
            <strong>{{ $root.tr(actor.name) }}</strong
            ><small>{{ tr("الحساب الحالي") }}</small>
          </div>
          <button
            class="sidebar-logout"
            @click="logoutToLogin"
            :title="$root.tr('تسجيل الخروج')"
            :aria-label="$root.tr('تسجيل الخروج')"
          >
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4"></path>
            </svg></button
          ><button
            v-if="viewportMobile"
            class="iconbtn"
            @click="menuOpen = false"
            :aria-label="tr('إغلاق القائمة')"
          >
            ×
          </button>
        </div>
      </aside>
      <main class="main" :data-page="page">
        <pos-mobile-header v-if="posMobileEnabled"></pos-mobile-header>
        <header v-else class="topbar">
          <button
            class="iconbtn menuToggle"
            @click="toggleSidebar"
            :aria-label="
              tr(
                viewportMobile
                  ? menuOpen
                    ? 'إغلاق القائمة'
                    : 'فتح القائمة'
                  : sidebarCollapsed
                    ? 'إظهار القائمة'
                    : 'طي القائمة',
              )
            "
            :aria-expanded="
              viewportMobile ? menuOpen : !sidebarCollapsed || sidebarHoverOpen
            "
            aria-controls="main-sidebar"
          >
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
            >
              <path d="M5 6h14M5 12h14M5 18h14"></path>
            </svg>
          </button>
          <div class="breadcrumb">
            <strong>{{ tr("ماسال") }}</strong>
          </div>
          <div class="toptools">
            <button
              v-if="actor.role !== 'owner'"
              class="btn small location-share-control"
              :aria-pressed="locationSharing"
              :title="$root.tr(locationStatus)"
              @click="
                locationSharing ? stopLocationSharing() : startLocationSharing()
              "
            >
              {{
                $root.tr(
                  locationSharing
                    ? "إيقاف مشاركة موقعي"
                    : "مشاركة موقعي مع الإدارة",
                )
              }}</button
            ><select
              class="language-picker"
              v-model="lang"
              @change="setLanguage"
              :aria-label="tr('لغة الواجهة')"
            >
              <option value="ar">{{ tr("العربية") }}</option>
              <option value="en">English</option>
              <option value="ckb">{{ tr("کوردی") }}</option></select
            ><button
              class="iconbtn quick-button"
              @click="openQuickActions"
              :aria-label="tr('إجراء سريع')"
              :title="tr('إجراء سريع')"
            >
              <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              >
                <path d="M12 5v14M5 12h14"></path>
              </svg></button
            ><button
              class="iconbtn theme-toggle"
              @click="toggleTheme"
              :aria-label="
                tr(
                  theme === 'light'
                    ? 'تفعيل الوضع الليلي'
                    : 'تفعيل الوضع النهاري',
                )
              "
              :title="tr(theme === 'light' ? 'الوضع الليلي' : 'الوضع النهاري')"
              :aria-pressed="theme === 'dark'"
            >
              <svg
                v-if="theme === 'light'"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
              >
                <path
                  d="M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z"
                ></path></svg
              ><svg
                v-else=""
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
              >
                <circle cx="12" cy="12" r="4"></circle>
                <path
                  d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"
                ></path>
              </svg></button
            ><input
              class="search"
              :placeholder="tr('ابحث في وحدات النظام…')"
              v-model="navSearch"
              :aria-label="tr('بحث الوحدات')"
            /><button
              class="iconbtn notification-bell"
              @click="go('notifications')"
              :aria-label="tr('الإشعارات')"
            >
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path :d="navIconPath('notifications')"></path></svg
              ><span v-if="unreadNotices" class="notification-count">{{
                $root.tr(unreadNotices)
              }}</span>
            </button>
            <div class="avatar">{{ tr(actor.name.slice(0, 1)) }}</div>
            <select
              class="profile-picker"
              v-model="currentUser"
              :aria-label="tr('محاكاة دور المستخدم')"
              @change="switchUser"
            >
              <option v-for="u in s.users.filter(u=&gt;u.active)" :value="u.id">
                {{ tr(profileAccountLabel(u)) }}
              </option>
            </select>
          </div>
        </header>

        <template
          v-if="!posMobileCatalog&amp;&amp;!(posMobileEnabled&amp;&amp;page==='sales')"
          ><div
            v-if="dashboardFilter&amp;&amp;dashboardFilter.page===page"
            class="dashboard-filter-bar"
          >
            <span
              >{{ tr(dashboardFilter.title)
              }}<small
                v-if="
                  ['sales', 'quantity', 'profit'].includes(dashboardFilter.key)
                "
              >
                · {{ $root.tr(dashboardFilter.day) }}</small
              ></span
            ><button class="btn small" @click="clearDashboardFilter">
              {{ tr("عرض الكل") }}
            </button>
          </div>
          <funding-request-settings></funding-request-settings
          ><account-time-settings></account-time-settings
          ><print-policy-settings></print-policy-settings
          ><operation-control></operation-control
          ><company-page ref="companyPage"></company-page
          ><governorates-page></governorates-page><order-sources></order-sources
          ><branding-switcher></branding-switcher><card-designer></card-designer
          ><completion-panel ref="completion"></completion-panel
          ><operations-panel
            v-if="
              ![
                'inventory',
                'pos',
                'claims',
                'exports',
                'integrations',
              ].includes(page)
            "
            ref="operations"
          ></operations-panel
          ><workflow-panel
            v-if="!['claims', 'exports'].includes(page)"
            ref="workflow"
          ></workflow-panel
        ></template>

        <!-- Dashboard -->
        <template v-if="posMobileCatalog"
          ><pos-mobile-catalog
            :key="'pos-catalog-' + currentUser"
          ></pos-mobile-catalog></template
        ><template v-else-if="posMobileEnabled&amp;&amp;page==='sales'"
          ><pos-mobile-operations
            :key="'pos-operations-' + currentUser"
          ></pos-mobile-operations></template
        ><DashboardView v-else-if="page === 'dashboard'" />
        <!-- Generic managed entities -->
        <CatalogView v-else-if="schema" />
        <!-- Import wizard -->
        <ImportView v-else-if="page === 'import'" />
        <!-- Inventory and batches -->
        <InventoryView v-else-if="['inventory', 'batches'].includes(page)" />
        <!-- Wallets -->
        <template v-else-if="false"
          ><div class="two">
            <div class="card">
              <div class="cardhead">
                <span class="badge neutral">{{ tr("بالدينار العراقي") }}</span>
              </div>
              <form
                v-permit="can('wallets.transfer')"
                @submit.prevent="reviewTransfer"
              >
                <div class="formgrid">
                  <label
                    >{{ tr("من محفظة")
                    }}<select v-model="transferForm.from">
                      <option v-for="a in visibleAgents" :value="a.id">
                        {{ tr(a.name) }} • {{ tr(money(engine.balance(a.id))) }}
                      </option>
                    </select></label
                  ><label
                    >{{ tr("إلى محفظة")
                    }}<select v-model="transferForm.to">
                      <option v-for="a in accounts" :value="a.id">
                        {{ tr(a.name) }}
                      </option>
                    </select></label
                  ><label class="full"
                    >{{ tr("المبلغ")
                    }}<input
                      type="text"
                      inputmode="decimal"
                      v-money=""
                      min="1"
                      required=""
                      v-model.number="transferForm.amount"
                  /></label>
                </div>
                <div class="formfoot">
                  <button class="btn primary">{{ tr("تنفيذ التحويل") }}</button>
                </div>
              </form>
            </div>
            <div class="card">
              <div class="cardhead">
                <button
                  v-if="can('wallets.deposit')"
                  class="btn small"
                  v-permit="can('wallets.deposit')"
                  @click="openDeposit"
                >
                  {{ tr("＋ إيداع") }}
                </button>
              </div>
              <div v-for="a in accounts" class="rowline">
                <span>{{ tr(a.name) }}</span
                ><strong class="mono"
                  >{{ tr(money(engine.balance(a.id))) }}
                  <small>{{ tr("د.ع") }}</small></strong
                >
              </div>
            </div>
          </div>
          <div class="card" style="margin-top: 20px">
            <div class="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>{{ tr("المرجع") }}</th>
                    <th>{{ tr("الحساب") }}</th>
                    <th>{{ tr("الحركة") }}</th>
                    <th>{{ tr("المبلغ") }}</th>
                    <th>{{ tr("التاريخ") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in visibleLedger.slice().reverse()">
                    <td class="mono">{{ tr(l.group) }}</td>
                    <td>{{ tr(accountName(l.account)) }}</td>
                    <td>{{ tr(l.kind) }}</td>
                    <td
                      class="mono"
                      :style="{color:l.amount&lt;0?'#c14b5a':'#0f8a72'}"
                    >
                      {{ tr(money(l.amount)) }}
                    </td>
                    <td>{{ tr(formatTime(l.time)) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div></template
        >
        <!-- Pricing -->
        <PricesView v-else-if="page === 'prices'" />
        <!-- Sales terminal -->
        <SellView v-else-if="page === 'sell'" />
        <!-- Transactions and exceptions -->
        <SalesView v-else-if="['sales', 'exceptions'].includes(page)" />
        <!-- Claims and exports -->
        <ClaimsView v-else-if="['claims', 'exports'].includes(page)" />
        <!-- Permissions -->
        <PermissionsView v-else-if="page === 'permissions'" />
        <!-- Integrations -->
        <IntegrationsView v-else-if="page === 'integrations'" />
        <DigitalServicesView v-else-if="page === 'digital'" />
        <!-- Notifications -->
        <NotificationsView v-else-if="page === 'notifications'" />
        <!-- Support -->
        <DeletedAccountsView
          v-else-if="page === 'deletedAccounts'"
        /><SupportView v-else-if="page === 'support'" />
        <!-- Map -->
        <MapView v-else-if="page === 'map'" />
        <!-- Reports -->
        <ReportsView v-else-if="page === 'reports'" />
        <!-- Audit -->
        <AuditView v-else-if="page === 'audit'" />
        <!-- Monitoring -->
        <MonitoringView v-else-if="page === 'monitoring'" />
        <!-- Security -->
        <template v-else-if="page === 'security'"></template>
        <!-- Branding -->
        <template v-else-if="page === 'branding'"></template>
        <!-- Landing and CMS -->
        <!-- Backup -->
        <BackupView v-else-if="page === 'backup'" />
        <div v-if="page === 'denied'" class="card empty">
          {{ tr("لا توجد وحدات مسموحة لهذا الحساب") }}
        </div>
        <footer
          class="footer app-footer"
          style="justify-content: center; text-align: center"
        >
          <span
            >© {{ $root.tr(new Date().getFullYear())
            }}{{
              $root.tr(" جميع الحقوق محفوظة لشركة عراق تكنو للحلول البرمجية")
            }}</span
          >
        </footer>
        <pos-mobile-nav v-if="posMobileEnabled"></pos-mobile-nav>
      </main>
    </div>
    <!-- Forms modal -->

    <div v-if="modal" class="overlay" @click.self="closeModal">
      <section
        class="modal"
        role="dialog"
        aria-modal="true"
        :aria-label="tr(modal.title)"
      >
        <div class="cardhead dialog-heading">
          <div class="dialog-heading-main">
            <span class="dialog-icon" aria-hidden="true"
              ><svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path
                  :d="
                    modal.kind === 'edit' || modal.kind === 'staff'
                      ? 'M12 5v14M5 12h14'
                      : navIconPath(page)
                  "
                ></path></svg
            ></span>
            <div>
              <span class="dialog-section">{{ tr(title) }}</span>
              <h2>{{ tr(modal.title) }}</h2>
            </div>
          </div>
          <button
            class="iconbtn dialog-close"
            @click="closeModal"
            :aria-label="tr('إغلاق')"
          >
            ×
          </button>
        </div>
        <report-detail-dialog
          v-if="modal.kind === 'reportDetails'"
        ></report-detail-dialog
        ><wallet-account-detail
          v-else-if="modal.kind === 'walletAccountDetail'"
        ></wallet-account-detail
        ><wallet-filter-dialog
          v-else-if="modal.kind === 'walletFilters'"
        ></wallet-filter-dialog
        ><provider-preview
          v-else-if="modal.kind === 'providerPreview'"
          :provider="modal.provider"
        ></provider-preview
        ><CardDesignPreviewDialog
          v-else-if="modal.kind === 'cardDesignPreview'"
        /><category-preview
          v-else-if="modal.kind === 'categoryPreview'"
          :product="modal.product"
        ></category-preview
        ><ReportSettingsDialog
          v-else-if="modal.kind === 'reportSettings'"
        /><ProfileReviewDialog v-else-if="modal.kind === 'profileReview'" />
        <AccountDetailsDialog
          v-else-if="modal.kind === 'accountDetails' && accountDetails"
        />
        <form
          v-else-if="modal.kind === 'ownerName'"
          @submit.prevent="saveOwnerName"
        >
          <label
            >{{ $root.tr("اسم مدير النظام")
            }}<input
              v-model="staffForm.name"
              required=""
              maxlength="120"
              :aria-label="$root.tr('اسم مدير النظام')"
          /></label>
          <div class="formfoot">
            <button type="button" class="btn" @click="closeModal">
              {{ $root.tr("إلغاء") }}</button
            ><button class="btn primary">{{ $root.tr("حفظ الاسم") }}</button>
          </div>
        </form>
        <form v-else-if="modal.kind === 'staff'" @submit.prevent="saveStaff">
          <div class="formgrid staff-form">
            <label class="full"
              >{{ $root.tr("ملاحظات (اختياري)")
              }}<textarea
                v-model="staffForm.notes"
                maxlength="2000"
                rows="3"
              ></textarea></label
            ><label
              >{{ tr("اسم الموظف")
              }}<input
                v-model="staffForm.name"
                name="employee-name"
                autocomplete="name"
                required=""
                maxlength="120" /></label
            ><label
              >{{ tr("البريد الإلكتروني")
              }}<input
                v-model="staffForm.email"
                name="employee-email"
                type="email"
                dir="ltr"
                autocomplete="email"
                required="" /></label
            ><label v-if="!staffForm.id"
              >{{ tr(staffForm.id ? "كلمة مرور جديدة" : "كلمة المرور") }}
              <div class="staff-password">
                <input
                  v-model="staffForm.password"
                  name="employee-password"
                  :minlength="staffForm.id ? 10 : 8"
                  :type="staffShowPassword ? 'text' : 'password'"
                  dir="ltr"
                  autocomplete="new-password"
                  :required="!staffForm.id"
                  :placeholder="
                    tr(
                      staffForm.id
                        ? 'اتركها فارغة للإبقاء على كلمة المرور'
                        : '8 أحرف على الأقل',
                    )
                  "
                /><button
                  type="button"
                  class="btn small"
                  @click="staffShowPassword = !staffShowPassword"
                  :aria-pressed="staffShowPassword"
                >
                  {{ tr(staffShowPassword ? "إخفاء" : "إظهار") }}
                </button>
              </div></label
            ><button
              v-if="staffForm.id&amp;&amp;canResetUserPassword(staffForm.id)"
              type="button"
              class="btn"
              @click="requestPasswordReset(staffForm.id)"
            >
              {{ $root.tr("طلب إعادة تعيين كلمة المرور") }}</button
            ><label
              >{{ tr("نوع الصلاحية")
              }}<select
                v-model="staffForm.permissionProfileId"
                name="employee-permission"
                :required="staffForm.role === 'employee'"
                :disabled="
                  staffForm.id === currentUser || staffForm.role === 'owner'
                "
              >
                <option value="">
                  {{
                    tr(
                      staffForm.role === "employee"
                        ? "اختر نوع الصلاحية"
                        : "صلاحيات الدور الحالي",
                    )
                  }}
                </option>
                <option v-for="p in assignableProfiles" :value="p.id">
                  {{ $root.tr(p.name) }}
                </option>
                <option
                  v-if="staffForm.permissionProfileId&amp;&amp;!assignableProfiles.some(p=&gt;p.id===staffForm.permissionProfileId)"
                  :value="staffForm.permissionProfileId"
                >
                  {{ $root.tr(permissionProfiles.find(p=&gt;p.id===staffForm.permissionProfileId)?.name) }}
                  · {{ tr("موقوف") }}
                </option>
              </select></label
            >
          </div>
          <div class="formfoot">
            <button type="button" class="btn" @click="closeModal">
              {{ tr("إلغاء") }}</button
            ><button class="btn primary" :disabled="staffSaving">
              {{ tr(staffSaving ? "جارٍ الحفظ…" : "حفظ الموظف") }}
            </button>
          </div>
        </form>
        <PermissionReviewDialog
          v-else-if="modal.kind === 'permissionReview'"
        /><QuickActionsDialog v-else-if="modal.kind === 'quick'" />
        <TransferReviewDialog v-else-if="modal.kind === 'transferReview'" />
        <form
          v-else-if="modal.kind === 'networkCategories'"
          @submit.prevent="saveNetworkCategories"
        >
          <agent-product-picker
            :agent="networkCategoryDraft"
            :available-ids="networkCategoryAvailableIds"
            inline
          ></agent-product-picker>
          <div class="formfoot">
            <button type="button" class="btn" @click="closeModal">
              {{ tr("إلغاء") }}</button
            ><button class="btn primary">{{ tr("حفظ الفئات") }}</button>
          </div>
        </form>
        <form
          v-else-if="modal.kind === 'networkPermissions'"
          @submit.prevent="saveNetworkPermissions"
          class="network-permissions-form"
        >
          <label
            >{{ tr("بحث في الصلاحيات")
            }}<input
              type="search"
              v-model="networkPermissionSearch"
              :placeholder="$root.tr('إنشاء، تمويل، طباعة…')"
          /></label>
          <div class="network-permission-groups">
            <fieldset
              v-for="group in networkPermissionGroups"
              :key="group.module"
            >
              <legend>{{ tr(group.title) }}</legend>
              <label
                v-for="item in group.items"
                :key="item.key"
                class="network-permission-choice"
                :class="{ locked: item.locked }"
                ><input
                  type="checkbox"
                  :data-network-permission="item.key"
                  v-model="networkPermissionDraft[item.key]"
                  :disabled="item.locked"
                /><span
                  >{{ tr(item.label)
                  }}<small v-if="item.locked">{{
                    tr("مقيدة من الأعلى أو تتطلب عرض القسم")
                  }}</small></span
                ></label
              >
            </fieldset>
          </div>
          <label
            >{{ tr("سبب التعديل")
            }}<textarea
              v-model="networkPermissionReason"
              required=""
            ></textarea>
          </label>
          <div class="formfoot">
            <button type="button" class="btn" @click="closeModal">
              {{ tr("إلغاء") }}</button
            ><button class="btn primary">{{ tr("حفظ الصلاحيات") }}</button>
          </div>
        </form>
        <network-archive-confirm
          v-else-if="modal.kind === 'archiveNetwork'"
        ></network-archive-confirm
        ><AgentProductsDialog v-else-if="modal.kind === 'agentProducts'" />
        <form
          v-else-if="modal.kind === 'edit'"
          v-permit="can(page + '.' + (editForm.id ? 'edit' : 'create'))"
          @submit.prevent="saveEntity"
        >
          <category-daily-limit
            v-if="page === 'products'"
          ></category-daily-limit>
          <div class="formgrid">
            <component
              :is="f.type === 'cardFields' ? 'div' : 'label'"
              v-for="f in schema.fields"
              :class="{ full: ['textarea', 'cardFields'].includes(f.type) }"
              >{{ tr(f.label) }}
              <div v-if="f.type === 'cardFields'" class="card-field-editor">
                <p class="help">
                  {{
                    tr(
                      "حدد البيانات الموجودة في البطاقة. كل حقل محدد مطلوب عند الاستيراد، وغير المحدد لا يستخدم.",
                    )
                  }}
                </p>
                <label
                  v-for="field in cardFieldOptions"
                  :key="field.key"
                  class="card-field-option"
                  :class="{
                    'is-selected':
                      editForm.fieldPolicy[field.key] === 'required',
                  }"
                  ><input
                    type="checkbox"
                    v-model="editForm.fieldPolicy[field.key]"
                    true-value="required"
                    false-value="unused"
                    :aria-label="tr(field.label)"
                    :disabled="field.fixed"
                  /><span
                    ><b>{{ tr(field.label) }}</b
                    ><small>{{
                      tr(
                        field.fixed
                          ? "مطلوب دائمًا"
                          : editForm.fieldPolicy[field.key] === "required"
                            ? "مطلوب عند الاستيراد"
                            : "غير مستخدم",
                      )
                    }}</small></span
                  ></label
                >
                <p class="help">
                  {{
                    tr(
                      "رمز الشحن وتاريخ الانتهاء مطلوبان للبيع وإدارة صلاحية المخزون. يمكن أخذ تاريخ الانتهاء من تاريخ الدفعة عند غيابه من الملف.",
                    )
                  }}
                </p>
              </div>
              <div v-else-if="f.type === 'agentScope'" class="scope-options">
                <label v-for="a in visibleAgents" class="inline-check"
                  ><input
                    type="checkbox"
                    :checked="
                      (editForm.assignedText || '').split(',').includes(a.id)
                    "
                    @change="toggleAssignedAgent(a.id, $event.target.checked)"
                  />{{ tr(a.name) }}</label
                >
              </div>
              <select
                v-else-if="f.options"
                :disabled="networkFieldLocked(f)"
                v-model="editForm[f.key]"
                :required="f.required !== false"
              >
                <option v-if="f.required === false" value="">
                  {{ tr("بدون") }}
                </option>
                <option v-for="o in optionsFor(f)" :value="o.value">
                  {{ tr(o.label) }}
                </option></select
              ><textarea
                v-else-if="f.type === 'textarea'"
                v-model="editForm[f.key]"
                :required="f.required !== false"
              ></textarea
              ><input
                v-money="
                  ['face', 'min', 'dailyAmount', 'creditLimit'].includes(f.key)
                "
                v-else=""
                :type="
                  ['face', 'min', 'dailyAmount', 'creditLimit'].includes(f.key)
                    ? 'text'
                    : f.type || 'text'
                "
                v-model="editForm[f.key]"
                :min="f.min"
                :max="f.max"
                :step="f.type === 'number' ? 'any' : undefined"
                :required="f.required !== false"
            /></component>
          </div>
          <pos-serial-policy
            v-if="page==='pos' &amp;&amp; can('pos.device')"
          ></pos-serial-policy
          ><agent-product-picker
            v-if="page==='agents' &amp;&amp; editForm.type==='رئيسي' &amp;&amp; actor.role==='owner'"
          ></agent-product-picker
          ><representative-picker
            v-if="page==='pos' &amp;&amp; can('pos.representatives')"
          ></representative-picker
          ><pos-personal-photo
            v-if="page==='pos' &amp;&amp; can('pos.documents')"
          ></pos-personal-photo
          ><representative-photos
            v-if="page === 'representatives'"
          ></representative-photos
          ><pos-documents-editor
            v-if="page==='pos' &amp;&amp; can('pos.documents')"
          ></pos-documents-editor
          ><provider-editor v-if="page === 'providers'"></provider-editor
          ><category-editor v-if="page === 'products'"></category-editor
          ><label
            v-if="['agents', 'pos'].includes(page)"
            class="account-notes-field"
            >{{ $root.tr("ملاحظات (اختياري)")
            }}<textarea
              v-model="editForm.notes"
              maxlength="2000"
              rows="3"
            ></textarea>
          </label>
          <section v-if="page === 'agents'" class="network-image-field">
            <h3>{{ tr("الصورة (اختياري)") }}</h3>
            <image-attachment
              :key="page + ':' + (editForm.id || 'new')"
              v-model="editForm.image"
              @busy="networkImageBusy = $event"
            ></image-attachment>
          </section>
          <section
            v-if="['agents', 'pos'].includes(page)"
            class="network-login-fields"
          >
            <h3>{{ tr("حساب الدخول") }}</h3>
            <button
              v-if="networkLinkedAccount&amp;&amp;canResetUserPassword(networkLinkedAccount.id)"
              type="button"
              class="btn"
              @click="requestPasswordReset(networkLinkedAccount.id)"
            >
              {{ $root.tr("طلب إعادة تعيين كلمة المرور") }}</button
            ><template v-if="networkLinkedAccount"
              ><label
                >{{ $root.tr("بريد أو اسم مستخدم تسجيل الدخول")
                }}<input
                  type="text"
                  dir="ltr"
                  v-model.trim="networkLogin.email"
                  required=""
                  autocomplete="username"
                  :readonly="actor.role !== 'owner'"
                  :aria-readonly="actor.role !== 'owner'"
                  :aria-label="$root.tr('بريد تسجيل الدخول')"
                /><small v-if="actor.role !== 'owner'">{{
                  $root.tr("التعديل متاح لمدير النظام فقط")
                }}</small></label
              >
              <p class="help">
                {{ tr("الحساب المرتبط:") }}
                {{ $root.tr(networkLinkedAccount.name) }}
              </p></template
            ><template v-else=""
              ><div class="formgrid">
                <label
                  >{{ tr("البريد الإلكتروني للدخول")
                  }}<input
                    type="email"
                    v-model="networkLogin.email"
                    required=""
                    autocomplete="off"
                    :disabled="networkSaving" /></label
                ><label
                  >{{ tr("كلمة مرور الحساب")
                  }}<input
                    type="password"
                    v-model="networkLogin.password"
                    minlength="8"
                    required=""
                    autocomplete="new-password"
                    :disabled="networkSaving"
                  /><small>{{ tr("8 أحرف على الأقل") }}</small></label
                >
              </div></template
            >
          </section>
          <div class="formfoot">
            <button type="button" class="btn" @click="closeModal">
              {{ tr("إلغاء") }}</button
            ><button
              class="btn primary"
              :disabled="(page==='providers' &amp;&amp; (providerLogoBusy || providerReceiptBusy)) || networkSaving || networkImageBusy || (page==='products' &amp;&amp; (categoryImageBusy || categoryReceiptImageBusy))"
            >
              {{ tr(networkSaving ? "جارٍ الحفظ…" : "حفظ البيانات") }}
            </button>
          </div>
        </form>
        <DashboardDetailsDialog
          v-else-if="modal.kind === 'dashboardDetail'"
        /><SaleDetailsDialog
          v-else-if="modal.kind === 'saleDetails'"
        /><NoticeDetailsDialog
          v-else-if="modal.kind === 'noticeDetail'"
        /><POSDetailsDialog
          v-else-if="modal.kind === 'posDetails'"
        /><InspectDialog v-else-if="modal.kind === 'inspect'" />
        <BatchDetailsDialog v-else-if="modal.kind === 'batch'" />
        <ReceiptDialog v-else-if="modal.kind === 'receipt'" />
        <form
          v-else-if="modal.kind === 'reprint'"
          v-permit="can('sell.reprint')"
          @submit.prevent="submitReprint"
        >
          <div class="notice">
            {{ tr("نفس البطاقات دون خصم جديد. عدد المحاولات السابقة: ")
            }}{{ tr(modal.tx.reprints) }}
          </div>
          <label
            >{{ tr("سبب إعادة الطباعة")
            }}<textarea v-model="reason" required=""></textarea>
          </label>
          <div class="formfoot">
            <button class="btn primary">{{ tr("طلب إعادة الطباعة") }}</button>
          </div>
        </form>
        <form
          v-else-if="modal.kind === 'deposit'"
          v-permit="can('wallets.deposit')"
          @submit.prevent="deposit"
        >
          <label
            >{{ tr("الحساب")
            }}<select v-model="depositForm.account">
              <option v-for="a in accounts" :value="a.id">
                {{ tr(a.name) }}
              </option>
            </select></label
          ><label
            >{{ tr("المبلغ • د.ع")
            }}<input
              type="text"
              inputmode="decimal"
              v-money=""
              min="1"
              v-model.number="depositForm.amount"
              required="" /></label
          ><label
            >{{ tr("مرجع الإيداع")
            }}<input v-model="depositForm.reference" required=""
          /></label>
          <div class="formfoot">
            <button class="btn primary">{{ tr("تسجيل الإيداع") }}</button>
          </div>
        </form>
        <form
          v-else-if="modal.kind === 'newTicket'"
          @submit.prevent="saveTicket"
        >
          <label v-if="can('support.broadcast')"
            >{{ $root.tr("المستخدمين")
            }}<select
              v-model="supportAudience"
              :aria-label="$root.tr('مستخدمو رسالة الدعم')"
            >
              <option value="all">
                {{ $root.tr("عام لكل المستخدمين ضمن نطاقي") }}
              </option>
              <option value="agents">
                {{ $root.tr("الوكلاء الرئيسيون") }}
              </option>
              <option value="branches">{{ $root.tr("الفروع") }}</option>
              <option value="custom">{{ $root.tr("مخصص") }}</option>
              <option value="direct">{{ $root.tr("مستلم مباشر") }}</option>
            </select></label
          >
          <div v-if="supportAudience === 'custom'">
            <input
              v-model="supportSearch"
              :placeholder="$root.tr('بحث المستخدمين')"
              :aria-label="$root.tr('بحث مستلمي الدعم')"
            />
            <div class="scope-options">
              <label v-for="u in supportBroadcastChoices" :key="u.id"
                ><input
                  type="checkbox"
                  v-model="supportSelected"
                  :value="u.id"
                />{{ $root.tr(u.name) }}</label
              >
            </div>
          </div>
          <label v-if="supportAudience === 'direct'"
            >{{ $root.tr("المرسل إليه")
            }}<select v-model="ticketForm.recipient" required="">
              <option value="" disabled="">
                {{ $root.tr("اختر المستلم") }}
              </option>
              <option v-for="a in supportRecipients" :value="a.id">
                {{ $root.tr(a.name) }}
              </option>
            </select></label
          ><label
            >{{ $root.tr("العنوان")
            }}<input v-model="ticketForm.title" required="" /></label
          ><label
            >{{ $root.tr("الشرح")
            }}<textarea
              v-model="ticketForm.description"
              required=""
            ></textarea></label
          ><image-attachment
            v-if="can('support.attach')"
            v-model="ticketForm.image"
            @busy="attachmentBusy = $event"
          ></image-attachment>
          <div class="formfoot">
            <button
              class="btn primary"
              :disabled="formPending.saveTicket || attachmentBusy"
            >
              {{ $root.tr("إرسال") }}
            </button>
          </div>
        </form>
        <TicketDialog v-else-if="modal.kind === 'ticket'" />
        <InventoryManagerDialog
          v-else-if="modal.kind === 'inventoryManager'"
        /><InventoryActionDialog
          v-else-if="modal.kind === 'inventoryAction'"
        /><ConfirmationDialog v-else-if="modal.kind === 'confirm'" />
      </section>
    </div>
    <teleport to="body"
      ><div
        v-if="toast"
        class="toast"
        :class="{ error: toast.error }"
        :role="toast.error ? 'alert' : 'status'"
        aria-atomic="true"
      >
        {{ tr(toast.text) }}
      </div></teleport
    >
  </div>
</template>
