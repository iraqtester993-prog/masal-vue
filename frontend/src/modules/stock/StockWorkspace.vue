<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import TablePanel from '../../shared/components/TablePanel.vue';


import {computed, onBeforeUnmount, onMounted, reactive, ref, watch} from 'vue';

import {useRoute, useRouter} from 'vue-router';

import {usePortal} from '../auth/session.js';

import CatalogModal from '../catalog/CatalogModal.vue';

import InventoryManager from './InventoryManager.vue';

import {createStockApi} from './stock-api.js';

import {cardPayload, downloadText, printableTime, requestKey, stockStatus} from './stock-model.js';

import {parseSheets} from './order-parser.js';

import '../catalog/catalog.css';

import './stock.css';

const {session} = usePortal(), api = createStockApi(session.api), route = useRoute(), router = useRouter();

const tab = computed(() => route.name === 'claims' ? 'claims' : route.name === 'exports' ? route.query.tab === 'history' ? 'history' : route.query.tab === 'withdraw' || !session.can('inventory.view') ? 'withdraw' : 'stock' : 'stock');

const kind = computed(() => tab.value === 'stock' ? 'batches' : tab.value === 'claims' ? 'claims' : 'withdrawals');

const state = reactive({rows:[],meta:{page:1,last_page:1,total:0},options:{accounts:[],products:[],providers:[],cities:[]},summary:null,query:'',account:route.query.account_id || '',product:'',provider:'',status:'',busy:false,saving:false,error:'',success:''});

const filtersOpen=ref(false), pageSize=ref(10), inventoryTab=ref('all');

const manager = ref(null), managerBusy = ref(false), claim = ref(null), decision = ref(''), reason = ref(''), withdrawalReasons = reactive({}), replacement = ref([]), replacementReading = ref(false), replacementFileName = ref('');

const isAdmin = computed(() => session.state.identity?.account.type === 'system');

const activeWithdrawals = computed(() => state.rows);

const writer = new AbortController(); let reader, revision = 0, timer, operationSignature = '', operationKey = '', withdrawalReviewCache = null;

const canManage = computed(() => ['inventory.edit','inventory.quarantine','inventory.cancel','inventory.restore','inventory.export','claims.create','exports.request'].some(permission => session.can(permission)) && session.can('inventory.details'));

const accountName = row => row.account_name || state.options.accounts.find(account => account.id === row.account_id)?.name || '—';

const costText = computed(() => state.summary?.available_cost_by_currency ? Object.entries(state.summary.available_cost_by_currency).map(([currency,amount]) => `${amount} ${currency === 'IQD' ? 'د.ع' : '$'}`).join(' · ') || '0.00' : '••••');

function filters(page = 1) {return {page,per_page:pageSize.value,query:state.query,account_id:state.account,product_id:state.product,provider_id:state.provider,status:state.status || (tab.value === 'history' ? 'history' : tab.value === 'withdraw' ? 'active' : '')};}

async function listRows(page,signal){

  if(tab.value!=='stock' || inventoryTab.value==='all' || state.status)return api.list(kind.value,filters(page),signal);

  const status={active:'active',stopped:'Quarantined',cancelled:'Cancelled by Reversal'}[inventoryTab.value] || 'Cancelled by Reversal';

  return api.list('batches',{...filters(page),status},signal);

}

async function exportList(){

  try{const {workbook}=await import('../../shared/files/excel-export.js');downloadText('inventory.xlsx',workbook(state.rows.map(row=>({'الدفعة':row.id,'الفئة':row.product_name,'الوكيل':row.account_name,'المتاح':row.available_count,'الإجمالي':row.quantity,'الحالة':stockStatus(row.status)}))));}catch(cause){await failure(cause);}

}

watch([pageSize,inventoryTab],()=>load());

async function failure(cause) {

  if (cause.name === 'AbortError') return;

  state.error = Object.values(cause.errors || {}).flat().join(' ') || cause.message;

  if (cause.status === 401 || cause.status === 403) {await session.refresh(); if (!session.state.identity) await router.replace({name:'login'});}

}

async function load(page = 1, withOptions = false) {

  reader?.abort(); reader = new AbortController(); const expected = ++revision; state.busy = true; state.error = '';

  try {const [response,options,summary] = await Promise.all([listRows(page,reader.signal),withOptions ? api.options(reader.signal) : Promise.resolve(null),tab.value === 'stock' ? api.summary(filters(page),reader.signal) : Promise.resolve(null)]); if (expected !== revision) return; state.rows = response.data; state.meta = response.meta; if (options) state.options = options.data; if (summary) state.summary = summary.data;}

  catch (cause) {if (expected === revision) await failure(cause);} finally {if (expected === revision) state.busy = false;}

}

function goTab(value) {router.push({name:value === 'claims' ? 'claims' : 'exports',query:{...(state.account ? {account_id:state.account}:{}),...(['history','withdraw'].includes(value) ? {tab:value}:{})}});}

function openClaim(row) {claim.value = row; decision.value = ''; reason.value = ''; replacement.value = []; replacementFileName.value = ''; state.error = ''; state.success = ''; operationSignature = ''; operationKey = '';}

function closeClaim() {if (state.saving || replacementReading.value) return; claim.value = null; replacement.value = [];}

async function readReplacement(event) {

  const file = event.target.files?.[0]; event.target.value = ''; if (!file || state.saving || replacementReading.value) return;

  replacementReading.value = true; state.error = ''; replacement.value = []; replacementFileName.value = '';

  try {

    const {readImportFile} = await import('../../shared/files/import-reader.js');

    const parsed = parseSheets(await readImportFile(file)); const product = state.options.products.find(product => product.id === claim.value.product_id);

    const rows = parsed.flatMap(line => line.rows.map(row => cardPayload(row,product)));

    if (rows.length !== claim.value.quantity) throw Error('عدد البطاقات البديلة يجب أن يساوي عدد بطاقات المطالبة.');

    if (rows.some(row => row.parse_error)) throw Error('صحح أعمدة ملف البطاقات البديلة أولًا.');

    replacement.value = rows; replacementFileName.value = file.name;

  } catch (cause) {await failure(cause);} finally {replacementReading.value = false;}

}

function stableKey(payload) {const signature = JSON.stringify(payload); if (signature !== operationSignature) {operationSignature = signature; operationKey = requestKey();} return operationKey;}

async function settle() {

  if (state.saving || !claim.value || !decision.value || !reason.value.trim()) return;

  state.saving = true; state.error = '';

  const payload = {version:claim.value.version,reason:reason.value.trim(),decision:decision.value,...(decision.value === 'replace' ? {replacement_rows:replacement.value}:{})}; const key = stableKey([claim.value.id,payload]);

  try {claim.value = (await api.settleClaim(claim.value.id,{...payload,idempotency_key:key},writer.signal)).data; state.success = 'تمت تسوية المطالبة.'; decision.value = ''; replacement.value = []; await load(state.meta.page);}

  catch (cause) {await failure(cause);} finally {state.saving = false;}

}

async function downloadWithdrawal(row, key) {

  const result = (await api.downloadWithdrawal(row.id,{version:row.version,reason:row.reason || 'تنزيل ملف الإرجاع',idempotency_key:key},writer.signal)).data;

  const {workbook} = await import('../../shared/files/excel-export.js');

  downloadText(`supplier-return-${row.id}.xlsx`,workbook(result.cards.map(card => ({Serial:card.serial,PIN:card.pin,Expiry:card.expiry,CVC:card.cvc || '',Reference:card.reference || ''}))));

}

async function withdrawal(row, action) {

  if (state.saving) return; state.saving = true; state.error = '';

  const rejectionReason = String(withdrawalReasons[row.id] || '').trim();

  const payload = {version:row.version,decision:action,reason:action === 'reject' ? rejectionReason : row.reason || 'اعتماد إرجاع للمزود',...(action === 'approve' ? {hours:24}:{})};

  if (action === 'reject' && !rejectionReason) {state.error = 'سبب الرفض مطلوب.'; state.saving = false; return;}

  const key = stableKey([row.id,payload]);

  try {

    if (action === 'download') await downloadWithdrawal(row,key);

    else {

      if (withdrawalReviewCache?.key !== key) withdrawalReviewCache = {key,row:(await api.reviewWithdrawal(row.id,{...payload,idempotency_key:key},writer.signal)).data};

      const reviewedWithdrawal = withdrawalReviewCache.row;

      if (action === 'approve' && session.can('exports.encrypt') && session.can('data.pin')) await downloadWithdrawal(reviewedWithdrawal,`${key}:download`);

    }

    state.success = action === 'reject' ? 'تم رفض طلب الإرجاع.' : action === 'approve' && !(session.can('exports.encrypt') && session.can('data.pin')) ? 'تم اعتماد الإرجاع.' : 'تم إكمال الإرجاع وتنزيل الملف.'; withdrawalReviewCache = null; delete withdrawalReasons[row.id]; await load(state.meta.page);

  } catch (cause) {await failure(cause);} finally {state.saving = false;}

}

watch(() => [state.query,state.account,state.product,state.provider,state.status], () => {clearTimeout(timer); timer = setTimeout(() => load(),250);});

watch(tab, () => {state.rows = []; state.summary = null; state.meta = {page:1,last_page:1,total:0}; state.status = ''; claim.value = null; manager.value = null; replacement.value = []; load(1,true);});

onMounted(() => load(1,true));

onBeforeUnmount(() => {clearTimeout(timer); reader?.abort(); writer.abort(); replacement.value = [];});

</script>

<template>

  <div class="catalog-workspace stock-workspace">

    <div v-if="route.name==='inventory'" class="metrics"><div class="card metric"><div class="metriclabel">بطاقات متاحة للبيع</div><div class="metricvalue">{{state.summary?.available_count ?? '—'}}</div></div><div class="card metric"><div class="metriclabel">تكلفة البطاقات المتاحة</div><div class="metricvalue">{{costText}}</div></div><div class="card metric"><div class="metriclabel">بطاقات محجورة أو مصدّرة</div><div class="metricvalue">{{state.summary ? state.summary.quarantined_count + state.summary.exported_count : '—'}}</div></div><div class="card metric"><div class="metriclabel">ترتيب بيع البطاقات</div><div class="metricvalue" style="font-size:23px">الأقرب انتهاءً أولًا</div><div class="metricsub">عند تساوي الانتهاء، تُختار البطاقة الأقدم إدخالًا تلقائيًا</div></div></div>

    <div v-if="state.error" class="notice warn" role="alert">{{state.error}}</div><div v-if="state.success" class="notice" role="status">{{state.success}}</div>

    <section class="card inventory-workspace"><div v-if="route.name!=='inventory'" class="tabs inventory-tabs"><button v-if="session.can('inventory.view')" type="button" :class="{active:tab === 'stock'}" @click="goTab('stock')">بطاقات المخزون</button><button v-if="session.can('claims.view')" type="button" :class="{active:tab === 'claims'}" @click="goTab('claims')">البطاقات التالفة</button><button v-if="session.can('exports.view')" type="button" :class="{active:tab === 'withdraw'}" @click="goTab('withdraw')">طلبات الإرجاع</button><button v-if="session.can('exports.view')" type="button" :class="{active:tab === 'history'}" @click="goTab('history')">سجل الإرجاع</button></div>

      <div v-if="route.name==='inventory'" class="inventory-original-toolbar"><button v-if="session.can('import.preview')" class="btn primary" @click="router.push({name:'import',query:{quick:'import'}})">طلبية جديدة</button><button v-if="session.can('inventory.export')" class="btn" :disabled="!state.rows.length" @click="exportList">تصدير</button><button class="btn" @click="filtersOpen=!filtersOpen" :aria-expanded="filtersOpen">فلترة وبحث</button><span class="caption">المخزون · {{state.meta.total}} سجل مطابق</span><div class="tabs"><button v-for="item in [{id:'all',label:'الكل'},{id:'active',label:'النشطة'},{id:'stopped',label:'الموقوفة'},{id:'cancelled',label:'الملغاة'}]" :class="{active:inventoryTab===item.id}" @click="inventoryTab=item.id;state.status=''">{{uiLabel(item.label)}}</button></div><select v-model.number="pageSize" aria-label="عدد دفعات المخزون في الصفحة"><option v-for="size in [10,25,50,100]" :value="size">{{size}}</option></select><small>{{state.meta.total ? (state.meta.page-1)*pageSize+1 : 0}}–{{Math.min(state.meta.page*pageSize,state.meta.total)}} من {{state.meta.total}}</small></div>

      <div v-if="route.name!=='inventory' || filtersOpen" class="inventory-controls"><input v-model="state.query" placeholder="بحث بالطلبية أو الفئة" aria-label="بحث المخزون"><select v-model="state.account" aria-label="الوكيل"><option value="">جميع الوكلاء</option><option v-for="account in state.options.accounts" :key="account.id" :value="account.id">{{account.name}}</option></select><select v-model="state.provider" aria-label="الشركة"><option value="">جميع الشركات</option><option v-for="provider in state.options.providers" :key="provider.id" :value="provider.id">{{provider.name}}</option></select><select v-model="state.product" aria-label="الفئة"><option value="">جميع الفئات</option><option v-for="product in state.options.products.filter(product => !state.provider || product.provider_id === Number(state.provider))" :key="product.id" :value="product.id">{{product.name}}</option></select><select v-model="state.status" @change="inventoryTab='all'" aria-label="الحالة"><option value="">جميع الحالات</option><option v-for="status in tab === 'stock' ? ['Loaded','Partially Used','Completed','Quarantined','Exported','Cancelled by Reversal'] : tab === 'claims' ? ['pending','restore','compensate','replace','reject','loss'] : ['pending','approved','downloaded','rejected','cancelled']" :key="status" :value="status">{{stockStatus(status)}}</option></select><button type="button" class="btn small" :disabled="state.busy" @click="load(state.meta.page,true)">تحديث</button></div>

      <TablePanel v-if="tab === 'stock'" class="tablewrap"><table><thead><tr><th>الدفعة</th><th>الوكيل / الفئة</th><template v-if="route.name==='exports'"><th>الإجمالي</th><th>المتاح</th><th>المباع</th><th>التالف</th><th>الملغي</th></template><template v-else><th>المتاح للبيع / إجمالي الدفعة</th><th>التكلفة</th><th>الانتهاء</th></template><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="batch in state.rows" :key="batch.id"><td class="mono">{{batch.id}}</td><td><strong>{{batch.product_name}}</strong><div class="caption">{{batch.account_name}}</div></td><template v-if="route.name==='exports'"><td>{{batch.quantity}}</td><td>{{batch.available_count}}</td><td>{{batch.sold_count ?? '—'}}</td><td>{{batch.damaged_count ?? '—'}}</td><td>{{batch.cancelled_count ?? '—'}}</td></template><template v-else><td>{{batch.available_count}} / {{batch.quantity}}</td><td class="mono">{{batch.cost_total ?? '••••'}} <small>{{batch.currency === 'IQD' ? 'د.ع' : '$'}}</small></td><td>{{batch.min_expiry || '—'}}</td></template><td><span class="badge">{{stockStatus(batch.status)}}</span></td><td><div class="actions"><button v-if="session.can('inventory.details')" type="button" class="btn small" @click="manager = {id:batch.id,inspectOnly:true}">معاينة</button><button v-if="canManage" type="button" class="btn small primary" @click="manager = {id:batch.id,inspectOnly:false}">إدارة</button></div></td></tr></tbody></table></TablePanel>

      <TablePanel v-else-if="tab === 'claims'" class="tablewrap"><table class="claims-register"><thead><tr><th>رقم الطلب</th><th>الوكيل</th><th>الفئة</th><th>عدد البطاقات</th><th>تاريخ الطلب</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="row in state.rows" :key="row.id"><td>{{row.id}}</td><td>{{accountName(row)}}</td><td>{{row.product_name}}</td><td>{{row.quantity}}</td><td>{{printableTime(row.created_at)}}</td><td><span class="badge">{{row.status === 'pending' ? 'بانتظار المعالجة' : stockStatus(row.status)}}</span></td><td><button type="button" class="btn small" @click="openClaim(row)">معاينة</button></td></tr></tbody></table></TablePanel>

      <TablePanel v-else class="tablewrap"><table><thead><tr><th v-if="tab === 'history'">رقم السجل</th><th>الدفعة / الفئة</th><th>البطاقات</th><th>السبب</th><th>الحالة</th><th>الإجراءات</th><th v-if="tab === 'history'">التاريخ</th></tr></thead><tbody><tr v-for="row in activeWithdrawals" :key="row.id"><td v-if="tab === 'history'">{{row.id}}</td><td>{{row.batch_id}} · {{row.product_name}}</td><td>{{row.quantity}}</td><td>{{row.reason}}</td><td>{{stockStatus(row.status)}}</td><td><div class="actions"><template v-if="row.status === 'pending' && isAdmin && session.can('exports.approve')"><button type="button" class="btn small primary" :disabled="state.saving" @click="withdrawal(row,'approve')">{{session.can('exports.encrypt') && session.can('data.pin') ? 'اعتماد الإرجاع وتنزيل الملف' : 'اعتماد الإرجاع'}}</button><input v-model="withdrawalReasons[row.id]" :disabled="state.saving" placeholder="سبب الرفض" :aria-label="'سبب رفض الإرجاع ' + row.id"><button type="button" class="btn small danger" :disabled="state.saving || !String(withdrawalReasons[row.id] || '').trim()" @click="withdrawal(row,'reject')">رفض</button></template><button v-if="['approved','downloaded'].includes(row.status) && session.can('exports.encrypt') && session.can('data.pin')" type="button" class="btn small" :disabled="state.saving" @click="withdrawal(row,'download')">{{row.status === 'approved' ? 'إكمال الإرجاع وتنزيل الملف' : 'إعادة تنزيل'}}</button></div></td><td v-if="tab === 'history'">{{printableTime(row.downloaded_at || row.created_at)}}</td></tr></tbody></table></TablePanel>

      <p v-if="state.busy" class="read-loading help" role="status">جارٍ تحميل البيانات…</p><p v-else-if="!state.rows.length" class="empty">{{tab === 'stock' ? 'لا توجد دفعات مطابقة' : tab === 'claims' ? 'لا توجد طلبات' : 'لا توجد عمليات إرجاع'}}</p><div class="actions"><button type="button" class="btn small" :disabled="state.busy || state.meta.page <= 1" @click="load(state.meta.page-1)">السابق</button><span>{{state.meta.page}} / {{state.meta.last_page}}</span><button type="button" class="btn small" :disabled="state.busy || state.meta.page >= state.meta.last_page" @click="load(state.meta.page+1)">التالي</button></div>

    </section>

    <CatalogModal v-if="manager" :title="manager.inspectOnly ? 'تفاصيل دفعة المخزون' : 'إدارة المخزون'" section="المخزون" content-class="stock-modal" :busy="managerBusy" @close="!managerBusy && (manager = null)"><InventoryManager :batch-id="manager.id" :inspect-only="manager.inspectOnly" :cities="state.options.cities" @changed="load(state.meta.page)" @error="failure" @busy="managerBusy = $event" @withdrawals="goTab('withdraw')"/></CatalogModal>

    <CatalogModal v-if="claim" :title="'معاينة الطلب · ' + claim.id" section="التالف والمطالبات" content-class="stock-modal claim-preview-dialog" :busy="state.saving || replacementReading" @close="closeClaim"><article class="ops-request"><div class="claim-preview-fields"><div><small>الوكيل</small><b>{{accountName(claim)}}</b></div><div><small>الفئة</small><b>{{claim.product_name}}</b></div><div><small>عدد البطاقات</small><b>{{claim.quantity}}</b></div><div><small>الحالة</small><span class="badge settlement-status">{{stockStatus(claim.status)}}</span></div><div><small>رقم الدفعة</small><b>{{claim.batch_id}}</b></div><div><small>تاريخ الطلب</small><b>{{printableTime(claim.created_at)}}</b></div><div class="claim-reason"><small>سبب الطلب</small><b>{{claim.reason}}</b></div><div v-if="claim.replacement_batch_id"><small>الدفعة البديلة</small><b>{{claim.replacement_batch_id}}</b></div></div>

      <p v-if="claim.compensation_amount != null" class="notice">قيمة التعويض حسب سعر تحميل الدفعة الأصلي: {{claim.compensation_amount}} {{claim.currency === 'IQD' ? 'د.ع' : '$'}}</p><template v-if="claim.status === 'pending' && isAdmin && session.can('claims.settle')"><fieldset class="stock-fieldset" :disabled="state.saving || replacementReading"><div class="actions"><button v-if="session.can('import.approve') && session.can('data.pin')" type="button" class="btn" :class="{primary:decision === 'replace'}" @click="decision = 'replace'">استبدال بطاقات</button><button type="button" class="btn" :class="{primary:decision === 'compensate'}" @click="decision = 'compensate'">تعويض مالي</button><button type="button" class="btn" @click="decision = 'restore'">إعادة للمخزون</button><button v-if="session.can('claims.loss')" type="button" class="btn danger" @click="decision = 'loss'">اعتماد خسارة</button></div><template v-if="decision === 'replace'"><label class="btn claim-upload">رفع ملف البطاقات البديلة<input class="claim-file-input" type="file" accept=".txt,.csv,.xlsx" aria-label="رفع ملف البطاقات البديلة" @change="readReplacement"></label><p>{{replacementFileName}} · {{replacement.length}} بطاقة</p><TablePanel v-if="replacement.length" class="tablewrap"><table><thead><tr><th>السيريال</th><th>الانتهاء</th></tr></thead><tbody><tr v-for="(row,index) in replacement.slice(0,25)" :key="index"><td>{{row.serial}}</td><td>{{row.expiry}}</td></tr></tbody></table></TablePanel></template><label v-if="decision">سبب التسوية<textarea v-model="reason"></textarea></label><div v-if="decision" class="formfoot"><button type="button" class="btn primary" :disabled="!reason.trim() || (decision === 'replace' && !replacement.length)" @click="settle">{{decision === 'replace' ? 'تأكيد الاستبدال' : decision === 'compensate' ? 'تأكيد التعويض' : 'تأكيد التسوية'}}</button></div></fieldset></template><p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p></article></CatalogModal>

  </div>

</template>



<style scoped>

.inventory-original-toolbar{display:flex;gap:8px;align-items:center;padding:8px;background:var(--raised);border:1px solid var(--line);border-radius:10px;margin-bottom:14px;flex-wrap:wrap}.inventory-original-toolbar>.caption{flex:1;font-size:11px;margin:0}.inventory-original-toolbar>select{width:70px}.inventory-original-toolbar .btn,.inventory-original-toolbar .tabs button{font-size:11px;padding:7px 10px}.inventory-original-toolbar .tabs{margin:0}.stock-workspace .inventory-workspace>.actions{justify-content:flex-end;gap:18px;margin-top:12px}

.inventory-original-toolbar>select,.inventory-original-toolbar .btn,.inventory-original-toolbar .tabs button{height:36px;min-height:36px}.inventory-original-toolbar .tabs{padding:0}.inventory-original-toolbar>select{padding-block:4px}.stock-workspace .inventory-workspace>p.empty{min-height:0;padding:32px 12px;margin:0}

</style>

