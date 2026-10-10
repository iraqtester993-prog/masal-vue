<script setup>
import {usePreferencesTranslator} from "../preferences/preferences-state.js";
const uiLabel=usePreferencesTranslator();
import TopupWallet from './TopupWallet.vue';
import TablePanel from '../../shared/components/TablePanel.vue';


import {useRoute} from 'vue-router';

const route=useRoute();

import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';

import { financeKey, emptyFilters, matchesAccount, money, movementKinds, positive, sum, time } from './finance-model.js';

import { useFinanceRuntime } from './finance-runtime.js';

import WalletAccountsTable from './WalletAccountsTable.vue';

import WalletFilterDialog from './WalletFilterDialog.vue';

import SimpleWallets from './SimpleWallets.vue';

import WalletBulk from './WalletBulk.vue';

import WalletRecovery from './WalletRecovery.vue';

import WalletInvoices from './WalletInvoices.vue';

import FinancePagination from './FinancePagination.vue';

import './finance-parity.css';

const vm = useFinanceRuntime(), service = ref('voucher'), currency = ref('IQD'), tab = ref('balances'), account = ref(''), showFilters = ref(false);

const filters = reactive(emptyFilters()), ledger = vm.resource('ledger');

const stockError=ref('');let stockController,stockRevision=0;

const filtersActive = computed(() => Object.values(filters).some(Boolean));

const accounts = computed(() => vm.options.accounts.filter((row) => matchesAccount(row, filters, vm.options.accounts)));

const tabs = computed(() => [

  { id: 'balances', name: 'الأرصدة والحركات' },

  { id: 'fundingRequests', name: vm.admin.value ? 'طلبات تمويل الوكلاء' : 'طلبات وسجل التمويل' },

  ...(vm.can('wallets.bulk') && vm.can('wallets.transfer') ? [{id:'bulk',name:'تمويل متعدد'}] : []),

  {id:'recovery',name:'استرجاع الرصيد'},

  ...(vm.can('invoices.view') ? [{id:'invoice',name:'الفواتير والتحصيل'}] : []),

]);

const ownTotals = computed(() => !vm.admin.value && vm.options.accounts.some(row=>row.id===vm.ownId.value));

const totals = computed(() => {

  if (!vm.balancesReady.value || vm.balancesError.value) return {current:null,available:null,held:null,children:null};

  const visible = new Set(accounts.value.map((row) => Number(row.id)));

  const rows = vm.wallets.value.filter((row) => row.currency === currency.value && (!service.value || row.service === service.value));

  const displayed = rows.filter((row) => ownTotals.value ? Number(row.account_id) === vm.ownId.value : visible.has(Number(row.account_id)));

  return {current:sum(displayed.map(row=>row.balance)),available:sum(displayed.map(row=>row.available)),held:sum(displayed.map(row=>row.held)),children:sum(rows.filter(row=>visible.has(Number(row.account_id)) && Number(row.account_id)!==vm.ownId.value).map(row=>row.balance))};

});

function recordParameters(extra = {}) {

  const accountFilters = ['city','kind','network','active','query','account'].some((key)=>Boolean(filters[key]));

  return { service: service.value, currency: currency.value, ...(accountFilters ? { account_ids: accounts.value.map(row=>row.id) } : {}), q: filters.reference, from: filters.from, to: filters.to, ...extra };

}

function emptyScope() { return ['city','kind','network','active','query','account'].some(key=>Boolean(filters[key])) && !accounts.value.length; }

async function loadLedger(page = 1) {

  if (!vm.can('ledger.view')) return;

  if (emptyScope()) { ledger.dispose(); ledger.state.meta={current_page:1,last_page:1,total:0}; return; }

  await ledger.load(recordParameters({ account_id: account.value, per_page:25, page }));

}

async function loadStockMetrics(){

  stockController?.abort();const expected=++stockRevision;vm.options.stock_metrics=null;stockError.value='';

  if(!['system','main_agent'].includes(vm.identity.value?.account.type) || (service.value && service.value!=='voucher') || emptyScope())return;

  stockController=new AbortController();

  const accountFilters=['city','kind','network','active','query','account'].some(key=>Boolean(filters[key]));

  try{const result=await vm.api.list('wallets',{service:service.value,currency:currency.value,...(accountFilters?{account_ids:accounts.value.map(row=>row.id)}:{}),per_page:1},stockController.signal);if(expected===stockRevision)vm.options.stock_metrics=result.stock_metrics ?? null;}

  catch(failed){if(failed.name!=='AbortError' && expected===stockRevision)stockError.value=await vm.failure(failed);}

}

async function refresh(fresh = true) { if (fresh) vm.session.api.clearReadCache?.(); await vm.loadOptions(); await Promise.all([loadLedger(),loadStockMetrics()]); }

function clearFilters() { Object.assign(filters,emptyFilters()); account.value=''; }

const context = { ...vm, service, currency, filters, filtersActive, accounts, showFilters, recordParameters, emptyScope, refresh, clearFilters };

provide(financeKey, context);

watch([service,currency], () => {loadLedger();loadStockMetrics();});watch(account,()=>loadLedger());

watch(filters, () => { if (account.value && !accounts.value.some(row=>String(row.id)===String(account.value))) account.value=''; loadLedger();loadStockMetrics(); }, {deep:true});

function quickDeposit(){if(route.query.quick==='deposit' && vm.admin.value && vm.can('wallets.deposit'))tab.value='fundingRequests';}

watch(()=>route.query.quick,quickDeposit);

onMounted(async()=>{await refresh(false);quickDeposit();});

onBeforeUnmount(()=>{stockRevision++;stockController?.abort();});

</script>

<template>

  <div class="finance-workspace operations-panel">

    <p v-if="vm.error.value" class="notice warn" role="alert">{{vm.error.value}}</p><p v-if="vm.notice.value" class="notice" role="status">{{vm.notice.value}}</p><p v-if="vm.loading.value" class="read-loading caption" role="status">جارٍ تحميل المحافظ…</p>

    <section class="wallet-advanced"><div class="card"><div class="cardhead wallet-filter-toolbar"><div class="tabs wallet-navigation-tabs"><button v-for="item in tabs" :key="item.id" :class="{active:tab===item.id}" :aria-pressed="tab===item.id" @click="tab=item.id">{{uiLabel(item.name)}}<span v-if="item.id==='fundingRequests' && vm.options.counts?.pending_incoming" class="badge wallet-funding-count">{{vm.options.counts.pending_incoming}}</span></button></div><div class="actions"><button class="btn" @click="showFilters=true">فلترة وبحث<span v-if="filtersActive"> •</span></button><button v-if="filtersActive" class="btn small" @click="clearFilters">مسح الفلاتر</button><div class="wallet-extra-options"><select v-model="service" aria-label="جميع أنواع المحافظ"><option value="">إجمالي كل الأرصدة</option><option v-for="item in vm.options.services" :value="item.id">{{uiLabel(item.name)}}</option></select><button class="btn small" :disabled="vm.loading.value || vm.busy.value" @click="refresh">تحديث</button></div></div><div class="wallet-service-cards" role="group" aria-label="نوع المحفظة"><button type="button" :aria-pressed="!service" :class="{active:!service}" @click="service=''">إجمالي كل الأرصدة</button><button v-for="item in vm.options.services.filter(row=>['voucher','topup'].includes(row.id))" :key="item.id" type="button" :aria-pressed="service===item.id" :class="{active:service===item.id}" @click="service=item.id">{{item.id==='voucher'?'البطاقات • رصيد تشغيلي':item.id==='topup'?'Topup':item.name}}</button></div></div>

      <div v-if="tab!=='fundingRequests' && service!=='topup'" class="ops-stats wallet-summary-cards"><div data-wallet-metric="current"><span>{{ownTotals?'رصيدي الحالي':'الرصيد الحالي للحسابات المعروضة'}}</span><strong>{{money(totals.current)}} {{vm.currencyLabel(currency)}}</strong></div><div data-wallet-metric="available"><span>{{ownTotals?'رصيدي المتاح':'الرصيد المتاح للحسابات المعروضة'}}</span><strong>{{money(totals.available)}} {{vm.currencyLabel(currency)}}</strong></div><div data-wallet-metric="held"><span>{{ownTotals?'رصيدي المحجوز':'الرصيد المحجوز للحسابات المعروضة'}}</span><strong>{{money(totals.held)}} {{vm.currencyLabel(currency)}}</strong></div><div v-if="ownTotals && vm.identity.value?.account.type!=='pos' && accounts.some(row=>row.id!==vm.ownId.value)" data-wallet-metric="children"><span>أرصدة التابعين المعروضين</span><strong>{{money(totals.children)}} {{vm.currencyLabel(currency)}}</strong></div><div><span>طلبات بانتظار التمويل</span><strong>{{vm.options.counts?.pending_incoming ?? '—'}}</strong></div><div><span>تحويلات منفذة</span><strong>{{vm.options.counts?.transfer_count ?? '—'}}</strong></div><template v-if="vm.options.stock_metrics && (!service || service==='voucher') && ['system','main_agent'].includes(vm.identity.value?.account.type)"><div data-wallet-metric="stock-count"><span>عدد بطاقات مخزون الوكيل الرئيسي</span><strong>{{vm.options.stock_metrics.count}}</strong></div><div v-if="vm.can('data.cost') && vm.options.stock_metrics.cost!==null" data-wallet-metric="stock-cost"><span>تكلفة مخزون الوكيل الرئيسي</span><strong>{{money(vm.options.stock_metrics.cost)}} {{vm.currencyLabel(currency)}}</strong></div><div data-wallet-metric="stock-value"><span>قيمة مخزون البطاقات</span><strong>{{money(vm.options.stock_metrics.value)}} {{vm.currencyLabel(currency)}}</strong></div></template></div><p v-if="vm.balancesError.value" class="notice warn" role="alert">{{vm.balancesError.value}}</p><p v-if="stockError" class="notice warn" role="alert">{{stockError}}</p></div>

      <TopupWallet v-if="service==='topup' && tab==='balances' && vm.can('digital.view')"/><template v-else-if="tab==='balances'"><WalletAccountsTable @movements="account=String($event)" /><div v-if="vm.can('ledger.view')" class="card wallet-movements"><label>حركات الحساب<select v-model="account"><option value="">كل الحسابات</option><option v-for="row in accounts" :key="row.id" :value="String(row.id)">{{row.name}}</option></select></label><p v-if="ledger.state.error" class="notice warn" role="alert">{{ledger.state.error}}</p><p v-if="ledger.state.loading" class="read-loading caption" role="status">جارٍ تحميل الحركات…</p><TablePanel :start="(ledger.state.meta.current_page-1)*25+1" class="tablewrap"><table><thead><tr><th>الوقت</th><th>الحساب</th><th>الحركة</th><th>المبلغ</th><th>المرجع</th></tr></thead><tbody><tr v-for="row in ledger.state.rows" :key="row.id"><td>{{time(row.created_at)}}</td><td>{{row.account_name || vm.name(row.account_id)}}</td><td>{{movementKinds[row.kind] || row.kind}}</td><td :class="{'ops-negative':String(row.amount).startsWith('-')}">{{money(row.amount)}} {{vm.currencyLabel(row.currency)}}</td><td>{{row.reference || '#'+row.transaction_id}}</td></tr><tr v-if="!ledger.state.rows.length && !ledger.state.loading"><td colspan="5" class="empty">لا توجد حركات مطابقة</td></tr></tbody></table></TablePanel><FinancePagination :meta="ledger.state.meta" :busy="ledger.state.loading" @page="loadLedger" /></div></template>

      <SimpleWallets v-if="tab==='fundingRequests'" :quick-deposit="route.query.quick==='deposit'" /><WalletBulk v-if="tab==='bulk'" /><WalletRecovery v-if="tab==='recovery'" /><WalletInvoices v-if="tab==='invoice'" />

    </section><WalletFilterDialog :open="showFilters" @close="showFilters=false" />

  </div>

</template>

<style scoped>

.wallet-filter-toolbar>.actions select{height:40px;min-height:40px;padding-block:5px}

.wallet-extra-options{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wallet-extra-options>summary{cursor:pointer;list-style:none}.wallet-extra-options[open]{position:absolute;z-index:4;inset-inline-end:20px;padding:12px;background:var(--panel);border:1px solid var(--line);border-radius:8px}.wallet-extra-options select{width:80px}

</style>

