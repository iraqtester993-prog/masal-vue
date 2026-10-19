<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();

import { computed,ref,watch,onMounted,onBeforeUnmount,onServerPrefetch,defineAsyncComponent } from 'vue';




import { usePortal } from '../auth/session.js';

import { navIconPath } from '../../layouts/navigation.js';
import {RouterLink} from 'vue-router';

import { createReportApi } from '../reports/report-api.js';

import { formatNumber,errorText } from '../reports/report-model.js';

const DashboardDetails=defineAsyncComponent(()=>import('./DashboardDetails.vue'));
import DashboardActivity from './DashboardActivity.vue';



import {usePreferencesTranslator} from '../preferences/preferences-state.js';

import './dashboard-parity.css';
import {createSalesApi} from '../sales/sales-api.js';
import {createDigitalApi} from '../digital/digital-api.js';
import {dashboardCompanies} from './pos-home.js';
import {dashboardSummaryPath,dashboardReadScope,dashboardCatalogSnapshot,dashboardTopupAvailable} from './dashboard-cache.js';
import {useBalancePrivacy} from '../../shared/composables/balance-privacy.js';
import {requiresNativePrinter} from '../../shared/native-print.js';
import './home-adopted.css';
import {useRouteRefresh} from '../../shared/route-refresh.js';
import {topupCatalogRoute} from '../digital/pos-topup-context.js';

const {session}=usePortal(),api=createReportApi(session.api);

const emptySummary=()=>({cards:[],chart:[],regions:[],currency:'IQD'});
const data=ref(session.api.peekRequest?.(dashboardSummaryPath())?.data ?? emptySummary()),currency=ref('IQD'),busy=ref(false),error=ref(''),detail=ref(null);

const tr=usePreferencesTranslator();
const isPos=computed(()=>session.state.identity?.account.type==='pos');
const {hidden:balancesHidden,toggle:toggleBalances}=useBalancePrivacy();
const shortcuts=computed(()=>[['topupAvailable','Topup','pos','digital.view'],['support','الدعم الفني','support','support.view'],['company','موقع الشركة','company','company.view']].filter(row=>session.can(row[3])));
const PosPrinterSettings=defineAsyncComponent(()=>import('../../layouts/PosPrinterSettings.vue'));
const nativePrinter=ref(false),printerOpen=ref(false);
const cachedCatalog=dashboardCatalogSnapshot(session.api,session.can),catalogProducts=ref(cachedCatalog.products),catalogOffers=ref(cachedCatalog.offers);
const topupAvailable=computed(()=>session.can('digital.view')?dashboardTopupAvailable(catalogOffers.value):null);
const companies=computed(()=>dashboardCompanies(session.can('sell.create')?catalogProducts.value:[],session.can('digital.view')?catalogOffers.value:[])),showAllCompanies=ref(false),companiesLoading=ref(false),companyError=ref(''),brokenImages=ref(new Set());
const balance=computed(()=>data.value.cards.find(row=>row.key==='balance'));
const dailySales=computed(()=>data.value.cards.find(row=>row.key==='sales'));
let catalogController,catalogRevision=0;
async function loadCompanies(fresh=false){
 if(!isPos.value)return;
 catalogController?.abort();catalogController=new AbortController();const own=catalogController,expected=++catalogRevision;companiesLoading.value=true;companyError.value='';
 const result=await Promise.allSettled([
  session.can('sell.create')?createSalesApi(session.api).products(own.signal,{fresh}):Promise.resolve({data:[]}),
  session.can('digital.view')?createDigitalApi(session.api).offers(own.signal,{fresh}):Promise.resolve({data:[]})
 ]);
 if(!alive||expected!==catalogRevision||own.signal.aborted)return;
 if(result[0].status==='fulfilled')catalogProducts.value=result[0].value.data;
 if(result[1].status==='fulfilled')catalogOffers.value=result[1].value.data;
 if(result.some(row=>row.status==='rejected'&&row.reason.name!=='AbortError'))companyError.value='تعذر تحميل بعض الشركات.';
 companiesLoading.value=false;
}
function brokenCompanyImage(url){brokenImages.value=new Set([...brokenImages.value,url]);}

const presentation={mainAgents:{unit:'وكيل',note:'إجمالي المسجلين'},subAgents:{title:'الوكلاء الفرعيون',unit:'وكيل',note:'إجمالي المسجلين'},activeGovernorates:{title:'المحافظات النشطة',note:'المفعلة في النظام'},networkPOS:{icon:'pos',unit:'نقطة',note:'إجمالي المسجلين'},reprints:{note:'بانتظار الإكمال'}};

const cards=computed(()=>data.value.cards.map(item=>{const display=presentation[item.key]||{};return {...item,...Object.fromEntries(Object.entries(display).map(([key,value])=>[key,key==='icon'?value:tr(value)]))};}));

let controller,detailController,detailRevision=0,revision=0,alive=true;
async function load(fresh=false){controller?.abort();controller=new AbortController();const expected=++revision;busy.value=fresh===true||!session.api.hasFreshRequest?.(dashboardSummaryPath(currency.value));error.value='';detail.value=null;detailController?.abort();detailRevision++;

  try{const response=await api.dashboard(currency.value,controller.signal,fresh===true,'cards');if(alive&&expected===revision)data.value=response.data;}

  catch(failure){if(alive&&expected===revision&&failure.name!=='AbortError')error.value=errorText(failure);}

  finally{if(alive&&expected===revision)busy.value=false;}

}


function closeDetails(){detailRevision++;detailController?.abort();detail.value=null;}
async function openDetails(item){
  closeDetails();detailController=new AbortController();const expected=++detailRevision;
  const cached=session.api.peekRequest?.(dashboardSummaryPath(currency.value,'full'))?.data?.cards?.find(row=>row.key===item.key);
  detail.value=cached??{...item,loading:true};
  try{const response=await api.dashboard(currency.value,detailController.signal,false,'full');if(alive&&expected===detailRevision)detail.value=response.data.cards.find(row=>row.key===item.key)??null;}
  catch(failure){if(alive&&expected===detailRevision&&failure.name!=='AbortError'){error.value=errorText(failure);if(!cached)closeDetails();}}
}
watch(()=>dashboardReadScope(session.state.identity),()=>{
 const cached=dashboardCatalogSnapshot(session.api,session.can);catalogProducts.value=cached.products;catalogOffers.value=cached.offers;data.value=session.api.peekRequest?.(dashboardSummaryPath(currency.value))?.data??emptySummary();showAllCompanies.value=false;brokenImages.value=new Set();printerOpen.value=false;closeDetails();
 if(!session.state.identity){revision++;catalogRevision++;controller?.abort();catalogController?.abort();busy.value=false;companiesLoading.value=false;return;}
 load();loadCompanies();
});
useRouteRefresh(async()=>{await Promise.all([load(true),loadCompanies(true)]);return !error.value&&!companyError.value;},{canRefresh:()=>!printerOpen.value&&!detail.value});
onMounted(()=>{nativePrinter.value=requiresNativePrinter();load();loadCompanies();});onServerPrefetch(load);onBeforeUnmount(()=>{alive=false;revision++;controller?.abort();detailController?.abort();catalogController?.abort();detailRevision++;catalogRevision++;});

</script>

<template>
 <div class="dashboard-workspace" :class="{'pos-home-workspace':isPos}">
  <div v-if="error" class="notice warn" role="alert">{{error}} <button class="btn small" @click="load(true)">إعادة المحاولة</button></div>
  <p v-if="busy" class="read-loading help" role="status">{{cards.length?'جارٍ تحديث ملخص الحساب…':'جارٍ تحميل ملخص الحساب…'}}</p>
  <template v-if="isPos">
   <section class="pos-home-hero" :aria-label="uiLabel('ملخص الحساب')" :aria-busy="busy">
    <span class="pos-home-banknote" aria-hidden="true"></span>
    <div class="pos-home-balance-label"><span>{{uiLabel('الرصيد المتاح للبطاقات')}}</span><button type="button" class="pos-home-privacy" :aria-label="uiLabel(balancesHidden?'إظهار الأرصدة':'إخفاء الأرصدة')" :aria-pressed="balancesHidden" @click="toggleBalances"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path v-if="balancesHidden" d="m3 3 18 18"/></svg></button></div>
    <div class="pos-home-main-balance"><strong>{{balancesHidden?'••••••':balance?.value==null?'—':formatNumber(balance.value)}}</strong><small>د.ع</small></div>
    <div class="pos-home-hero-metrics">
     <div><span>{{uiLabel('مبيعات البطاقات اليوم')}}</span><strong>{{balancesHidden?'••••••':dailySales?.value==null?'—':formatNumber(dailySales.value)}} <small>د.ع</small></strong></div>
     <div v-if="session.can('digital.view')"><span>{{uiLabel('رصيد Topup المتاح')}}</span><strong>{{balancesHidden?'••••••':topupAvailable==null?'—':formatNumber(topupAvailable)}} <small>د.ع</small></strong></div>
    </div>
    <div v-if="session.can('sell.create')||session.can('wallets.view')" class="pos-home-hero-actions">
     <RouterLink v-if="session.can('sell.create')" :to="{name:'sell'}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{uiLabel('بيع بطاقة')}}</RouterLink>
     <RouterLink v-if="session.can('wallets.view')" :to="{name:'wallets',query:{quick:'funding'}}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="navIconPath('wallets')"/></svg>{{uiLabel('طلب تمويل')}}</RouterLink>
    </div>
   </section>
   <div class="pos-home-quickkeys"><RouterLink v-for="item in shortcuts" :key="item[0]" :to="item[0]==='topupAvailable'?topupCatalogRoute():{name:item[0],query:item[0]==='support'?{quick:'support'}:{}}"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="navIconPath(item[2])"/></svg></span><b>{{uiLabel(item[1])}}</b></RouterLink><button v-if="nativePrinter" type="button" @click="printerOpen=true"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6Z"/></svg></span><b>{{uiLabel('الطابعة')}}</b></button></div>
   <section v-if="session.can('sell.create')||session.can('digital.view')" class="pos-home-companies">
    <header><h2>{{uiLabel('الشركات')}}</h2><button v-if="companies.length" type="button" class="pos-text-action" :aria-expanded="showAllCompanies" @click="showAllCompanies=!showAllCompanies">{{uiLabel(showAllCompanies?'عرض أقل':'عرض الكل')}}</button></header>
    <p v-if="companyError" class="help" role="status">{{uiLabel(companyError)}}</p>
    <div class="pos-company-strip" :class="{'is-expanded':showAllCompanies}" :aria-label="uiLabel('الشركات')">
     <RouterLink v-for="company in companies" :key="company.key" :to="company.provider==='topup'?topupCatalogRoute():{name:session.can('sell.create')?'sell':'digital',query:session.can('sell.create')?{company:company.key}:{provider:company.provider}}" class="pos-home-company" :aria-label="company.name" :title="company.name"><span class="pos-company-circle"><img v-if="company.image&&!brokenImages.has(company.image)" :src="company.image" alt="" loading="lazy" @error="brokenCompanyImage(company.image)"><span v-else aria-hidden="true">{{Array.from(company.name||'')[0]||'•'}}</span></span><span class="pos-company-name">{{company.name}}</span></RouterLink>
    </div>
    <p v-if="!companies.length" class="help" role="status">{{uiLabel(companiesLoading?'جارٍ تحميل الشركات…':'لا توجد فئات متاحة للبيع')}}</p>
   </section>
  </template>
  <section v-else class="metrics dashboard-kpis" aria-label="ملخص الحساب"><article v-for="item in cards" :key="item.key" class="card dashboard-kpi" :data-kpi="item.key"><div class="dashboard-kpi-head"><span>{{uiLabel(item.title)}}</span><span class="dashboard-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path :d="navIconPath(item.icon)"/></svg></span></div><div v-if="item.balanceRows" class="dashboard-company-balances"><span class="caption">الرصيد المتبقي لدى الشركة</span><div class="dashboard-kpi-number"><strong>{{item.remaining==null?'لم يُجلب بالكامل':formatNumber(item.remaining)}}</strong><small>د.ع</small></div></div><div v-else class="dashboard-kpi-number"><strong>{{formatNumber(item.value)}}</strong><small>{{uiLabel(item.unit)}}</small></div><div class="dashboard-kpi-footer"><span v-if="item.key!=='digital:topup'" class="caption">{{uiLabel(item.note)}}</span><div class="dashboard-kpi-action"><button class="btn small" :disabled="busy" @click="openDetails(item)">عرض التفاصيل</button></div></div></article></section>
  <DashboardActivity :compact="isPos" :operations="data.recent_operations || []" :busy="busy"/>
  <PosPrinterSettings v-if="printerOpen" @close="printerOpen=false"/>
  <DashboardDetails v-if="detail" :detail="detail" :currency="currency" @close="closeDetails"/>
 </div>
</template>
