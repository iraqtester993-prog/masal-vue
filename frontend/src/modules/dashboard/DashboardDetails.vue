<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

import {statusLabels} from '../preferences/system-labels.js';
import { computed,ref,onBeforeUnmount } from 'vue';
import DigitalCategoryDetails from '../digital/DigitalCategoryDetails.vue';
import { errorText } from '../reports/report-model.js';
import { useRouter } from 'vue-router';
import { usePortal } from '../auth/session.js';
import ReportModal from '../reports/ReportModal.vue';
import { balanceTime as time } from './dashboard-time.js';
import { formatNumber } from '../reports/report-model.js';
import { allowedReportRoute } from '../reports/report-navigation.js';
const props=defineProps({detail:{type:Object,required:true},currency:{type:String,default:'IQD'}}),emit=defineEmits(['close']),{session}=usePortal(),router=useRouter();
const costVisible=computed(()=>session.state.identity?.account.type!=='pos'&&session.can('data.cost'));
const destination=computed(()=>props.detail.available?allowedReportRoute(router.getRoutes(),props.detail.destination,session.state.identity,session.can):null);
const digitalRoute=computed(()=>allowedReportRoute(router.getRoutes(),'digital',session.state.identity,session.can));
const categoryConnection=ref(null),categoryLoading=ref(false),categoryError=ref('');
const controller=new AbortController();let revision=0;
const canCategories=computed(()=>session.can('digital.view')||session.can('integrations.view'));
const agentQuery=ref(''),agentPage=ref(1);
const agents=computed(()=>(props.detail.balanceRows||[]).filter(row=>row.name.toLocaleLowerCase().includes(agentQuery.value.trim().toLocaleLowerCase())));
const agentPages=computed(()=>Math.max(1,Math.ceil(agents.value.length/25)));
const shownAgents=computed(()=>agents.value.slice((agentPage.value-1)*25,agentPage.value*25));
async function showCategories(row){
  const current=++revision;categoryLoading.value=true;categoryError.value='';categoryConnection.value=null;
  try{const result=await session.api.request('/digital/connections?'+new URLSearchParams({provider:'topup',main_account_id:row.agent}),{signal:controller.signal});if(current===revision){categoryConnection.value=result.data[0]||null;if(!categoryConnection.value)categoryError.value='لا يوجد ربط متاح لهذا الوكيل.';}}
  catch(error){if(current===revision&&error.name!=='AbortError')categoryError.value=errorText(error);}
  finally{if(current===revision)categoryLoading.value=false;}
}
onBeforeUnmount(()=>{revision++;controller.abort();});
function navigate(query=props.detail.parameters,route=destination.value){if(route){router.push({name:route.name,query:{...query,currency:props.currency}});emit('close');}}
</script>
<template><ReportModal :title="uiLabel(detail.title)" @close="emit('close')"><div class="notice"><b>{{uiLabel(detail.title)}}: {{formatNumber(detail.value)}} {{uiLabel(detail.unit)}}</b><p>{{uiLabel(detail.note)}}</p></div><p v-if="detail.explanation">{{uiLabel(detail.explanation)}}</p><template v-if="detail.balanceRows&&session.state.identity?.account.type!=='pos'"><FilterBar class="toolbar"><input v-model="agentQuery" type="search" aria-label="بحث الوكلاء" placeholder="بحث باسم الوكيل" @input="agentPage=1"><span class="badge">{{agents.length}} وكيل</span></FilterBar><TablePanel class="tablewrap"><table><thead><tr><th>الوكيل</th><th>الرصيد المتبقي لدى الشركة</th><th>آخر جلب</th><th v-if="costVisible">المبلغ المصروف</th><th>تفاصيل الوكيل</th></tr></thead><tbody><tr v-for="row in shownAgents" :key="row.agent"><td>{{row.name}}</td><td>{{row.remaining===null||row.remaining===undefined?'لم يُجلب بعد':formatNumber(row.remaining)+' د.ع'}}</td><td>{{row.updatedAt?time(row.updatedAt):'—'}}</td><td v-if="costVisible">{{formatNumber(row.spent)}} د.ع</td><td><div class="actions"><button v-if="canCategories" class="btn small" :disabled="categoryLoading" @click="showCategories(row)">الفئات وتفاصيلها</button><a v-if="session.state.identity?.account.type==='system'&&session.state.identity?.membership.kind==='owner'&&session.can('integrations.edit')" class="btn small" :href="'/topup/allocation?target_account_id='+row.agent">تخصيص الفئات</a><button v-if="digitalRoute" class="btn small" @click="navigate({provider:'topup',main_account_id:row.agent,tab:'log'},digitalRoute)">عرض حساب الوكيل</button></div></td></tr><tr v-if="!shownAgents.length"><td :colspan="costVisible?5:4" class="empty">لا يوجد وكلاء مربوطون بخدمة Topup</td></tr></tbody></table></TablePanel><div v-if="agentPages>1" class="table-pagination-footer"><button class="btn small" :disabled="agentPage<=1" @click="agentPage--">السابق</button><span>{{agentPage}} / {{agentPages}}</span><button class="btn small" :disabled="agentPage>=agentPages" @click="agentPage++">التالي</button></div><p v-if="detail.balance_rows_total>detail.balanceRows.length" class="caption">عرض أول {{detail.balanceRows.length}} حسابات؛ بقية التفاصيل في صفحة ربط الشركات وتخصيص الفئات.</p></template><template v-else><TablePanel class="tablewrap"><table><thead><tr><th>البيان</th><th>القيمة</th><th>الحالة</th></tr></thead><tbody><tr v-for="(row,index) in detail.rows" :key="index"><td>{{row.name}}</td><td>{{formatNumber(row.value)}} {{uiLabel(row.unit)}}</td><td>{{statusLabels[row.note]||row.note||'—'}}</td></tr><tr v-if="!detail.rows.length"><td colspan="3" class="empty">{{detail.available?'لا توجد بيانات':'المصدر غير متاح لهذا الحساب.'}}</td></tr></tbody></table></TablePanel><p v-if="detail.rows_total>5" class="caption">عرض 5 سجلات؛ بقية التفاصيل في الصفحة الأصلية.</p></template><p v-if="categoryLoading" class="help" role="status">جارٍ تحميل فئات الوكيل…</p><p v-if="categoryError" class="notice warn" role="alert">{{categoryError}}</p><DigitalCategoryDetails v-if="categoryConnection" :key="categoryConnection.id" :connection="categoryConnection" @close="categoryConnection=null"/><div class="formfoot"><button class="btn" @click="emit('close')">إغلاق</button><button v-if="destination" class="btn primary" @click="navigate()">الانتقال إلى الصفحة</button></div></ReportModal></template>
