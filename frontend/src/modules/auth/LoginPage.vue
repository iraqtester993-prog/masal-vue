<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import {landingRoute} from '../../router/access.js';
import { usePortal } from './session.js';
import MasalLogo from '../../shared/components/MasalLogo.vue';
import LoginCardScene from '../../shared/components/LoginCardScene.vue';
import PhoneRecovery from './PhoneRecovery.vue';
import { useTheme } from '../../shared/composables/theme.js';
import '../../shared/styles/login.css';
const { portal, session } = usePortal();
const router = useRouter();
const login = ref('');
const password = ref('');
const reveal = ref(false);
const error = ref('');
const recovering = ref(false), recovered = ref(false);
const { toggleTheme } = useTheme();
async function submit() {
  if (session.state.busy) return;
  error.value = '';
  try {
    await session.login(login.value.trim(), password.value);
    password.value = '';
    await router.replace(landingRoute(router, session));
  } catch (failure) {
    password.value = '';
    error.value = failure.status === 401 || failure.status === 422 ? 'تحقق من اسم الدخول وكلمة المرور.'
      : failure.status === 403 ? 'هذا الحساب غير مسموح له بالدخول إلى هذه البوابة.'
      : failure.status === 419 ? 'انتهت صلاحية محاولة الدخول. حاول مرة أخرى.'
      : failure.status === 429 ? 'محاولات الدخول كثيرة. انتظر قليلًا وحاول مرة أخرى.'
      : failure.message;
  }
}
</script>

<template>
  <main class="login-page" dir="rtl">
    <button type="button" class="login-theme iconbtn" aria-label="تبديل المظهر" @click="toggleTheme">◐</button>
    <div class="login-card">
      <section class="login-form-panel">
        <form @submit.prevent="submit">
          <h1>تسجيل الدخول</h1>
          <p class="login-portal-label">بوابة {{ uiLabel(portal.title) }}</p>
          <label for="login">البريد الإلكتروني أو اسم المستخدم</label>
          <div class="login-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M5 21v-3a7 7 0 0 1 14 0v3"/></svg>
            <input id="login" v-model="login" name="login" autocomplete="username" required maxlength="190" :disabled="session.state.busy" dir="auto" />
          </div>
          <label for="password">كلمة المرور</label>
          <div class="login-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4"/></svg>
            <input id="password" v-model="password" name="password" :type="reveal ? 'text' : 'password'" autocomplete="current-password" required :disabled="session.state.busy" />
            <button type="button" :aria-label="reveal ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'" :aria-pressed="reveal" @click="reveal = !reveal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path v-if="!reveal" d="m3 3 18 18"/></svg></button>
          </div>
          <p v-if="error || session.state.error" class="login-error" role="alert">{{ error || session.state.error }}</p>
          <button class="login-submit" type="submit" :disabled="session.state.busy">{{ session.state.busy ? 'جارٍ التحقق…' : 'تسجيل الدخول' }} <span aria-hidden="true">←</span></button>
          <button type="button" class="login-recovery" :disabled="session.state.busy" @click="recovering = true; recovered = false">نسيت كلمة المرور؟</button>
          <p v-if="recovered" role="status">تم تغيير كلمة المرور. سجل الدخول بكلمة المرور الجديدة.</p>
        </form>
      </section>
      <section class="login-welcome" aria-label="ماسال">
        <div class="login-brand"><div class="login-logo"><MasalLogo/></div><div><strong>ماسال</strong><small>لوحة إدارة شبكة التوزيع</small></div></div>
        <LoginCardScene/>
        <h2 class="login-scene-heading">عالم البطاقات، بين يديك</h2>
        <p class="login-scene-copy">إدارة البطاقات الإلكترونية وشبكة التوزيع</p>
        <div class="login-scene-tags"><span>اتصالات</span><span>ألعاب</span><span>إنترنت</span></div>
      </section>
    </div>
    <footer class="login-footer">جميع الحقوق محفوظة لشركة عراق تكنو للحلول البرمجية</footer>
    <PhoneRecovery v-if="recovering" @close="recovering = false" @complete="recovering = false; recovered = true; password = ''" />
  </main>
</template>
<style scoped>
.login-recovery{display:block;margin:16px auto 0;background:transparent;border:0;color:inherit;font:inherit;cursor:pointer;text-decoration:underline;text-underline-offset:4px}
</style>
