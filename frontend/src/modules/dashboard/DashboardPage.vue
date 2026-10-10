<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();

import { computed,ref,onMounted,onBeforeUnmount,onServerPrefetch } from 'vue';




import { usePortal } from '../auth/session.js';

import { navIconPath } from '../../layouts/navigation.js';

import { createReportApi } from '../reports/report-api.js';

import { formatNumber,errorText } from '../reports/report-model.js';

import DashboardDetails from './DashboardDetails.vue';
import DashboardActivity from './DashboardActivity.vue';



import {usePreferencesTranslator} from '../preferences/preferences-state.js';

import './dashboard-parity.css';

const {session}=usePortal(),api=createReportApi(session.api);

const data=ref(session.api.peekRequest?.('/dashboard/summary?currency=IQD',120000)?.data ?? {cards:[],chart:[],regions:[],currency:'IQD'}),currency=ref('IQD'),busy=ref(false),error=ref(''),detail=ref(null);

const tr=usePreferencesTranslator();

const presentation={mainAgents:{unit:'وكيل',note:'إجمالي المسجلين'},subAgents:{title:'الوكلاء الفرعيون',unit:'وكيل',note:'إجمالي المسجلين'},activeGovernorates:{title:'المحافظات النشطة',note:'المفعلة في النظام'},networkPOS:{icon:'pos',unit:'نقطة',note:'إجمالي المسجلين'},reprints:{note:'بانتظار الإكمال'}};

const cards=computed(()=>data.value.cards.map(item=>{const display=presentation[item.key]||{};return {...item,...Object.fromEntries(Object.entries(display).map(([key,value])=>[key,key==='icon'?value:tr(value)]))};}));

let controller,revision=0,alive=true;
async function load(fresh=false){controller?.abort();controller=new AbortController();const expected=++revision;busy.value=true;error.value='';detail.value=null;

  try{const response=await api.dashboard(currency.value,controller.signal,fresh===true);if(alive&&expected===revision)data.value=response.data;}

  catch(failure){if(alive&&expected===revision&&failure.name!=='AbortError')error.value=errorText(failure);}

  finally{if(alive&&expected===revision)busy.value=false;}

}


onMounted(load);onServerPrefetch(load);onBeforeUnmount(()=>{alive=false;revision++;controller?.abort();});

</script>

<template><div class="dashboard-workspace"><div v-if="error" class="notice warn" role="alert">{{error}} <button class="btn small" @click="load(true)">إعادة المحاولة</button></div><p v-if="busy" class="read-loading help" role="status">{{cards.length?'جارٍ تحديث ملخص الحساب…':'جارٍ تحميل ملخص الحساب…'}}</p><section class="metrics dashboard-kpis" aria-label="ملخص الحساب"><article v-for="item in cards" :key="item.key" class="card dashboard-kpi" :data-kpi="item.key"><div class="dashboard-kpi-head"><span>{{uiLabel(item.title)}}</span><span class="dashboard-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path :d="navIconPath(item.icon)"/></svg></span></div><div v-if="item.balanceRows && session.state.identity?.account.type!=='pos'" class="dashboard-company-balances"><span class="caption">الرصيد المتبقي لدى الشركة</span><div class="dashboard-kpi-number"><strong>{{item.remaining==null?'لم يُجلب بالكامل':formatNumber(item.remaining)}}</strong><small>د.ع</small></div></div><div v-else class="dashboard-kpi-number"><strong>{{formatNumber(item.value)}}</strong><small>{{uiLabel(item.unit)}}</small></div><div class="dashboard-kpi-footer"><span v-if="item.key!=='digital:topup'" class="caption">{{uiLabel(item.note)}}</span><div class="dashboard-kpi-action"><button class="btn small" :disabled="busy" @click="detail=item">عرض التفاصيل</button></div></div></article></section><DashboardActivity :operations="data.recent_operations || []" :busy="busy"/><DashboardDetails v-if="detail" :detail="detail" :currency="currency" @close="detail=null"/></div></template>



