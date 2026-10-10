<script setup>
import {inject,onBeforeUnmount,onMounted,ref,watch} from 'vue';
import {useRoute} from 'vue-router';
import {usePortal} from '../auth/session.js';
import {createCompanyApi} from './company-api.js';
import {inquiryTokenFromHash} from './inquiry-model.js';
import {createMutationKey} from '../finance/finance-model.js';
import CompanyInquiryConversation from './CompanyInquiryConversation.vue';
import {inquiryNotificationsKey} from './inquiry-notifications.js';
const notifications = inject(inquiryNotificationsKey, null);
const {session}=usePortal(),api=createCompanyApi(session.api),route=useRoute(),entry=ref(null),reply=ref(''),loading=ref(false),busy=ref(false),error=ref(''),success=ref('');
const writer=new AbortController(),replyKey=createMutationKey();let reader,revision=0,alive=true;
async function load(){reader?.abort();reader=new AbortController();const expected=++revision;const token=inquiryTokenFromHash(route.hash);loading.value=true;error.value='';if(!token){entry.value=null;error.value='رابط متابعة الرسالة غير صالح.';loading.value=false;return;}try{const result=await api.track(token,reader.signal);if(alive&&expected===revision){entry.value=result.data;notifications?.accept(token,result.data,true);}}catch(cause){if(alive&&expected===revision&&cause.name!=='AbortError'){entry.value=null;error.value='تعذر فتح الرسالة؛ تحقق من الرابط وأعد المحاولة.';}}finally{if(alive&&expected===revision)loading.value=false;}}
async function send(){if(busy.value||!reply.value.trim()||!entry.value)return;busy.value=true;error.value='';success.value='';const payload={token:inquiryTokenFromHash(route.hash),body:reply.value.trim(),website_honeypot:''};try{const result=await api.followup({...payload,idempotency_key:replyKey.for(payload)},writer.signal);if(alive){entry.value=result.data;notifications?.accept(payload.token,result.data,true);reply.value='';replyKey.clear();success.value='تم إرسال الرد.';}}catch(cause){if(alive&&cause.name!=='AbortError')error.value=cause.message;}finally{if(alive)busy.value=false;}}
watch(()=>notifications?.state.detail,detail=>{if(detail&&notifications.state.detailToken===inquiryTokenFromHash(route.hash))entry.value=detail;});
watch(()=>route.hash,()=>{entry.value=null;reply.value='';replyKey.clear();void load();});onMounted(()=>{document.title='متابعة الرسالة · موقع الشركة';void load();});onBeforeUnmount(()=>{alive=false;revision++;reader?.abort();writer.abort();});
</script>
<template><main class="site-tracking-page"><header class="site-tracking-heading"><h1>رسائل موقع الشركة</h1><a href="/" class="btn">موقع الشركة</a></header><p class="caption">احتفظ بهذا الرابط؛ يتيح لك متابعة رسالتك والردود عليها.</p><p v-if="error" class="notice notice-error" role="alert">{{error}}</p><p v-if="success" class="notice notice-success" role="status">{{success}}</p><button v-if="error" class="btn" :disabled="loading" @click="load">إعادة المحاولة</button><CompanyInquiryConversation v-model="reply" :entry="entry" :busy="busy" :loading="loading" visitor @reply="send" @refresh="load"/></main></template>
