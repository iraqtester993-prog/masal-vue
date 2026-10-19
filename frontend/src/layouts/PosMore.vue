<script setup>
import {computed,inject,ref,watch} from 'vue';
import {useRouter} from 'vue-router';
import {usePortal} from '../modules/auth/session.js';
import {useTheme} from '../shared/composables/theme.js';
import {usePreferencesTranslator} from '../modules/preferences/preferences-state.js';
import {usePreferences} from '../modules/preferences/preferences-state.js';
import {languages} from '../modules/preferences/translate.js';
import CatalogModal from '../modules/catalog/CatalogModal.vue';
import PosAbout from './PosAbout.vue';
import PosLegal from './PosLegal.vue';
import PosDevice from './PosDevice.vue';
import PosPrinterSettings from './PosPrinterSettings.vue';
import PwaInstall from './PwaInstall.vue';
import {presenceContextKey} from '../modules/maps/presence-context.js';
import {posMoreNavigation} from './pos-more-navigation.js';
import {posMoreIcon,posMoreIdentity} from './pos-more-presentation.js';
import {useRouteRefresh} from '../shared/route-refresh.js';
import {requiresNativePrinter} from '../shared/native-print.js';
const preferences=usePreferences(),languageOpen=ref(false),aboutOpen=ref(false),legalOpen=ref(''),deviceOpen=ref(false),printerOpen=ref(false),deleteOpen=ref(false),deletePreview=ref(false);
async function changeLanguage(id){try{await preferences.setLanguage(id);languageOpen.value=false;}catch(e){error.value=e.message;}}
const {session}=usePortal(),router=useRouter(),t=usePreferencesTranslator(),{dark,toggleTheme}=useTheme();
const presence=inject(presenceContextKey,null),error=ref(''),working=ref(false),native=requiresNativePrinter();
const navigation=computed(()=>posMoreNavigation({routes:router.getRoutes(),identity:session.state.identity,can:session.can}));
const profile=computed(()=>posMoreIdentity(session.state.identity,import.meta.env.VITE_API_BASE_URL||'/api/v1')),photoFailed=ref(false);
watch(()=>profile.value.photo,()=>photoFailed.value=false);
async function refreshIdentity(){const value=await session.refresh({background:true});if(!value){error.value=session.state.error||t('تعذر تحديث الصفحة. حاول مرة أخرى.');return false;}error.value='';return true;}
useRouteRefresh(refreshIdentity,{canRefresh:()=>!working.value&&!session.state.busy&&!languageOpen.value&&!aboutOpen.value&&!legalOpen.value&&!deviceOpen.value&&!printerOpen.value&&!deleteOpen.value});
async function logout(){working.value=true;error.value='';try{await session.logout();await router.replace({name:'login'});}catch(cause){error.value=cause.message;}finally{working.value=false;}}
</script>
<template>
 <section class="pos-more-page">
  <section class="pos-more-profile" :aria-label="t('بيانات حسابك')">
   <span class="pos-more-avatar"><img v-if="profile.photo&&!photoFailed" :src="profile.photo" :alt="t('صورة صاحب النقطة')" decoding="async" @error="photoFailed=true"><span v-else aria-hidden="true">{{profile.initial}}</span></span>
   <div class="pos-more-profile-text"><strong>{{profile.name}}</strong><span v-if="profile.point">{{profile.point}}</span><small v-if="profile.parent">{{t('الوكيل التابع له')}}: <b>{{profile.parent}}</b></small></div>
  </section>
  <p v-if="error" class="notice warn" role="alert">{{error}}</p>
  <div class="pos-more-list">
   <RouterLink v-if="navigation.profile" :to="navigation.profile.to"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="posMoreIcon('profile')"/></svg><span>{{t(navigation.profile.label)}}</span><b aria-hidden="true">‹</b></RouterLink>
   <RouterLink v-for="item in [...navigation.services,...navigation.shortcuts]" :key="item.id" :to="item.to"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="posMoreIcon(item.id)"/></svg><span>{{t(item.label)}}</span><b aria-hidden="true">‹</b></RouterLink>
   <button type="button" role="switch" :aria-checked="dark" :aria-label="t('الوضع الليلي')" @click="toggleTheme"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.5 14A8.5 8.5 0 0 1 10 3.5 8.5 8.5 0 1 0 20.5 14Z"/></svg><span>{{t(dark?'الوضع النهاري':'الوضع الليلي')}}</span><span class="pos-switch" :class="{on:dark}" aria-hidden="true"><i/></span></button>
   <button type="button" @click="languageOpen=true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="posMoreIcon('language')"/></svg><span>{{t('اللغة')}}</span><b aria-hidden="true">‹</b></button>
   <button type="button" @click="deviceOpen=true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="2" width="14" height="20" rx="3"/><path d="M10 18h4"/></svg><span>{{t('الجهاز')}}</span><b aria-hidden="true">‹</b></button>
   <button v-if="native" type="button" @click="printerOpen=true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z"/></svg><span>{{t('ربط الطابعة')}}</span><b aria-hidden="true">‹</b></button>
   <button type="button" @click="aboutOpen=true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="posMoreIcon('about')"/></svg><span>{{t('عن التطبيق')}}</span><b aria-hidden="true">‹</b></button>
   <button type="button" @click="legalOpen='terms'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6ZM15 3v5h4M9 12h7M9 16h7"/></svg><span>{{t('الشروط والأحكام')}}</span><b aria-hidden="true">‹</b></button>
   <button type="button" @click="legalOpen='privacy'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z M9 12l2 2 4-5"/></svg><span>{{t('سياسة الخصوصية')}}</span><b aria-hidden="true">‹</b></button>
   <button class="pos-more-delete" type="button" @click="deleteOpen=true;deletePreview=false"><span class="pos-more-symbol">×</span><span>{{t('حذف الحساب')}}</span><b aria-hidden="true">‹</b></button>
   <button v-if="presence" type="button" :disabled="presence.state.checking" @click="presence.state.sharing?presence.stopLocationSharing():presence.startLocationSharing()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 21s7-7 7-12a7 7 0 0 0-14 0c0 5 7 12 7 12Z"/><circle cx="12" cy="9" r="2"/></svg><span>{{t(presence.state.sharing?'إيقاف مشاركة موقعي':'مشاركة موقعي مع الإدارة')}}</span></button>
   <button class="pos-more-logout" type="button" :disabled="working" @click="logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="posMoreIcon('logout')"/></svg><span>{{t('تسجيل الخروج')}}</span></button>
  </div>
  <PwaInstall/>
  <CatalogModal v-if="languageOpen" title="اللغة" section="دنانير" content-class="pos-language-dialog" @close="languageOpen=false"><div class="pos-language-options"><button v-for="language in languages" :key="language.id" type="button" :aria-pressed="preferences.state.language===language.id" :class="{selected:preferences.state.language===language.id}" :disabled="preferences.state.busy" @click="changeLanguage(language.id)"><span>{{language.name}}</span><b>{{preferences.state.language===language.id?'✓':'○'}}</b></button></div></CatalogModal>
  <PosAbout v-if="aboutOpen" @close="aboutOpen=false"/>
  <PosDevice v-if="deviceOpen" @close="deviceOpen=false"/>
  <PosLegal v-if="legalOpen" :kind="legalOpen" @close="legalOpen=''"/>
  <PosPrinterSettings v-if="printerOpen" @close="printerOpen=false"/>
  <CatalogModal v-if="deleteOpen" title="حذف الحساب" content-class="pos-delete-dialog" @close="deleteOpen=false"><p>سيتم حذف الحساب خلال 24 ساعة.</p><footer class="formfoot"><button class="btn danger" type="button" @click="deleteOpen=false">تأكيد</button></footer></CatalogModal>
 </section>
</template>
