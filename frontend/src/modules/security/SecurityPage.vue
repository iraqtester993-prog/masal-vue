<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import TablePanel from '../../shared/components/TablePanel.vue';

import {computed,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createOperationsApi} from './operations-api.js';
import {operationFields,scopes,scopeLabel,actionLabel,previewCount} from './operations-model.js';
import {accountTypes,createMutationKey,errorText,time} from '../finance/finance-model.js';
import '../finance/finance-parity.css';
import './security.css';
import FundingRequestSettings from './FundingRequestSettings.vue';
const {session} = usePortal(), api = createOperationsApi(session.api);
const state = reactive({accounts:[],active_stops:[],restricted:[],restricted_total:0,legacy_global:null,can_manage:false,meta:{current_page:1,last_page:1},error:'',success:'',loading:false,saving:false});
const scope = ref('all'),selected = ref([]),query = ref(''),filter = ref(''),actions = ref([]),reason = ref('');
const allowed = computed(() => state.can_manage && session.can('security.policies'));
const choices = computed(() => state.accounts.filter(row => row.name.toLocaleLowerCase('ar').includes(query.value.toLocaleLowerCase('ar'))));
const preview = computed(() => previewCount(state.accounts,scope.value,selected.value));
const legacy = computed(() => state.legacy_global && Object.values(state.legacy_global.stops).some(Boolean));
const write = new AbortController(),key = createMutationKey(); let reader,revision=0,alive=true,timer;
async function failure(cause) {if(cause.name === 'AbortError'||!alive) return; state.error = errorText(cause); if([401,403].includes(cause.status)) await session.refresh();}
async function load(page=1) {
  reader?.abort(); reader = new AbortController(); const expected = ++revision; state.loading=true; state.error='';
  try {const result = await api.security({page,per_page:10,query:filter.value},reader.signal); if(alive&&expected===revision) Object.assign(state,result.data,{meta:result.meta});}
  catch(cause) {await failure(cause);} finally {if(alive&&expected===revision) state.loading=false;}
}
async function change(run,clear) {
  if(state.saving||!allowed.value) return; state.saving=true; state.error=''; state.success='';
  try {await run(); if(!alive)return; key.clear(); clear?.(); state.success='تم حفظ ضوابط التوقيف.'; await load(state.meta.current_page);}
  catch(cause) {await failure(cause);} finally {if(alive) state.saving=false;}
}
function apply() {
  const body = {scope:scope.value,actions:[...actions.value],reason:reason.value.trim(),...(scope.value==='custom'?{account_ids:[...selected.value]}:{})};
  return change(() => api.createStop({...body,idempotency_key:key.for({action:'create',...body})},write.signal),() => {reason.value='';actions.value=[];selected.value=[];});
}
function resume(row) {const body={version:row.version};return change(() => api.resume(row.id,{...body,idempotency_key:key.for({action:'resume',id:row.id,...body})},write.signal));}
function clearDirect(row) {const body={version:row.direct_version,stops:{login:false,sales:false,printing:false,import:false}};return change(() => api.direct(row.id,{...body,idempotency_key:key.for({action:'direct',id:row.id,...body})},write.signal));}
function restoreGlobal() {const body={version:state.legacy_global.version};return change(() => api.restoreGlobal({...body,idempotency_key:key.for({action:'global',...body})},write.signal));}
watch(filter,() => {clearTimeout(timer);timer=setTimeout(() => load(),250);});
onMounted(() => load()); onBeforeUnmount(() => {alive=false;revision++;reader?.abort();write.abort();clearTimeout(timer);});
</script>

<template>
  <section class="finance-workspace security-center" aria-label="التحكم بالتوقيف">
    <FundingRequestSettings v-if="session.state.identity?.account.type==='system' && session.can('security.fundingRequests')" />
    <p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><p v-if="state.success" class="notice" role="status">{{state.success}}</p>
    <div class="card"><div class="cardhead"><h3>التحكم بالتوقيف</h3><span class="badge">{{state.restricted_total}} حساب مقيّد</span></div>
      <form v-if="allowed" @submit.prevent="apply"><fieldset :disabled="state.saving||state.loading">
        <label class="security-scope-select">نطاق التوقيف<select v-model="scope" aria-label="نطاق التوقيف"><option v-for="option in scopes" :key="option.id" :value="option.id">{{uiLabel(option.label)}}</option></select></label>
        <p class="caption">اختيار مستوى معيّن يخص هذا المستوى وحده، ضمن نطاقك المصرح به.</p>
        <details v-if="scope==='custom'" class="security-account-picker"><summary>{{selected.length?`الحسابات المحددة: ${selected.length}`:'اختر الحسابات'}}<span aria-hidden="true">⌄</span></summary><div class="security-account-menu"><input v-model="query" placeholder="بحث باسم الحساب" aria-label="بحث الحسابات"><div class="security-account-options"><label v-for="account in choices" :key="account.id"><input type="checkbox" :value="account.id" v-model="selected">{{account.name}}<small>{{accountTypes[account.type]}}</small></label><p v-if="!choices.length" class="caption">لا توجد نتائج</p></div></div></details>
        <h4>خيارات التوقيف</h4><div class="security-action-grid"><label v-for="field in operationFields" :key="field.key" :class="{selected:actions.includes(field.key)}"><input type="checkbox" v-model="actions" :value="field.key"><span>{{uiLabel(field.label)}}</span></label></div>
        <label class="security-reason">سبب التوقيف<input v-model="reason" maxlength="300" required></label><div class="security-apply"><span class="badge">{{preview}} حساب</span><button class="btn danger" :disabled="!actions.length||!reason.trim()||(scope==='custom'&&!selected.length)">تطبيق التوقيف</button></div>
      </fieldset></form><span v-else class="badge">عرض فقط</span>
    </div>
    <div v-if="legacy" class="card security-legacy"><b>يوجد توقيف عام سابق</b><button v-if="allowed" class="btn" :disabled="state.saving" @click="restoreGlobal">إلغاء التوقيف العام السابق</button></div>
    <div class="card"><div class="cardhead"><h3>قرارات التوقيف الفعّالة</h3><span class="badge">{{state.active_stops.length}}</span></div><p v-if="!state.active_stops.length" class="empty">{{state.loading?'جارٍ التحميل…':'لا توجد قرارات توقيف جديدة'}}</p>
      <article v-for="row in state.active_stops" :key="row.id" class="security-rule"><div><b>{{scopeLabel(row.scope)}} <span class="badge warn">{{row.count}} حساب</span></b><p>{{row.reason}}</p><div class="actions"><span v-for="action in row.actions" :key="action" class="badge">{{actionLabel(action)}}</span></div><small>{{time(row.time)}}</small></div><button v-if="allowed" class="btn" :disabled="state.saving" @click="resume(row)">إلغاء هذا التوقيف</button></article>
    </div>
    <div class="card"><div class="cardhead"><h3>الحسابات المعطّلة أو المقيّدة</h3><input v-model="filter" placeholder="بحث الحسابات المعطّلة" aria-label="بحث الحسابات المعطّلة"></div><TablePanel class="tablewrap"><table><thead><tr><th>الحساب</th><th>النوع</th><th>الإيقاف الفعلي</th><th>الإجراء</th></tr></thead><tbody><tr v-for="row in state.restricted" :key="row.id"><td>{{row.name}}</td><td>{{accountTypes[row.type]}}</td><td><div class="actions"><span v-for="stop in row.stops" :key="stop" class="badge warn">{{actionLabel(stop)}}</span></div></td><td><button v-if="allowed&&Object.values(row.direct_stops).some(Boolean)" class="btn small" :disabled="state.saving" @click="clearDirect(row)">إلغاء التوقيف المباشر السابق</button><span v-else>—</span></td></tr></tbody></table></TablePanel><p v-if="!state.restricted.length" class="empty">{{state.loading?'جارٍ التحميل…':'لا توجد حسابات مقيّدة'}}</p><div class="actions"><button class="btn small" :disabled="state.loading||state.meta.current_page<=1" @click="load(state.meta.current_page-1)">السابق</button><span>{{state.meta.current_page}} / {{state.meta.last_page}}</span><button class="btn small" :disabled="state.loading||state.meta.current_page>=state.meta.last_page" @click="load(state.meta.current_page+1)">التالي</button></div></div>
  </section>
</template>
