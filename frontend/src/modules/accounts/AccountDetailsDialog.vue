<script setup>

import { computed,defineAsyncComponent,onMounted,onBeforeUnmount,ref } from 'vue';

import { useAccountAccess } from './use-account-access.js';

import { accountTypeLabel } from '../../app/portal-config.js';

import AccountModal from './AccountModal.vue';

import {createReferenceApi} from '../reference/reference-api.js';

const AccountAttachments=defineAsyncComponent(()=>import('./AccountAttachments.vue'));

const props=defineProps({account:Object});const emit=defineEmits(['close','changed','login','create']);

const {session,handleFailure}=useAccountAccess();const data=ref(null),error=ref('');const controller=new AbortController();

onMounted(async()=>{try{data.value=(await session.api.account(props.account.id,controller.signal)).data;}catch(failure){if(failure.name!=='AbortError')error.value=await handleFailure(failure);}});

onBeforeUnmount(()=>controller.abort());

const labels=computed(()=>({name:accountIsPos.value?'الاسم التجاري':'اسم الوكيل',city:'المحافظة',phone:'رقم الهاتف',...(accountIsPos.value?{owner_name:'اسم صاحب المكتب',address:'المدينة والعنوان',serial:'سيريال الجهاز',device_lock_enabled:'قيد الرقم التسلسلي',device_model:'الجهاز',app_version:'إصدار التطبيق'}:{support:'معلومات الدعم',color:'لون الوكيل'}),notes:'الملاحظات'}));

const accountIsPos=computed(()=>data.value?.type==='pos');

const mediaBusy=ref(false);

const reference=ref(null);

onMounted(async()=>{if(props.account.type!=='pos')return;try{reference.value=(await createReferenceApi(session.api).getProfile(props.account.id,controller.signal)).data;}catch(failure){if(failure.name!=='AbortError')error.value=await handleFailure(failure);}});

</script>

<template>

  <AccountModal :title="account.type==='pos'?'تفاصيل نقطة البيع':'تفاصيل الوكيل'" :busy="mediaBusy" @close="emit('close')">

    <p v-if="error" class="notice notice-error" role="alert">{{error}}</p>

    <p v-else-if="!data" class="read-loading help" role="status">جارٍ تحميل التفاصيل…</p>

    <template v-else>

      <dl class="details-grid"><div v-for="(label,key) in labels" :key="key"><dt>{{label}}</dt><dd>{{key==='device_lock_enabled'?(data[key]===false?'مطفأ · أي جهاز':'مفعّل · جهاز محدد'):(data[key] || '—')}}</dd></div><div><dt>المستوى</dt><dd>{{accountTypeLabel(data.type)}}</dd></div><div><dt>الوكيل الأعلى</dt><dd>{{data.parent?.name || '—'}}</dd></div><div><dt>الحالة</dt><dd>{{data.status==='active'?'مفعل':'موقوف'}}</dd></div><div><dt>تاريخ إنشاء الحساب</dt><dd>{{data.created_at?new Date(data.created_at).toLocaleString('ar-IQ'):'—'}}</dd></div><div v-if="data.owner_user"><dt>بريد أو اسم مستخدم تسجيل الدخول</dt><dd dir="auto">{{data.owner_user.login || data.owner_user.email}}</dd></div></dl>

      <AccountAttachments v-if="session.can('account.attachments.view') && data.type!=='system'" :account="data" @version="data.version=$event" @changed="emit('changed',$event)" @busy="mediaBusy=$event"/>

      <dl v-if="reference" class="details-grid"><div><dt>نوع نقطة البيع</dt><dd>{{reference.pos_types.find(row=>row.id===reference.pos_type_id)?.name || '—'}}</dd></div><div><dt>المندوبون</dt><dd>{{reference.selected_representatives.map(row=>row.name || 'مندوب بدون اسم').join('، ') || '—'}}</dd></div></dl>

    </template>

    <footer class="formfoot"><button v-if="session.can('account.login') && session.state.identity?.membership?.kind==='owner' && session.state.identity?.account.type==='system' && account.id!==session.state.identity.account.id && account.type!=='system'" type="button" class="btn" :disabled="mediaBusy" @click="emit('login',account)">حساب الدخول</button><button v-if="session.can('account.create') && session.state.identity?.membership?.kind==='owner' && ['main_agent','sub_agent','sub_branch'].includes(account.type)" type="button" class="btn" :disabled="mediaBusy" @click="emit('create',account)">إنشاء حساب تابع</button><button type="button" class="btn primary" :disabled="mediaBusy" @click="emit('close')">إغلاق</button></footer>

  </AccountModal>

</template>

