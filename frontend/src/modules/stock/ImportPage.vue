<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

import {auditActionLabel} from '../preferences/system-labels.js';

import {computed, onBeforeUnmount, onMounted, reactive, ref, watch} from 'vue';

import {useRoute, useRouter} from 'vue-router';

import {usePortal} from '../auth/session.js';

import {createStockApi} from './stock-api.js';

import {amountText, printableTime, requestKey, stockStatus} from './stock-model.js';

import ImportForm from './ImportForm.vue';

import OrderLines from './OrderLines.vue';

import '../catalog/catalog.css';

import './stock.css';

const {session} = usePortal(), api = createStockApi(session.api), router = useRouter(), route = useRoute();

const state = reactive({rows:[],meta:{page:1,last_page:1,total:0}, options:null, query:'',status:'',busy:false,saving:false,error:'',success:''});

const tab = ref('records'), expanded = ref(true), selected = ref(null), reviewAction = ref(''), reason = ref(''), correction = ref(null);

const isAdmin = computed(() => session.state.identity?.account.type === 'system');

const canCorrect = computed(() => selected.value?.status === 'returned' && selected.value.creator_id === session.state.identity?.user.id && session.can('import.preview') && session.can('data.pin'));

let reader, detailsReader, revision = 0, timer, reviewSignature = '', reviewKey = '';

const writer = new AbortController();

async function failure(cause) {

  if (cause.name === 'AbortError') return;

  state.error = Object.values(cause.errors || {}).flat().join(' ') || cause.message;

  if (cause.status === 401 || cause.status === 403) {await session.refresh(); if (!session.state.identity) await router.replace({name:'login'});}

}

async function load(page = 1, withOptions = false) {

  reader?.abort(); reader = new AbortController(); const expected = ++revision; state.busy = true; state.error = '';

  try {

    const [response, options] = await Promise.all([api.list('orders',{page,per_page:20,query:state.query,status:state.status,account_id:route.query.account_id || ''},reader.signal),withOptions ? api.options(reader.signal) : Promise.resolve(null)]);

    if (expected !== revision) return; state.rows = response.data; state.meta = response.meta; if (options) state.options = options.data;

  } catch (cause) {if (expected === revision) await failure(cause);} finally {if (expected === revision) state.busy = false;}

}

async function inspect(row, action = '') {

  detailsReader?.abort(); detailsReader = new AbortController(); selected.value = null; state.error = ''; state.success = ''; reviewAction.value = ''; reason.value = ''; const id = row.id;

  try {const response = await api.get('orders',id,detailsReader.signal); if (detailsReader.signal.aborted) return; selected.value = response.data; reviewAction.value = action;}

  catch (cause) {await failure(cause);}

}

function closeDetails() {detailsReader?.abort(); selected.value = null; reviewAction.value = '';}

function edit() {if (!canCorrect.value) return; correction.value = selected.value; tab.value = 'new'; closeDetails();}

async function review() {

  if (state.saving || !selected.value || (!reason.value.trim() && reviewAction.value !== 'approve')) return;

  state.saving = true; state.error = ''; const payload = {version:selected.value.version,decision:reviewAction.value,reason:reason.value.trim()};

  const signature = JSON.stringify([selected.value.id,payload]); if (signature !== reviewSignature) {reviewSignature = signature; reviewKey = requestKey();}

  try {selected.value = (await api.review(selected.value.id,{...payload,idempotency_key:reviewKey},writer.signal)).data; reviewAction.value = ''; state.success = 'تمت مراجعة الطلبية.'; await load(state.meta.page);}

  catch (cause) {await failure(cause);} finally {state.saving = false;}

}

function saved(order) {state.success = order.status === 'approved' ? 'تم اعتماد الطلبية وتسجيل المخزون.' : 'تم إرسال الطلبية.'; load(1);}

watch(() => [state.query,state.status,route.query.account_id], () => {clearTimeout(timer); timer = setTimeout(() => load(),250);});

function quickImport(){if(route.query.quick==='import' && session.can('import.preview')) tab.value='new';}

watch(()=>route.query.quick,quickImport);

onMounted(() => {quickImport();return load(1,true);});

onBeforeUnmount(() => {clearTimeout(timer); reader?.abort(); detailsReader?.abort(); writer.abort(); selected.value = null;});

</script>

<template>

  <div class="catalog-workspace stock-workspace">

    <nav class="price-mode-tabs order-page-tabs" aria-label="تبويبات الطلبيات"><button type="button" class="btn" :aria-pressed="tab === 'records'" @click="tab = 'records'">سجل الطلبيات</button><button v-if="session.can('import.preview')" type="button" class="btn" :aria-pressed="tab === 'new'" @click="tab = 'new'">طلبية جديدة</button></nav>

    <div v-if="state.error" class="notice warn" role="alert">{{state.error}}</div><div v-if="state.success" class="notice" role="status">{{state.success}}</div><p v-if="state.busy" class="read-loading help" role="status">جارٍ تحميل الطلبيات…</p>

    <ImportForm v-if="state.options" v-show="tab === 'new'" :key="correction ? `${correction.id}:${correction.version}` : 'new'" :options="state.options" :correction="correction" @saved="saved" @records="tab = 'records'" @error="failure" @reset="correction = null"/>

    <section v-show="tab === 'records'" class="card multi-orders order-history"><header class="order-history-heading"><h3>سجل الطلبيات متعددة الملفات</h3><span class="badge">{{state.meta.total}} طلبية</span><button type="button" class="btn" :aria-expanded="expanded" @click="expanded = !expanded">{{expanded ? 'إغلاق الجدول' : 'عرض الجدول'}}</button></header>

      <template v-if="expanded"><FilterBar class="toolbar"><input v-model="state.query" placeholder="بحث بالطلبية أو الوكيل أو الفئة" aria-label="بحث الطلبيات"><select v-model="state.status" aria-label="حالة الطلبية"><option value="">جميع الحالات</option><option v-for="value in ['pending','approved','returned','rejected']" :key="value" :value="value">{{stockStatus(value)}}</option></select><button type="button" class="btn" :disabled="state.busy" @click="load(state.meta.page,true)">تحديث</button></FilterBar>

        <TablePanel class="tablewrap"><table><thead><tr><th>الطلبية</th><th>تاريخ الإرسال</th><th>الوكيل</th><th>الملفات</th><th>صالحة / مرفوضة</th><th>القيمة</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="row in state.rows" :key="row.id"><td>{{row.id}}</td><td>{{printableTime(row.created_at)}}</td><td>{{row.account_name}}</td><td>{{row.summary.lines.length}}</td><td>{{row.quantity}} / {{row.rejected}}</td><td>{{amountText(row.summary.amounts)}}</td><td><span class="badge">{{stockStatus(row.status)}}</span></td><td><div class="actions"><button type="button" class="btn small" @click="selected?.id === row.id ? closeDetails() : inspect(row)">معاينة</button><template v-if="isAdmin && session.can('import.approve') && row.status === 'pending'"><button type="button" class="btn small primary" @click="inspect(row,'approve')">اعتماد</button><button type="button" class="btn small" @click="inspect(row,'return')">إعادة للتصحيح</button><button type="button" class="btn small danger" @click="inspect(row,'reject')">رفض</button></template><button v-if="row.status === 'returned' && row.creator_id === session.state.identity?.user.id && session.can('import.preview') && session.can('data.pin')" type="button" class="btn small" @click="inspect(row).then(edit)">تصحيح</button><button v-if="row.status === 'approved' && session.can('inventory.view')" type="button" class="btn small" @click="router.push({name:'inventory',query:{account_id:row.account_id}})">المخزون</button></div></td></tr></tbody></table></TablePanel>

        <div v-if="!state.rows.length && !state.busy" class="empty">لا توجد طلبيات</div><div class="formfoot"><button type="button" class="btn small" :disabled="state.busy || state.meta.page <= 1" @click="load(state.meta.page-1)">السابق</button><span>{{state.meta.page}} / {{state.meta.last_page}}</span><button type="button" class="btn small" :disabled="state.busy || state.meta.page >= state.meta.last_page" @click="load(state.meta.page+1)">التالي</button></div>

        <section v-if="selected" class="order-file"><header><h3>{{selected.id}}</h3><button type="button" class="btn small" :disabled="state.saving" @click="closeDetails">إغلاق المعاينة</button></header><div v-if="selected.reason" class="notice warn">{{selected.reason}}</div><div class="order-metrics"><span>{{selected.provider_name}} · {{selected.source_name}}</span><span>{{selected.city}}</span></div><OrderLines :lines="selected.summary.lines" :draft-lines="selected.draft?.lines" :can-export="session.can('data.pin') && session.can('import.preview')"/><details><summary>سجل المتابعة</summary><div v-for="event in selected.events || []" :key="event.id" class="order-metrics"><span>{{event.label || auditActionLabel(event.action)}}</span><span>{{event.user_name}}</span><span>{{printableTime(event.time)}}</span><span>{{event.reason}}</span></div><p v-if="selected.events_truncated" class="help">يعرض آخر 1000 إجراء.</p></details>

          <div v-if="reviewAction && selected.status === 'pending'" class="formfoot"><input v-if="reviewAction !== 'approve'" v-model="reason" :disabled="state.saving" placeholder="سبب الإعادة أو الرفض" aria-label="سبب مراجعة الطلبية"><button type="button" class="btn primary" :disabled="state.saving || (reviewAction !== 'approve' && !reason.trim())" @click="review">{{reviewAction === 'approve' ? 'تأكيد الاعتماد' : reviewAction === 'return' ? 'تأكيد الإعادة' : 'تأكيد الرفض'}}</button><button type="button" class="btn" :disabled="state.saving" @click="reviewAction = ''">إلغاء</button></div><button v-if="canCorrect" type="button" class="btn primary" @click="edit">تصحيح وإعادة إرسال</button>

        </section>

      </template>

    </section>

  </div>

</template>

