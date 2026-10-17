<script setup>
import {computed,inject,onMounted,onBeforeUnmount,ref} from 'vue';
import FinancePagination from './FinancePagination.vue';
import {financeKey,money} from './finance-model.js';
import {topupWalletMetrics} from './topup-funding.js';
import {usePreferencesTranslator} from '../preferences/preferences-state.js';
import {useDigitalRuntime} from '../digital/digital-runtime.js';
import DigitalLog from '../digital/DigitalLog.vue';
import DigitalCategoryDetails from '../digital/DigitalCategoryDetails.vue';
import DigitalReceipt from '../digital/DigitalReceipt.vue';
import '../digital/digital.css';
const finance=inject(financeKey),vm=useDigitalRuntime(),details=ref(null),log=ref(null);
const uiLabel=usePreferencesTranslator();
const metrics=computed(()=>topupWalletMetrics(finance.options.wallet_summary,finance.options.topup_summary,{own:!finance.admin.value&&finance.ownVisible?.value!==false&&!finance.filtersActive?.value,group:['system','main_agent'].includes(finance.identity.value?.account.type),costs:finance.can('data.cost'),profits:finance.can('data.profit')&&finance.can('data.cost')}));
let reader,revision=0;
async function load(page=vm.state.connectionsMeta.current_page){
 if(!vm.can('digital.view'))return;
 reader?.abort();reader=new AbortController();const expected=++revision;vm.state.loading=true;vm.state.error='';
 try{const result=await vm.api.connections({provider:'topup',page,per_page:25},reader.signal);if(expected!==revision)return;vm.state.connections=result.data;vm.state.connectionsMeta=result.meta;vm.state.options.accounts=finance.options.accounts;vm.state.options.products=[...new Map(result.data.flatMap(row=>row.offers||[]).map(row=>[row.product_id,{id:row.product_id,name:row.name}])).values()];}
 catch(error){if(error.name!=='AbortError'&&expected===revision)vm.state.error=await vm.failure(error);}
 finally{if(expected===revision)vm.state.loading=false;}
}
onBeforeUnmount(()=>{revision++;reader?.abort();});
vm.load=load;
onMounted(load);
</script>
<template>
 <section class="topup-wallet">
  <p v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</p>
  <section v-if="finance.can('wallets.view')" class="card topup-funded-balances">
   <h3>رصيد Topup الممول</h3>
   <dl class="topup-wallet-metrics"><div v-for="metric in metrics" :key="metric.id" :data-topup-metric="metric.id"><dt>{{uiLabel(metric.label)}}</dt><dd>{{money(metric.value)}} <small v-if="metric.value!=null">د.ع</small></dd></div></dl>
  </section>
  <section v-if="vm.can('digital.view')" class="card topup-wallet-balances" :aria-busy="vm.state.loading">
   <h3>فئات Topup الممنوحة</h3>
   <p v-if="vm.state.loading" class="caption" role="status">جارٍ تحميل الأرصدة…</p>
   <article v-for="row in vm.state.connections" :key="row.id" class="topup-wallet-account">
    <header class="topup-wallet-account-header">
     <strong>{{row.account_name}}</strong>
     <div class="topup-wallet-account-actions">
      <span class="badge">{{row.effective_offer_ids?.length ?? row.offers?.length ?? 0}} فئة</span>
      <button type="button" class="btn small" @click="details=row">عرض تفاصيل الفئات</button>
     </div>
    </header>
   </article>
   <p v-if="!vm.state.loading&&!vm.state.connections.length" class="empty">لا يوجد ربط Topup متاح ضمن نطاقك.</p>
   <FinancePagination :meta="vm.state.connectionsMeta" :busy="vm.state.loading" @page="load"/>
  </section>
  <DigitalCategoryDetails v-if="details" :connection="details" @close="details=null"/>
  <DigitalLog v-if="vm.can('digital.view')" ref="log" wallet-filters class="topup-wallet-log" :vm="vm" :route-filters="{provider:'topup',main_account_id:finance.filters.network,account_id:finance.filters.account,from:finance.filters.from,to:finance.filters.to,q:finance.filters.reference}"/>
  <DigitalReceipt v-if="vm.receipt.value" :vm="vm" :receipt="vm.receipt.value" @close="vm.clearReceipt"/>
 </section>
</template>

<style>
.finance-workspace .topup-wallet{display:grid;gap:18px;min-width:0}
.finance-workspace .topup-wallet-balances h3{margin:0 0 16px}
.finance-workspace .topup-wallet-account{border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:12px;min-width:0}
.finance-workspace .topup-wallet-account-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.finance-workspace .topup-wallet-account-header>strong{overflow-wrap:anywhere}
.finance-workspace .topup-wallet-account-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.finance-workspace .topup-wallet-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:0}
.finance-workspace .topup-wallet-metrics>div{background:var(--raised);border-radius:10px;padding:12px 14px;min-width:0}
.finance-workspace .topup-wallet-metrics dt{color:var(--muted);font-size:13px;margin-bottom:8px}
.finance-workspace .topup-wallet-metrics dd{margin:0;color:var(--text);font-size:21px;font-weight:800;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.finance-workspace .topup-wallet-metrics small{font-size:12px;font-weight:600;color:var(--muted)}
.finance-workspace .topup-wallet-log>.toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding:0;margin:0 0 16px;background:transparent;border:0}
.finance-workspace .topup-wallet-log>.toolbar h3{margin:0}
.finance-workspace .topup-wallet-log>.toolbar .actions{display:flex;flex-wrap:wrap;gap:8px;width:auto;margin:0}
.finance-workspace .topup-wallet-log .filter-bar{display:flex;flex-direction:column;align-items:stretch;gap:10px;margin-bottom:16px}
.finance-workspace .topup-wallet-log .filter-toggle{align-self:flex-start}
.finance-workspace .topup-wallet-log .digital-filters{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:10px;margin:0;width:100%}
.finance-workspace .topup-wallet-log .digital-filters label{display:grid;gap:6px;min-width:0;margin:0;font-size:12px;color:var(--muted)}
.finance-workspace .topup-wallet-log .digital-filters input,.finance-workspace .topup-wallet-log .digital-filters select{width:100%;min-width:0;height:44px}
.finance-workspace .topup-wallet-log td{vertical-align:top}
.finance-workspace .topup-wallet-log td small{display:block;color:var(--muted);margin-top:6px;max-width:260px;overflow-wrap:anywhere}
.finance-workspace .topup-wallet-log td .mono{overflow-wrap:anywhere}
.finance-workspace .topup-wallet-log td .actions{display:flex;flex-wrap:wrap;gap:6px}
.finance-workspace .topup-wallet-log .digital-totals>div{min-width:0}
.finance-workspace .topup-wallet-log .formfoot{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:16px}
@media(max-width:1100px){.finance-workspace .topup-wallet-log .digital-filters{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:650px){
 .finance-workspace .topup-wallet-account{padding:12px}
 .finance-workspace .topup-wallet-account-header{align-items:flex-start;flex-direction:column}
 .finance-workspace .topup-wallet-account-actions{width:100%;justify-content:space-between}
 .finance-workspace .topup-wallet-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
 .finance-workspace .topup-wallet-metrics dd{font-size:18px}
 .finance-workspace .topup-wallet-log .digital-filters{grid-template-columns:repeat(2,minmax(0,1fr))}
 .finance-workspace .topup-wallet-log>.toolbar .actions{width:100%}
 .finance-workspace .topup-wallet-log>.toolbar .actions .btn{flex:1 1 auto}
}
</style>
