<script setup>
import {onBeforeUnmount,onMounted,ref} from 'vue';
import {usePortal} from '../modules/auth/session.js';
import {usePreferencesTranslator} from '../modules/preferences/preferences-state.js';
import {webAppVersion} from '../shared/client-version.js';
import {hasNativePrinter,getNativePrinterCapabilities} from '../shared/native-print.js';
import CatalogModal from '../modules/catalog/CatalogModal.vue';
import './pos-about.css';
defineEmits(['close']);
const {session}=usePortal(),t=usePreferencesTranslator(),config=ref(null),error=ref(''),tab=ref('company'),bridgeVersion=ref('');
const reader=new AbortController();let alive=true;
onMounted(async()=>{
 if(hasNativePrinter())getNativePrinterCapabilities().then(value=>{if(alive)bridgeVersion.value=value.appVersion;}).catch(()=>{});
 try{const result=await session.api.request('/company/app-config',{signal:reader.signal});if(alive)config.value=result.data.config;}
 catch(e){if(alive&&e.name!=='AbortError')error.value=e.message;}
});
onBeforeUnmount(()=>{alive=false;reader.abort();});
</script>
<template>
 <CatalogModal :title="t('عن التطبيق')" section="دنانير" content-class="pos-info-dialog pos-about-dialog" @close="$emit('close')">
  <div class="pos-info-body">
   <div class="pos-about-tabs" role="group" :aria-label="t('معلومات التطبيق')">
    <button type="button" :aria-pressed="tab==='company'" :class="{active:tab==='company'}" @click="tab='company'">دنانير</button>
    <button type="button" :aria-pressed="tab==='developer'" :class="{active:tab==='developer'}" @click="tab='developer'">{{t('المطور')}}</button>
   </div>
   <p v-if="error" class="notice warn" role="alert">{{error}}</p>
   <template v-if="tab==='company'">
    <header class="pos-about-hero"><img :src="'/icons/dananeer-192-v3.png'" alt="" width="76" height="76"><h2>{{config?.name||'دنانير'}}</h2><p>{{t('البطاقات والخدمات الإلكترونية')}}</p></header>
    <section v-if="config?.about" class="pos-info-card"><h3>{{t('عن دنانير')}}</h3><p class="pos-info-copy" dir="auto">{{config.about}}</p></section>
    <p v-else-if="!config&&!error" class="pos-info-empty" role="status">{{t('جارٍ التحميل…')}}</p>
    <p v-else-if="!error" class="pos-info-empty">{{t('لم تضف الإدارة المحتوى بعد.')}}</p>
   </template>
   <template v-else>
    <header class="pos-about-hero"><div class="pos-developer-mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10 9-7 7 7 7m12-14 7 7-7 7M19 5l-6 22"/></svg></div><h2>عراق تكنو</h2><p>{{t('لتكنولوجيا المعلومات')}}</p><span class="pos-founder-badge">{{t('منذ 2015')}}</span></header>
    <section class="pos-info-card"><h3>{{t('من نحن')}}</h3><div class="pos-info-copy"><p>شركة عراق تكنو لتكنولوجيا المعلومات شركة عراقية متخصصة في بناء أنظمة قواعد البيانات، وتقديم الحلول البرمجية الجاهزة، وبناء الأنظمة حسب الطلب.</p><p>أسست الشركة من قبل عدد من المبرمجين ذوي الخبرة والاختصاص والحاصلين على تدريب في مجالات متعددة من تكنولوجيا المعلومات مثل تصميم وإدارة قواعد البيانات، وإدارة الخوادم، وتطوير برامجيات الويب باستخدام تقنيات حديثة، وبناء تطبيقات الهواتف.</p><p>يتركز عمل الشركة على تطوير وتحسين أنظمة معالجة المعلومات ومساعدة الزبائن في تبني التكنولوجيا الحديثة، وهدفها توفير أنظمة مرنة وسهلة الاستخدام.</p></div></section>
    <section class="pos-about-contact"><h3>{{t('تواصل معنا')}}</h3><a href="mailto:info@iraqtechno.com"><span class="pos-contact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg></span><span><small>{{t('البريد الإلكتروني')}}</small><b dir="ltr">info@iraqtechno.com</b></span><span class="pos-contact-arrow" aria-hidden="true">↗</span></a><a href="tel:+9647711132368"><span class="pos-contact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m7 3 3 5-3 3a15 15 0 0 0 6 6l3-3 5 3c0 3-2 4-4 4C10 21 3 14 3 7c0-2 1-4 4-4Z"/></svg></span><span><small>{{t('اتصال مباشر')}}</small><b dir="ltr">+9647711132368</b></span><span class="pos-contact-arrow" aria-hidden="true">↗</span></a></section>
   </template>
   <footer class="pos-about-version"><span>{{t('إصدار التطبيق')}}</span><b dir="ltr">{{bridgeVersion||webAppVersion}}</b></footer>
  </div>
 </CatalogModal>
</template>
