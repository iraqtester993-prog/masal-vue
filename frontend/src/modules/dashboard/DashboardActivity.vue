<script setup>
import AppSelect from '../../shared/components/AppSelect.vue';

import { useRouter,RouterLink } from 'vue-router';
import {computed,ref,watch,onMounted,onBeforeUnmount} from 'vue';
import { usePortal } from '../auth/session.js';
import { usePreferences, usePreferencesTranslator } from '../preferences/preferences-state.js';
import { systemLabel } from '../preferences/system-labels.js';
import {navIconPath} from '../../layouts/navigation.js';
import { formatNumber } from '../reports/report-model.js';
import { activityTone as tone, activityDate, activityTitle, activitySource } from './activity-model.js';
import {createReportApi} from '../reports/report-api.js';
import {errorText} from '../reports/report-model.js';
import {dashboardActivityPath,dashboardReadScope} from './dashboard-cache.js';
import {routeAllowed} from '../../router/access.js';
import './dashboard-activity.css';

const props=defineProps({ operations: { type: Array, default: () => [] }, busy: Boolean, compact:Boolean, complete:Boolean });
const router = useRouter(), { session } = usePortal(), t = usePreferencesTranslator(), preferences = usePreferences();
const isPos=computed(()=>session.state.identity?.account.type==='pos');
const limit=ref(props.complete?'all':props.compact?'10':'20'),cachedActivity=session.api.peekRequest?.(dashboardActivityPath(limit.value)),rows=ref(Array.isArray(cachedActivity?.data)?cachedActivity.data:null),loading=ref(false),error=ref(''),api=createReportApi(session.api);
const pages=ref(['']),page=ref(0),nextCursor=ref(cachedActivity?.meta?.next_cursor||null);
const shown=computed(()=>rows.value ?? props.operations.slice(0,Number(limit.value)||props.operations.length));
let reader,revision=0,alive=true;
async function load(){reader?.abort();reader=new AbortController();const expected=++revision,path=dashboardActivityPath(limit.value,pages.value[page.value]),cached=session.api.peekRequest?.(path);if(Array.isArray(cached?.data)){rows.value=cached.data;nextCursor.value=cached.meta?.next_cursor||null;}loading.value=!session.api.hasFreshRequest?.(path);error.value='';try{const result=await api.activity(limit.value,reader.signal,pages.value[page.value]);if(alive&&expected===revision){if(!Array.isArray(result.data))throw Error('تعذر تحميل العمليات.');rows.value=result.data;nextCursor.value=result.meta?.next_cursor||null;}}catch(cause){if(alive&&expected===revision&&cause.name!=='AbortError')error.value=errorText(cause);}finally{if(alive&&expected===revision)loading.value=false;}}
watch(limit,()=>{pages.value=[''];page.value=0;load();});
watch(()=>dashboardReadScope(session.state.identity),()=>{rows.value=null;pages.value=[''];page.value=0;nextCursor.value=null;if(!session.state.identity){revision++;reader?.abort();loading.value=false;return;}load();});
function moveNext(){if(nextCursor.value){pages.value[page.value+1]=nextCursor.value;page.value++;load();}}
function movePrevious(){if(page.value>0){page.value--;load();}}
onMounted(load);onBeforeUnmount(()=>{alive=false;revision++;reader?.abort();});
  function brief(operation){const fields=(operation.summary_fields||[]).map(field=>`${t(field.label)}: ${field.label==='الخدمة'?t(systemLabel(field.value,'movement')):field.value}`);return [operation.product_name,...fields,`${t('رقم العملية')}: ${String(operation.key||'').split(':').pop()}`].filter(Boolean).join(' · ');}
function date(value) {
  return activityDate(value, preferences?.state.language || 'ar');
}
function canOpen(operation) {
  const route = router.getRoutes().find(row => row.name === operation.destination), identity = session.state.identity;
  return !!route&&!!identity&&routeAllowed(route,session);
}
function open(operation) { if (canOpen(operation)) router.push({ name: operation.destination, query: operation.parameters }); }
function operationIcon(operation){return navIconPath(operation.kind==='topup'?'pos':operation.destination==='wallets'?'wallets':'sales');}
</script>

<template>
  <section class="card dashboard-activity-log dashboard-activity-grid">
    <header class="activity-card-heading"><h2>{{complete?'العمليات المالية':isPos?'آخر العمليات المالية':'آخر العمليات'}}</h2><RouterLink v-if="compact&&isPos" class="pos-text-action" :to="{name:'posActivity'}">{{t('عرض الكل')}}</RouterLink><div v-else-if="!compact&&!complete" class="activity-display-tools"><label>{{t('عرض العمليات')}}<AppSelect v-model="limit" :aria-label="t('عدد العمليات المعروضة')"><option v-for="value in ['10','20','50','100','all']" :key="value" :value="value">{{value==='all'?t('الكل'):value}}</option></AppSelect></label><span class="badge neutral">{{ formatNumber(shown.length) }} {{t('عمليات')}}</span></div></header>
    <p v-if="error" class="notice warn" role="alert">{{error}} <button type="button" class="btn small" @click="load">إعادة المحاولة</button></p>
    <p v-if="loading || busy" class="activity-updating" role="status">جارٍ تحديث العمليات…</p>
    <div v-if="shown.length&&isPos" class="pos-activity-list"><article v-for="operation in shown" :key="operation.key" class="pos-activity-row" :class="{'is-compact':compact}"><span v-if="compact" class="pos-operation-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="operationIcon(operation)"/></svg></span><div><strong>{{t(activityTitle(operation))}}</strong><small>{{t(activitySource(operation))}}<template v-if="operation.product_name"> · {{operation.product_name}}</template></small><small>{{date(operation.occurred_at)}}</small><small v-if="!compact">{{brief(operation)}}</small></div><div><b dir="ltr"><span v-if="operation.direction">{{operation.direction==='credit'?'+':'−'}} </span>{{operation.amount==null?'—':formatNumber(operation.amount)}} <small>د.ع</small></b><small v-if="operation.amount_label" class="activity-direction">{{t(operation.amount_label)}}</small><small v-else-if="operation.direction" class="activity-direction">{{operation.direction==='credit'?'وارد للمحفظة':'صادر من المحفظة'}}</small><span class="activity-status" :data-tone="tone(operation.status)">{{t(systemLabel(operation.status,'status'))}}</span></div><button v-if="canOpen(operation)" type="button" :class="compact?'pos-row-open':'btn small'" :aria-label="t('عرض تفاصيل العملية')" @click="open(operation)"><svg v-if="compact" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 7-5 5 5 5"/></svg><template v-else>{{t('عرض')}}</template></button></article></div>
    <div v-else-if="shown.length" class="activity-table-scroll" tabindex="0" :aria-label="t('آخر العمليات')">
      <table class="activity-table">
        <thead><tr><th scope="col">التسلسل</th><th scope="col">التاريخ والوقت</th><th scope="col">العملية</th><th scope="col">الحساب</th><th scope="col">المستخدم</th><th scope="col">المبلغ · د.ع</th><th scope="col">الحالة</th><th scope="col">التفاصيل</th></tr></thead>
        <tbody><tr v-for="(operation,index) in shown" :key="operation.key">
          <td class="activity-number">{{ index + 1 }}</td><td><time :datetime="operation.occurred_at">{{ date(operation.occurred_at) }}</time></td>
          <td class="activity-description"><strong>{{t(activityTitle(operation))}}</strong><small>{{t(activitySource(operation))}}<template v-if="operation.product_name"> · {{operation.product_name}}</template></small></td>
          <td dir="auto">{{ operation.account_name || '—' }}</td><td dir="auto">{{ operation.actor_name || '—' }}</td>
          <td class="activity-amount">{{ operation.amount == null ? '—' : formatNumber(operation.amount) }}</td><td><span class="activity-status" :data-tone="tone(operation.status)">{{ t(systemLabel(operation.status,'status')) }}</span></td>
          <td><div class="activity-brief"><span :title="brief(operation)">{{brief(operation)}}</span><button v-if="canOpen(operation)" type="button" class="btn small" @click="open(operation)">عرض</button></div></td>
        </tr></tbody>
      </table>
    </div>
    <div v-else-if="!busy && !loading && !error" class="activity-empty"><p>لا توجد عمليات ضمن نطاقك</p></div>
    <div v-if="limit==='all'" class="table-pagination-footer"><button type="button" class="btn small" :disabled="loading||page===0" @click="movePrevious">السابق</button><span>الصفحة {{page+1}} · حتى 100 عملية في الصفحة</span><button type="button" class="btn small" :disabled="loading||!nextCursor" @click="moveNext">التالي</button></div>
  </section>
</template>
