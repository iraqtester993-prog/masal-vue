<script setup>
import {ref} from 'vue';
import {usePreferencesTranslator} from '../preferences/preferences-state.js';
defineProps({details:{type:Object,required:true}});
defineEmits(['close']);
const t=usePreferencesTranslator(),copied=ref(false),error=ref('');
async function copy(details){
  error.value='';
  try {await navigator.clipboard.writeText(`${t('رابط الدخول')}: ${details.url}\n${t('اسم المستخدم')}: ${details.login}\n${t('كلمة المرور')}: ${details.password}`);copied.value=true;}
  catch {error.value=t('تعذر النسخ؛ يمكنك تحديد بيانات الدخول ونسخها يدويًا.');}
}
</script>
<template><section class="created-login-details" aria-live="polite"><h3>تم إنشاء الحساب بنجاح</h3><p class="muted">تظهر بيانات الدخول مرة واحدة. انسخها قبل إغلاق النافذة.</p><dl class="details-grid"><div><dt>رابط الدخول</dt><dd><a :href="details.url" target="_blank" rel="noopener noreferrer">{{details.url}}</a></dd></div><div><dt>اسم المستخدم</dt><dd dir="ltr">{{details.login}}</dd></div><div><dt>كلمة المرور</dt><dd dir="ltr">{{details.password}}</dd></div></dl><p v-if="error" class="notice notice-error">{{error}}</p><footer class="formfoot"><button type="button" class="btn primary" @click="copy(details)">{{t(copied?'تم النسخ':'نسخ بيانات الدخول')}}</button><button type="button" class="btn" @click="$emit('close')">إغلاق</button></footer></section></template>
<style scoped>.created-login-details dd{user-select:text;overflow-wrap:anywhere}.created-login-details .details-grid{grid-template-columns:1fr}.created-login-details h3{color:var(--ink)}</style>
