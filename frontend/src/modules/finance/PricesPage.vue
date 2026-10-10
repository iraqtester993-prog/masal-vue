<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';


import { matchesPrice, priceDifference } from './price-filters.js';

import {usePreferencesTranslator} from '../preferences/preferences-state.js';

import { vMoney } from './money-input.js';

import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

import FinanceDialog from './FinanceDialog.vue';

import FinancePagination from './FinancePagination.vue';

import { allPages } from './finance-api.js';

import { cleanMoney, difference, errorText, minor, money, priceChanges, pricePreview, time } from './finance-model.js';

import { priceTemplateRows, readPriceTemplate } from './price-template.js';

import { useFinanceRuntime } from './finance-runtime.js';

import './finance-parity.css';

const vm = useFinanceRuntime(), tr = usePreferencesTranslator();

const imported = ref(false);

const account = ref(''), products = ref([]), mode = ref('individual'), scope = ref('filtered'), direction = ref('add'), value = ref(''), selected = ref([]), drafts = reactive({});

const filtersOpen = ref(false), filters = reactive({ query: '', provider: '', product: '', currency: '', status: '', from: '', to: '' });

const preview = ref(null), reading = ref(false), priceLoading = ref(false), priceError = ref(''), review = ref(null), reason = ref('');

const history = vm.resource('price-requests'), historyFilters = reactive({ q: '', status: '', from: '', to: '' });

const createKey = vm.key(), actionKey = vm.key();

let priceController, priceRevision = 0, historyTimer;

watch(historyFilters,()=>{clearTimeout(historyTimer);historyTimer=setTimeout(()=>loadHistory(),300);},{deep:true});

const canEdit = computed(() => vm.can('prices.propose') && vm.options.accounts.some(row=>row.id===Number(account.value) && row.type==='main_agent'));

const canReview = computed(() => vm.admin.value && vm.ownVisible.value && vm.can('prices.approve'));

const canReverse = computed(() => vm.admin.value && vm.ownVisible.value && vm.can('prices.reverse'));

const providers = computed(() => [...new Map(products.value.map((row) => [row.provider_id, { id: row.provider_id, name: row.provider_name }])).values()]);

const filtered = computed(() => products.value.filter(row => matchesPrice(row, filters)));

const pricePage = ref(1), priceSize = ref(10), historySize = ref(10);

const pricePages = computed(() => Math.max(1, Math.ceil(filtered.value.length / priceSize.value)));

const pagedPrices = computed(() => filtered.value.slice((pricePage.value - 1) * priceSize.value, pricePage.value * priceSize.value));

watch([filtered, priceSize], () => { pricePage.value = 1; });

watch(historySize, () => loadHistory());

const validDates = computed(() => !filters.from || !filters.to || filters.from <= filters.to);

const draftCount = computed(() => products.value.filter((row) => cleanMoney(drafts[row.product_id]) !== '').length);

const targetIds = computed(() => scope.value === 'selected' ? selected.value : (scope.value === 'all' ? products.value : filtered.value).map((row) => row.product_id));

const historyRows = computed(() => history.state.rows.flatMap((row) => row.changes.map((change, index) => ({ ...change, operation: row, key: `${row.id}-${index}` }))));

const historyState = { pending: 'قيد المراجعة', approved: 'معتمد', rejected: 'مرفوض', reversed: 'تم التراجع' };

const autoApprove = computed(() => (vm.owner.value && vm.identity.value?.account.type === 'main_agent' && Number(account.value) === vm.ownId.value) || (vm.admin.value && vm.can('prices.approve')));

function clearDrafts() { imported.value = false; Object.keys(drafts).forEach((id) => delete drafts[id]); selected.value = []; preview.value = null; createKey.clear(); }

async function loadPrices() {

  if (!account.value) return;

  priceController?.abort(); priceController = new AbortController(); const expected = ++priceRevision;

  products.value = []; priceError.value = ''; priceLoading.value = true;

  try { const rows = await allPages(vm.api, 'prices', { account_id: account.value }, priceController.signal); if (expected === priceRevision) products.value = rows; }

  catch (failed) { if (failed.name !== 'AbortError' && expected === priceRevision) priceError.value = await vm.failure(failed); }

  finally { if (expected === priceRevision) priceLoading.value = false; }

}

function loadHistory(page = 1) { history.load({ ...historyFilters, account_id: account.value, per_page: historySize.value, page }); }

watch(account, async () => { clearDrafts(); await loadPrices(); loadHistory(); });

onMounted(async () => { await vm.loadOptions(); const own=vm.options.accounts.find(row=>row.id===vm.ownId.value); account.value=String(own?.type==='main_agent'?own.id:vm.options.accounts.find(row=>row.type==='main_agent')?.id ?? vm.ownId.value); });

onBeforeUnmount(() => { priceRevision += 1; priceController?.abort(); clearTimeout(historyTimer); });

function prepare() {

  vm.error.value = ''; priceError.value = '';

  if (!validDates.value) { priceError.value = 'تاريخ البداية يجب أن يسبق تاريخ النهاية.'; return; }

  try {

    const rows = pricePreview(products.value, drafts, { mode: mode.value, targetIds: targetIds.value, direction: direction.value, value: value.value });

    let valid = true;

    try { priceChanges(rows); } catch (failed) { valid = false; priceError.value = errorText(failed); }

    preview.value = { rows, valid, account: Number(account.value) };

  } catch (failed) { priceError.value = errorText(failed); }

}

async function confirm() {

  if (!preview.value?.valid) return;

  const payload = { account_id: preview.value.account, changes: priceChanges(preview.value.rows), ...(imported.value ? {source:'import'} : {}) };

  const result = await vm.execute(payload, createKey, (body) => vm.api.create('price-requests', body), 'تم حفظ عملية تعديل الأسعار.');

  if (result) { clearDrafts(); await loadPrices(); loadHistory(); }

}

async function exportPrices() {

  if (!vm.can('prices.template') || !account.value) return;

  try {

    const rows = priceTemplateRows(await allPages(vm.api, 'price-template', {account_id: account.value}), account.value);

    const { workbook } = await import('../../shared/files/excel-export.js');

    const url = URL.createObjectURL(workbook(rows)), link = document.createElement('a');

    link.href = url; link.download = 'masal-prices-v1.xlsx'; link.click();

    setTimeout(() => URL.revokeObjectURL(url), 0);

  } catch (failed) { priceError.value = errorText(failed); }

}

async function importPrices(event) {

  const file = event.target.files?.[0]; event.target.value = ''; if (!file || reading.value || vm.busy.value || !canEdit.value || !vm.can('prices.import')) return;

  reading.value = true; priceError.value = '';

  const importedAccount = account.value, importedRevision = priceRevision, importedProducts = products.value;

  try {

    if (!/\.(csv|xlsx)$/i.test(file.name)) throw new Error('اختر CSV أو XLSX.');

    const { readImportFile } = await import('../../shared/files/import-reader.js');

    const sheets = await readImportFile(file), incoming = readPriceTemplate(sheets, importedProducts, importedAccount);

    if (account.value !== importedAccount || priceRevision !== importedRevision || !canEdit.value || !vm.can('prices.import')) throw new Error('تغير الحساب أو الوكيل أو الصفحة؛ أعد الاستيراد.');

    clearDrafts(); Object.assign(drafts, incoming); imported.value = true; mode.value = 'individual'; prepare();

  } catch (failed) { priceError.value = errorText(failed); }

  finally { reading.value = false; }

}

function openReview(row, action) { review.value = { row, action }; reason.value = ''; vm.error.value = ''; actionKey.clear(); }

async function reviewPrice() {

  const { row, action } = review.value;

  const payload = { version: row.version, ...(action === 'reverse' ? {} : { decision: action }), ...(action !== 'approve' ? { reason: reason.value.trim() } : {}) };

  const result = await vm.execute(payload, actionKey, (body) => vm.api.action('price-requests', row.id, action === 'reverse' ? 'reverse' : 'review', body));

  if (result) { review.value = null; await loadPrices(); loadHistory(history.state.meta.current_page); }

}

</script>

<template>

  <div class="finance-workspace prices-workspace">

    <p v-if="vm.error.value && !preview && !review" class="notice warn" role="alert">{{ vm.error.value }}</p><p v-if="vm.notice.value" class="notice" role="status">{{ vm.notice.value }}</p>

    <section class="card price-editor">

      <nav v-if="canEdit" class="price-mode-tabs" aria-label="طريقة تعديل الأسعار"><button type="button" class="btn" :class="{active:mode==='individual'}" :aria-pressed="mode==='individual'" :disabled="vm.busy.value || reading" @click="mode='individual'">تعديل سعر فئة</button><button type="button" class="btn" :class="{active:mode==='bulk'}" :aria-pressed="mode==='bulk'" :disabled="vm.busy.value || reading" @click="mode='bulk'">تعديل أسعار الفئات</button></nav>

      <section class="catalog-filter price-catalog-filter" :class="{'is-open':filtersOpen}"><div class="catalog-filter-head"><div class="price-scope-summary"><span class="price-agent-name" :title="vm.name(account)">{{ vm.name(account) }}</span><span class="price-category-count">{{ filtered.length }} فئة</span></div><button type="button" class="btn" :aria-expanded="filtersOpen" @click="filtersOpen=!filtersOpen">فلترة وبحث<span v-if="Object.values(filters).some(Boolean)"> •</span></button><button v-if="Object.values(filters).some(Boolean)" type="button" class="btn small" @click="Object.keys(filters).forEach(key=>filters[key]='')">مسح الفلاتر</button><details class="price-toolbar-extra"><summary>خيارات إضافية</summary><div class="actions"><button v-if="vm.can('prices.template')" class="btn" :disabled="priceLoading" @click="exportPrices">تنزيل قالب الأسعار</button><label v-if="canEdit && vm.can('prices.import')" class="btn">{{tr(reading?'جارٍ قراءة الأسعار':'استيراد Excel / CSV')}}<input type="file" accept=".csv,.xlsx" hidden :disabled="reading || vm.busy.value || priceLoading" @change="importPrices"></label></div></details><select v-model.number="priceSize" aria-label="عدد فئات الأسعار في الصفحة"><option v-for="size in [10,25,50,100]" :value="size">{{size}}</option></select><small>{{filtered.length ? (pricePage-1)*priceSize+1 : 0}}–{{Math.min(pricePage*priceSize,filtered.length)}} من {{filtered.length}}</small></div>

        <form v-if="filtersOpen" class="catalog-filter-fields" @submit.prevent="validDates && (filtersOpen=false)"><label class="catalog-filter-search">بحث<input v-model="filters.query" type="search" placeholder="اسم أو معرف أو قيمة اسمية"></label><label>الحساب<select v-model="account" :disabled="vm.busy.value"><option v-for="row in vm.options.accounts" :key="row.id" :value="String(row.id)">{{row.name}}</option></select></label><label>الفئة<select v-model="filters.product"><option value="">كل الفئات</option><option v-for="row in products" :key="row.product_id" :value="row.product_id">{{row.name}}</option></select></label><label>الشركة<select v-model="filters.provider"><option value="">الكل</option><option v-for="row in providers" :key="row.id" :value="row.id">{{row.name}}</option></select></label><label>الحالة<select v-model="filters.status"><option value="">الكل</option><option value="active">مفعّل</option><option value="disabled">معطّل</option><option value="priced">لها سعر</option><option value="unpriced">بلا سعر</option></select></label><label>من تاريخ السعر<input v-model="filters.from" type="date" :max="filters.to || undefined"></label><label>إلى تاريخ السعر<input v-model="filters.to" type="date" :min="filters.from || undefined"></label><div class="actions"><button class="btn primary" :disabled="!validDates">تطبيق</button></div></form>

      </section>

      <div v-if="canEdit && mode==='bulk'" class="price-bulk-toolbar"><label>نطاق التعديل<select v-model="scope" aria-label="نطاق تعديل الأسعار"><option value="filtered">نتائج الفلترة</option><option value="all">كل الفئات</option><option value="selected">الفئات المحددة يدويًا</option></select></label><label>نوع التعديل<select v-model="direction" aria-label="نوع تعديل الأسعار"><option value="add">زيادة مبلغ</option><option value="subtract">نقصان مبلغ</option></select></label><label>المبلغ<input v-model="value" type="text" inputmode="decimal" v-money aria-label="مبلغ تعديل الأسعار"></label><button class="btn primary" :disabled="vm.busy.value || reading || !targetIds.length" @click="prepare">معاينة التغييرات ({{targetIds.length}})</button></div>

      <p v-if="priceError && !preview" class="notice warn" role="alert">{{priceError}}</p><p v-if="priceLoading || vm.loading.value" class="read-loading caption" role="status">جارٍ تحميل الأسعار…</p>

      <TablePanel class="tablewrap"><table class="price-edit-table"><thead><tr><th v-if="canEdit && mode==='bulk'">تحديد</th><th>الفئة</th><th v-if="vm.can('data.cost')">أقل سعر مسموح</th><th>السعر الحالي</th><th v-if="canEdit && mode==='individual'">السعر الجديد</th></tr></thead><tbody><tr v-for="row in pagedPrices" :key="row.product_id"><td v-if="canEdit && mode==='bulk'"><input v-model="selected" type="checkbox" :value="row.product_id" :aria-label="'تحديد '+row.name" @change="scope='selected'"></td><td>{{row.name}}</td><td v-if="vm.can('data.cost')">{{money(row.minimum_price)}} {{vm.currencyLabel(row.currency)}}</td><td>{{money(row.price)}} {{vm.currencyLabel(row.currency)}}</td><td v-if="canEdit && mode==='individual'"><input v-model="drafts[row.product_id]" type="text" inputmode="decimal" v-money :aria-label="'السعر الجديد '+row.name" placeholder="بدون تعديل" :disabled="vm.busy.value || reading"></td></tr><tr v-if="!filtered.length && !priceLoading"><td colspan="5" class="empty">لا توجد فئات مطابقة</td></tr></tbody></table></TablePanel>

      <div class="price-pagination"><button class="btn small" :disabled="pricePage<=1" @click="pricePage--">السابق</button><span>الصفحة {{pricePage}} من {{pricePages}}</span><button class="btn small" :disabled="pricePage>=pricePages" @click="pricePage++">التالي</button></div>

      <div v-if="canEdit && mode==='individual'" class="formfoot"><span>{{draftCount}} تعديلات فردية</span><button class="btn" :disabled="vm.busy.value || reading" @click="clearDrafts">مسح التعديلات الفردية</button><button class="btn primary" :disabled="!draftCount || vm.busy.value || reading" @click="prepare">معاينة التغييرات</button></div>

    </section>

    <section class="card price-history" style="margin-top:20px"><h3>سجل تغييرات الأسعار</h3><form class="toolbar" @submit.prevent="loadHistory()"><input v-model="historyFilters.q" type="search" placeholder="بحث بالعملية أو الفئة أو المستخدم" aria-label="بحث سجل الأسعار"><select v-model="historyFilters.status" aria-label="حالة سجل الأسعار"><option value="">كل الحالات</option><option value="approved">معتمد</option><option value="pending">قيد المراجعة</option><option value="rejected">مرفوض</option><option value="reversed">تم التراجع</option></select><label>من<input v-model="historyFilters.from" type="date" aria-label="من تاريخ" :max="historyFilters.to || undefined"></label><label>إلى<input v-model="historyFilters.to" type="date" aria-label="إلى تاريخ" :min="historyFilters.from || undefined"></label><select v-model.number="historySize" aria-label="عدد عمليات الأسعار في الصفحة"><option v-for="size in [10,25,50,100]" :value="size">{{size}}</option></select></form><p v-if="history.state.error" class="notice warn" role="alert">{{history.state.error}}</p><p v-if="history.state.loading" class="read-loading caption" role="status">جارٍ تحميل السجل…</p><TablePanel class="tablewrap"><table><thead><tr><th>رقم العملية</th><th>التاريخ والوقت</th><th>الوكيل</th><th>الفئة</th><th>السعر السابق</th><th>السعر الجديد</th><th>الفرق</th><th>نفذ التعديل</th><th>الحالة</th><th v-if="canReview || canReverse">الإجراءات</th></tr></thead><tbody><tr v-for="row in historyRows" :key="row.key"><td>{{row.operation.id}}</td><td>{{time(row.operation.created_at)}}</td><td>{{row.operation.account_name || vm.name(row.operation.account_id)}}</td><td>{{row.name}}</td><td>{{money(row.old_price)}}</td><td>{{money(row.price)}}</td><td dir="ltr">{{row.old_price!==null && minor(row.price)>minor(row.old_price)?'+':''}}{{money(priceDifference(row.price,row.old_price))}}</td><td>{{row.operation.creator_name || '#'+row.operation.creator_id}}</td><td><span class="badge">{{tr(historyState[row.operation.status] || row.operation.status)}}</span></td><td v-if="canReview || canReverse"><div class="actions"><button v-if="row.operation.status==='pending' && canReview" class="btn small primary" :disabled="vm.busy.value" @click="openReview(row.operation,'approve')">اعتماد</button><button v-if="row.operation.status==='pending' && canReview" class="btn small danger" :disabled="vm.busy.value" @click="openReview(row.operation,'reject')">رفض</button><button v-if="row.operation.status==='approved' && canReverse" class="btn small" :disabled="vm.busy.value" @click="openReview(row.operation,'reverse')">تراجع عن هذه العملية</button><span v-if="row.operation.status==='rejected' || row.operation.status==='reversed'">{{row.operation.reason || '—'}}</span></div></td></tr><tr v-if="!historyRows.length && !history.state.loading"><td colspan="10" class="empty">لا توجد تغييرات</td></tr></tbody></table></TablePanel><FinancePagination :meta="history.state.meta" :busy="history.state.loading" @page="loadHistory" /></section>

    <FinanceDialog variant="price-preview-dialog" :open="!!preview" title="معاينة تعديل الأسعار" :busy="vm.busy.value" @close="preview=null"><template v-if="preview"><p>{{vm.name(preview.account)}} · {{preview.rows.length}} فئات</p><TablePanel class="tablewrap"><table><thead><tr><th>الفئة</th><th>السعر القديم</th><th>التغيير</th><th>السعر الجديد</th><th>الفحص</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.product_id"><td>{{row.name}}</td><td>{{money(row.old_price)}}</td><td dir="ltr">{{row.old_price!==null && row.difference && minor(row.difference)>0n?'+':''}}{{money(priceDifference(row.price,row.old_price))}}</td><td>{{money(row.price)}}</td><td>{{row.error || (row.old_price!==null && row.difference==='0.00'?'بدون تغيير':'صالح')}}</td></tr></tbody></table></TablePanel><p v-if="priceError || vm.error.value" class="notice warn" role="alert">{{priceError || vm.error.value}}</p><div class="actions"><button class="btn primary" :disabled="!preview.valid || vm.busy.value" @click="confirm">{{tr(autoApprove?'حفظ الأسعار':'إرسال للاعتماد')}}</button><button class="btn" :disabled="vm.busy.value" @click="preview=null">رجوع للتعديل</button></div></template></FinanceDialog>

    <FinanceDialog :open="!!review" :title="review?.action==='reverse'?'تراجع عن عملية الأسعار':review?.action==='reject'?'رفض تعديل الأسعار':'اعتماد تعديل الأسعار'" :busy="vm.busy.value" @close="review=null"><template v-if="review"><p>العملية #{{review.row.id}} · {{vm.name(review.row.account_id)}} · {{review.row.changes.length}} فئات</p><form @submit.prevent="reviewPrice"><label v-if="review.action!=='approve'">السبب<input v-model="reason" required maxlength="1000"></label><p v-if="vm.error.value" class="notice warn" role="alert">{{vm.error.value}}</p><div class="actions"><button class="btn primary" :disabled="vm.busy.value || (review.action!=='approve' && !reason.trim())">تأكيد</button><button type="button" class="btn" :disabled="vm.busy.value" @click="review=null">إلغاء</button></div></form></template></FinanceDialog>

  </div>

</template>

<style scoped>

.prices-workspace .price-editor{padding:20px;border-radius:14px}

.prices-workspace .price-catalog-filter .catalog-filter-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:12px;overflow:visible}

.prices-workspace .price-catalog-filter .catalog-filter-fields label{min-width:0;width:auto;flex:none}

.prices-workspace .price-catalog-filter .catalog-filter-fields :is(input,select){min-width:0;width:100%;box-sizing:border-box}

.prices-workspace .price-catalog-filter .catalog-filter-fields .actions{align-self:end;justify-content:flex-start}

.prices-workspace .price-mode-tabs .btn.active{background:#087f9b!important;border-color:#087f9b!important;color:#fff!important}

.prices-workspace .price-edit-table input:not([type=checkbox]){width:220px;max-width:100%}

.price-pagination{display:flex;gap:20px;align-items:center;justify-content:flex-end;padding:12px 0 24px;font-size:12px}

.prices-workspace .price-history .toolbar{background:var(--raised);padding:12px;align-items:end;gap:8px}

.prices-workspace .price-history .toolbar label{display:grid;gap:6px;min-width:0;flex:1}

.prices-workspace .catalog-filter-head>select{width:70px;flex:none}

.prices-workspace .price-history .toolbar>select[aria-label="عدد عمليات الأسعار في الصفحة"]{flex:none;width:70px}.prices-workspace .price-history .toolbar label{max-width:180px}

</style>



