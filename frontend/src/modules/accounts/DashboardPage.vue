<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import { usePortal } from '../auth/session.js';
import { accountTypeLabel } from '../../app/portal-config.js';
const { portal, session } = usePortal();
</script>

<template>
  <div class="page-heading"><p class="eyebrow">{{ uiLabel(portal.title) }}</p><h1>الرئيسية</h1><p class="muted">مرحبًا {{ session.state.identity?.user.name }}.</p></div>
  <section v-if="session.state.identity" class="panel" aria-labelledby="account-title">
    <h2 id="account-title">حسابك</h2>
    <dl class="identity-grid">
      <div><dt>الحساب</dt><dd>{{ session.state.identity.account.name }}</dd></div>
      <div><dt>نوع الحساب</dt><dd>{{ accountTypeLabel(session.state.identity.account.type) }}</dd></div>
      <div><dt>المستخدم</dt><dd>{{ session.state.identity.user.name }}</dd></div>
      <div><dt>اسم الدخول</dt><dd dir="auto">{{ session.state.identity.user.login || session.state.identity.user.email }}</dd></div>
    </dl>
    <RouterLink v-if="session.can('account.view')" class="button button-primary inline-button" :to="session.state.identity.account.type==='pos'?'/pos':'/agents'">عرض الحسابات المتاحة</RouterLink>
  </section>
</template>
