<script>
import { componentOptions } from "../services/component-registry.js";
export default componentOptions(["pos-mobile-header"]);
</script>
<template>
  <header class="pos-phone-header">
    <button
      class="iconbtn"
      @click="vm.toggleSidebar()"
      :aria-label="vm.tr('فتح القائمة')"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
      >
        <path d="M4 6h16M4 12h16M4 18h16"></path>
      </svg>
    </button>
    <div>
      <strong>{{ $root.tr(point?.name || vm.actor.name) }}</strong
      ><small>{{ vm.tr("نقطة بيع") }}</small>
    </div>
    <button
      v-if="vm.can('notifications.view')"
      class="iconbtn notification-bell"
      @click="vm.go('notifications')"
      :aria-label="vm.tr('الإشعارات')"
    >
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
      >
        <path :d="vm.navIconPath('notifications')"></path></svg
      ><span v-if="vm.unreadNotices" class="notification-count">{{
        $root.tr(vm.unreadNotices)
      }}</span></button
    ><button
      class="iconbtn"
      @click="vm.openQuickActions()"
      :aria-label="vm.tr('إجراء سريع')"
    >
      +
    </button>
    <details class="pos-phone-settings">
      <summary :aria-label="vm.tr('الإعدادات')">⚙</summary>
      <div>
        <label
          >{{ vm.tr("اللغة")
          }}<select v-model="vm.lang" @change="vm.setLanguage()">
            <option value="ar">{{ $root.tr("العربية") }}</option>
            <option value="en">English</option>
            <option value="ckb">{{ $root.tr("کوردی") }}</option>
          </select></label
        ><button class="btn" @click="vm.toggleTheme()">
          {{ vm.tr("تبديل المظهر") }}</button
        ><button class="btn" @click="vm.startLocationSharing()">
          {{ vm.tr("مشاركة الموقع") }}</button
        ><button class="btn danger" @click="vm.logoutToLogin()">
          {{ vm.tr("تسجيل الخروج") }}
        </button>
      </div>
    </details>
  </header>
</template>
