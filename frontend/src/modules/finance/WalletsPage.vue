<script setup>
import {usePreferencesTranslator} from "../preferences/preferences-state.js";
const uiLabel=usePreferencesTranslator();
import AccountPicker from './AccountPicker.vue';
import {accountParameters} from './finance-api.js';
import TopupWallet from './TopupWallet.vue';
import PosWalletSummary from './PosWalletSummary.vue';
import {posWalletValues} from './pos-wallet-summary.js';
import {useBalancePrivacy} from '../../shared/composables/balance-privacy.js';
import {navIconPath} from '../../layouts/navigation.js';
import TablePanel from '../../shared/components/TablePanel.vue';


import {useRoute} from 'vue-router';

const route=useRoute();

import { computed, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';

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
import './wallet-layout.css';
import './pos-wallet.css';

const vm = useFinanceRuntime({balances:true}), service = ref('voucher'), currency = ref('IQD'), tab = ref('balances'), account = ref(''), showFilters = ref(false);

const filters = reactive(emptyFilters()), ledger = vm.resource('ledger');
const isPos=computed(()=>vm.identity.value?.account.type==='pos'),movementPanel=ref(null),posSummaries=reactive({}),{hidden:balancesHidden}=useBalancePrivacy();
const posSummaryScope=computed(()=>JSON.stringify([vm.ownId.value,currency.value,filters,vm.identity.value?.membership,vm.identity.value?.permissions]));
function summaryParameters(selected=service.value){return {service:selected,currency:currency.value,...accountParameters(filters),per_page:1,summary:1};}
function hydratePosSummaries(){if(!isPos.value)return;for(const id of ['voucher','topup']){const cached=vm.api.peekList('wallets',summaryParameters(id));if(cached?.summary)posSummaries[id]={scope:posSummaryScope.value,summary:cached.summary};}}
const posWalletSummaries=computed(()=>Object.fromEntries(['voucher','topup'].map(id=>[id,posWalletValues(posSummaries[id]?.scope===posSummaryScope.value?posSummaries[id].summary:null,vm.ownVisible.value&&!filtersActive.value)])));
const canPosFunding=computed(()=>isPos.value&&vm.ownVisible.value&&!!service.value&&vm.can('wallets.request')&&!!(service.value==='topup'?vm.options.topup_funder_account_id:vm.options.parent_account_id));
const otherPosServices=computed(()=>vm.options.services.some(item=>!['voucher','topup'].includes(item.id)));
async function showMovements(){tab.value='balances';await nextTick();movementPanel.value?.scrollIntoView?.({behavior:'smooth',block:'start'});}
function movementAmount(value){return balancesHidden.value&&value!==null&&value!==undefined?'••••••':money(value);}

const stockError=ref(''),stockLoading=ref(false);let stockController,stockRevision=0;

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

  const summary=vm.options.wallet_summary;
  if(!summary || stockError.value) return {current:null,available:null,held:null,children:null};
  return {current:summary[ownTotals.value?'own_balance':'balance'],available:summary[ownTotals.value?'own_available':'available'],held:summary[ownTotals.value?'own_held':'held'],children:summary.children};
});

function recordParameters(extra = {}) {

  return {service:service.value,currency:currency.value,...accountParameters(filters),q:filters.reference,from:filters.from,to:filters.to,...extra};

}

function emptyScope() { return false; }

async function loadLedger(page = 1) {

  if (!vm.can('ledger.view')) return;

  if (emptyScope()) { ledger.dispose(); ledger.state.meta={current_page:1,last_page:1,total:0}; return; }

  await ledger.load(recordParameters({ account_id: account.value || filters.account, per_page:25, page }));

}

async function loadStockMetrics(){

  stockController?.abort();const expected=++stockRevision;vm.options.stock_metrics=null;vm.options.wallet_summary=null;vm.options.topup_summary=null;stockError.value='';stockLoading.value=false;

  if(!vm.can('wallets.view') || emptyScope())return;

  stockController=new AbortController();stockLoading.value=true;



  const selected=service.value,scope=posSummaryScope.value;
  try{const result=await vm.api.list('wallets',summaryParameters(selected),stockController.signal);if(expected===stockRevision){vm.options.stock_metrics=result.stock_metrics ?? null;vm.options.wallet_summary=result.summary ?? null;vm.options.topup_summary=result.topup_summary ?? null;if(isPos.value&&scope===posSummaryScope.value&&['voucher','topup'].includes(selected))posSummaries[selected]={scope,summary:result.summary ?? null};}}

  catch(failed){if(failed.name!=='AbortError' && expected===stockRevision){if(isPos.value)delete posSummaries[selected];stockError.value=await vm.failure(failed);}}
  finally{if(expected===stockRevision)stockLoading.value=false;}

}

async function refresh(fresh = true) { if (fresh) {vm.session.api.clearReadCache?.();Object.keys(posSummaries).forEach(id=>delete posSummaries[id]);}else hydratePosSummaries(); if(filtersActive.value){await vm.loadOptions();await Promise.all([loadLedger(),loadStockMetrics()]);}else{await Promise.all([vm.loadOptions(),loadLedger(),loadStockMetrics()]);} }

function clearFilters() { Object.assign(filters,emptyFilters()); account.value=''; }

const context = { ...vm, service, currency, filters, filtersActive, accounts, showFilters, recordParameters, emptyScope, refresh, clearFilters };

provide(financeKey, context);

watch([service,currency], () => {loadLedger();loadStockMetrics();});watch(account,()=>loadLedger());

watch(filters, () => {account.value='';loadLedger();loadStockMetrics();}, {deep:true});
watch(posSummaryScope,()=>{Object.keys(posSummaries).forEach(id=>delete posSummaries[id]);hydratePosSummaries();},{immediate:true});

function quickDeposit(){if(route.query.quick==='funding'||route.query.quick==='deposit' && vm.admin.value && vm.can('wallets.deposit'))tab.value='fundingRequests';}

watch(()=>route.query.quick,quickDeposit);

onMounted(async()=>{await refresh(false);quickDeposit();});

onBeforeUnmount(()=>{stockRevision++;stockController?.abort();});

</script>

<template>

  <div class="finance-workspace operations-panel wallet-layout" :class="{'pos-wallet-page':isPos}">

    <p v-if="vm.error.value" class="notice warn" role="alert">{{vm.error.value}}</p><p v-if="vm.notice.value" class="notice" role="status">{{vm.notice.value}}</p><p v-if="vm.loading.value" class="read-loading caption" role="status">جارٍ تحميل المحافظ…</p>

    <PosWalletSummary v-if="isPos&&vm.can('wallets.view')" :services="vm.options.services" :summaries="posWalletSummaries" :selected="service" :loading="vm.loading.value||stockLoading" :filtered="filtersActive" @select="service=$event"/>
    <div v-if="isPos" class="pos-wallet-context-actions"><button v-if="canPosFunding" type="button" class="btn primary" @click="tab='fundingRequests'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{uiLabel(service==='topup'?'طلب تمويل Topup':'طلب تمويل البطاقات')}}</button><button v-if="vm.can('ledger.view')" type="button" class="btn" @click="showMovements"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="navIconPath('wallets')"/></svg>{{uiLabel('الحركات')}}</button></div>
    <section class="wallet-advanced"><div class="card"><div class="wallet-filter-toolbar">
        <div class="tabs wallet-navigation-tabs"><button v-for="item in tabs" :key="item.id" :class="{active:tab===item.id}" :aria-pressed="tab===item.id" @click="tab=item.id">{{uiLabel(item.name)}}<span v-if="item.id==='fundingRequests' && vm.options.counts?.pending_incoming" class="badge wallet-funding-count">{{vm.options.counts.pending_incoming}}</span></button></div>
        <div class="wallet-control-row">
          <div v-if="!isPos" class="wallet-service-cards" role="group" aria-label="نوع المحفظة">
            <button type="button" :aria-pressed="!service" :class="{active:!service}" @click="service=''">إجمالي كل الأرصدة</button>
            <button v-for="item in vm.options.services" :key="item.id" type="button" :aria-pressed="service===item.id" :class="{active:service===item.id}" @click="service=item.id">{{item.id==='voucher'?'البطاقات • رصيد تشغيلي':item.id==='topup'?'Topup':item.name}}</button>
          </div>
          <label v-else-if="otherPosServices" class="pos-wallet-other-services">{{uiLabel('نوع المحفظة')}}<select v-model="service" aria-label="نوع المحفظة"><option value="">إجمالي كل الأرصدة</option><option v-for="item in vm.options.services" :key="item.id" :value="item.id">{{item.id==='voucher'?'محفظة البطاقات':item.id==='topup'?'محفظة Topup':item.name}}</option></select></label>
          <div class="actions wallet-page-actions">
            <button class="btn" @click="showFilters=true">فلترة وبحث<span v-if="filtersActive"> •</span></button>
            <button v-if="filtersActive" class="btn small" @click="clearFilters">مسح الفلاتر</button>
            <button class="btn small" :disabled="vm.loading.value || vm.busy.value" @click="refresh">تحديث</button>
          </div>
        </div>
      </div>

      <div v-if="tab==='balances' && service!=='topup' && (!isPos||!['voucher','topup'].includes(service))" class="ops-stats wallet-summary-cards"><div data-wallet-metric="current"><span>{{ownTotals?'رصيدي الحالي':'الرصيد الحالي للحسابات المعروضة'}}</span><strong>{{money(totals.current)}} {{vm.currencyLabel(currency)}}</strong></div><div data-wallet-metric="available"><span>{{ownTotals?'رصيدي المتاح':'الرصيد المتاح للحسابات المعروضة'}}</span><strong>{{money(totals.available)}} {{vm.currencyLabel(currency)}}</strong></div><div data-wallet-metric="held"><span>{{ownTotals?'رصيدي المحجوز':'الرصيد المحجوز للحسابات المعروضة'}}</span><strong>{{money(totals.held)}} {{vm.currencyLabel(currency)}}</strong></div><div v-if="ownTotals && vm.identity.value?.account.type!=='pos' && accounts.some(row=>row.id!==vm.ownId.value)" data-wallet-metric="children"><span>أرصدة التابعين المعروضين</span><strong>{{money(totals.children)}} {{vm.currencyLabel(currency)}}</strong></div><div><span>طلبات بانتظار التمويل</span><strong>{{vm.options.counts?.pending_incoming ?? '—'}}</strong></div><div><span>تحويلات منفذة</span><strong>{{vm.options.counts?.transfer_count ?? '—'}}</strong></div><template v-if="vm.options.stock_metrics && (!service || service==='voucher') && ['system','main_agent'].includes(vm.identity.value?.account.type)"><div data-wallet-metric="stock-count"><span>عدد بطاقات مخزون الوكيل الرئيسي</span><strong>{{vm.options.stock_metrics.count}}</strong></div><div v-if="vm.can('data.cost') && vm.options.stock_metrics.cost!==null" data-wallet-metric="stock-cost"><span>تكلفة مخزون الوكيل الرئيسي</span><strong>{{money(vm.options.stock_metrics.cost)}} {{vm.currencyLabel(currency)}}</strong></div><div data-wallet-metric="stock-value"><span>قيمة مخزون البطاقات</span><strong>{{money(vm.options.stock_metrics.value)}} {{vm.currencyLabel(currency)}}</strong></div></template></div><p v-if="vm.balancesError.value" class="notice warn" role="alert">{{vm.balancesError.value}}</p><p v-if="stockError" class="notice warn" role="alert">{{stockError}}</p></div>

      <template v-if="tab==='balances'"><TopupWallet v-if="service==='topup' && (vm.can('digital.view') || vm.can('wallets.view'))"/><WalletAccountsTable v-if="service!=='topup'&&(!isPos||filtersActive||accounts.some(row=>Number(row.id)!==vm.ownId.value)||!vm.ownVisible.value)" @movements="account=String($event)" /><div v-if="vm.can('ledger.view')" ref="movementPanel" class="card wallet-movements"><h3 v-if="isPos">{{uiLabel('حركات المحفظة')}}</h3><label>حركات الحساب<AccountPicker v-model="account" :vm="vm" :parameters="accountParameters(filters)" label="حركات الحساب"/></label><p v-if="ledger.state.error" class="notice warn" role="alert">{{ledger.state.error}}</p><p v-if="ledger.state.loading" class="read-loading caption" role="status">جارٍ تحميل الحركات…</p><div v-if="isPos" class="pos-wallet-movement-list"><article v-for="row in ledger.state.rows" :key="row.id" class="pos-wallet-movement"><span class="pos-wallet-movement-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path :d="String(row.amount).startsWith('-')?'M7 17 17 7M7 7h10v10':'M17 7 7 17M7 7v10h10'"/></svg></span><div class="pos-wallet-movement-text"><b>{{uiLabel(movementKinds[row.kind]||row.kind)}}</b><small>{{time(row.created_at)}} · {{row.reference||'#'+row.transaction_id}}</small><small v-if="row.account_name&&Number(row.account_id)!==vm.ownId.value">{{row.account_name}}</small></div><strong class="pos-wallet-movement-amount mono">{{movementAmount(row.amount)}}<small>{{vm.currencyLabel(row.currency)}}</small></strong></article><p v-if="!ledger.state.rows.length&&!ledger.state.loading" class="empty">{{uiLabel('لا توجد حركات مطابقة')}}</p></div><TablePanel v-else :start="(ledger.state.meta.current_page-1)*25+1" class="tablewrap"><table><thead><tr><th>الوقت</th><th>الحساب</th><th>الحركة</th><th>المبلغ</th><th>المرجع</th></tr></thead><tbody><tr v-for="row in ledger.state.rows" :key="row.id"><td>{{time(row.created_at)}}</td><td>{{row.account_name || vm.name(row.account_id)}}</td><td>{{movementKinds[row.kind] || row.kind}}</td><td :class="{'ops-negative':String(row.amount).startsWith('-')}">{{money(row.amount)}} {{vm.currencyLabel(row.currency)}}</td><td>{{row.reference || '#'+row.transaction_id}}</td></tr><tr v-if="!ledger.state.rows.length && !ledger.state.loading"><td colspan="5" class="empty">لا توجد حركات مطابقة</td></tr></tbody></table></TablePanel><FinancePagination :meta="ledger.state.meta" :busy="ledger.state.loading" @page="loadLedger" /></div></template>

      <SimpleWallets v-if="tab==='fundingRequests'" :quick-deposit="route.query.quick==='deposit'" /><WalletBulk v-if="tab==='bulk'" /><WalletRecovery v-if="tab==='recovery'" /><WalletInvoices v-if="tab==='invoice'" />

    </section><WalletFilterDialog :open="showFilters" @close="showFilters=false" />

  </div>

</template>
