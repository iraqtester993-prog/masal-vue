<script setup>

import { useRouter } from 'vue-router';
import {computed,ref,watch,onMounted,onBeforeUnmount} from 'vue';
import { usePortal } from '../auth/session.js';
import { usePreferencesTranslator } from '../preferences/preferences-state.js';
import { systemLabel } from '../preferences/system-labels.js';
import { formatNumber } from '../reports/report-model.js';
import { activityTone as tone } from './activity-model.js';
import {createReportApi} from '../reports/report-api.js';
import {errorText} from '../reports/report-model.js';
import './dashboard-activity.css';

const props=defineProps({ operations: { type: Array, default: () => [] }, busy: Boolean });
const router = useRouter(), { session } = usePortal(), t = usePreferencesTranslator();
const limit=ref('20'),rows=ref(null),loading=ref(false),error=ref(''),api=createReportApi(session.api);
const shown=computed(()=>rows.value ?? props.operations.slice(0,Number(limit.value)||props.operations.length));
let reader,revision=0,alive=true;
async function load(){reader?.abort();reader=new AbortController();const expected=++revision;loading.value=true;error.value='';try{const result=await api.activity(limit.value,reader.signal);if(alive&&expected===revision){if(!Array.isArray(result.data))throw Error('تعذر تحميل العمليات.');rows.value=result.data;}}catch(cause){if(alive&&expected===revision&&cause.name!=='AbortError')error.value=errorText(cause);}finally{if(alive&&expected===revision)loading.value=false;}}
watch(limit,load);onMounted(load);onBeforeUnmount(()=>{alive=false;revision++;reader?.abort();});
function brief(operation){const fields=(operation.summary_fields||[]).map(field=>`${t(field.label)}: ${field.value}`);return [operation.product_name,...fields,`${t('رقم العملية')}: ${String(operation.key||'').split(':').pop()}`].filter(Boolean).join(' · ');}
function date(value) {
  const raw = String(value || '').replace(' ', 'T');
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(raw) ? raw : raw + 'Z';
  return new Date(normalized).toLocaleString(undefined, { timeZone: 'Asia/Baghdad', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}
function canOpen(operation) {
  const route = router.getRoutes().find(row => row.name === operation.destination), identity = session.state.identity;
  return route && (!route.meta.permission || session.can(route.meta.permission)) && (!route.meta.accountTypes || route.meta.accountTypes.includes(identity?.account.type)) && (!route.meta.membershipKinds || route.meta.membershipKinds.includes(identity?.membership.kind));
}
function open(operation) { if (canOpen(operation)) router.push({ name: operation.destination, query: operation.parameters }); }
</script>

<template>
  <section class="card dashboard-activity-log dashboard-activity-grid">
    <header class="activity-card-heading"><h2>آخر العمليات</h2><div class="activity-display-tools"><label>{{t('عرض العمليات')}}<select v-model="limit" :aria-label="t('عدد العمليات المعروضة')"><option v-for="value in ['10','20','50','100','all']" :key="value" :value="value">{{value==='all'?t('الكل'):value}}</option></select></label><span class="badge neutral">{{ formatNumber(shown.length) }} {{t('عمليات')}}</span></div></header>
    <p v-if="error" class="notice warn" role="alert">{{error}} <button type="button" class="btn small" @click="load">إعادة المحاولة</button></p>
    <p v-if="loading || busy" class="activity-updating" role="status">جارٍ تحديث العمليات…</p>
    <div v-if="shown.length" class="activity-table-scroll" tabindex="0" :aria-label="t('آخر العمليات')">
      <table class="activity-table">
        <thead><tr><th scope="col">التسلسل</th><th scope="col">التاريخ والوقت</th><th scope="col">العملية</th><th scope="col">الحساب</th><th scope="col">المستخدم</th><th scope="col">المبلغ · د.ع</th><th scope="col">الحالة</th><th scope="col">التفاصيل</th></tr></thead>
        <tbody><tr v-for="(operation,index) in shown" :key="operation.key">
          <td class="activity-number">{{ index + 1 }}</td><td><time :datetime="operation.occurred_at">{{ date(operation.occurred_at) }}</time></td>
          <td class="activity-description"><strong>{{ t(operation.action ? systemLabel(operation.action,'action') : operation.kind === 'topup' ? 'تعبئة مباشرة Topup' : operation.kind === 'rabiaa' ? 'بطاقات الرابعة' : 'بطاقات الطباعة') }}</strong><small v-if="operation.product_name" dir="auto">{{ operation.product_name }}</small></td>
          <td dir="auto">{{ operation.account_name || '—' }}</td><td dir="auto">{{ operation.actor_name || '—' }}</td>
          <td class="activity-amount">{{ operation.amount == null ? '—' : formatNumber(operation.amount) }}</td><td><span class="activity-status" :data-tone="tone(operation.status)">{{ t(systemLabel(operation.status,'status')) }}</span></td>
          <td><div class="activity-brief"><span :title="brief(operation)">{{brief(operation)}}</span><button v-if="canOpen(operation)" type="button" class="btn small" @click="open(operation)">عرض</button></div></td>
        </tr></tbody>
      </table>
    </div>
    <div v-else-if="!busy && !loading && !error" class="activity-empty"><p>لا توجد عمليات ضمن نطاقك</p></div>
  </section>
</template>
