<script setup>
import {RouterLink} from "vue-router";
import {usePreferencesTranslator} from "../preferences/preferences-state.js";
const uiLabel=usePreferencesTranslator();
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';


import {computed,onBeforeUnmount,onMounted,ref} from 'vue';

import {useRoute} from 'vue-router';
import {usePortal} from '../auth/session.js';

import {createMutationKey,errorText,money} from '../finance/finance-model.js';

import './digital.css';
import DigitalPage from './DigitalPage.vue';
import DigitalExcludedCatalog from './DigitalExcludedCatalog.vue';
import {exportCategories} from './category-export.js';
async function downloadCategories(pdf){try{await exportCategories({allocation:!props.available,account_name:targetAccount.value?.name||data.value.connection?.main_account_name,provider:'topup',catalog_updated_at:data.value.connection?.catalog_updated_at,excluded_catalog:data.value.connection?.excluded_catalog,offers:data.value.categories.map(row=>({name:row.name,remote_id:row.remote_id,type:row.type,retail:row.price,active:props.available?row.active:selected.value.includes(row.id)&&active.value}))},pdf);}catch(e){error.value=e.message||'تعذر التصدير.';}}

const props=defineProps({available:{type:Boolean,default:false}});

const {session}=usePortal(),data=ref(null),target=ref(''),selected=ref([]),active=ref(true),error=ref(''),notice=ref(''),loading=ref(false),busy=ref(false),query=ref(''),page=ref(1);

const selling=ref(null),connectionId=ref(''),allocationBalance=ref('0.00'),retailPrices=ref({});
const route=useRoute(),type=ref('');
if(!props.available&&/^\d+$/.test(String(route.query.target_account_id||'')))target.value=String(route.query.target_account_id);
const counts=computed(()=>['topup','bundle','bill'].map(key=>({key,name:labels[key],count:(data.value?.categories||[]).filter(row=>row.type===key).length,selected:(data.value?.categories||[]).filter(row=>row.type===key&&selected.value.includes(row.id)).length})).filter(row=>row.count));
const controller=new AbortController(),key=createMutationKey(); let revision=0;

const system=computed(()=>session.state.identity?.account.type==='system');

const labels={topup:'تعبئة رصيد',bundle:'باقة',bill:'تسديد فاتورة',main_agent:'وكيل رئيسي',sub_agent:'وكيل فرعي',sub_branch:'فرع فرعي',pos:'نقطة بيع'};

const filtered=computed(()=>(data.value?.categories||[]).filter(row=>(!type.value||row.type===type.value)&&[row.name,row.remote_id].some(value=>String(value||'').toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase()))));

const pages=computed(()=>Math.max(1,Math.ceil(filtered.value.length/25)));

const shown=computed(()=>filtered.value.slice((page.value-1)*25,page.value*25));

const targetAccount=computed(()=>data.value?.targets?.find(row=>String(row.id)===target.value));

async function load(next=target.value){

  const current=++revision; loading.value=true; error.value=''; data.value=null; key.clear();

  try{

    const params=new URLSearchParams({...next?{target_account_id:next}:{},...system.value&&connectionId.value?{connection_id:connectionId.value}:{}});
    const path=props.available?'/topup/available':'/topup/distribution?'+params;

    const result=await session.api.request(path,{signal:controller.signal});

    if(current!==revision)return;

    data.value=result.data;target.value=String(result.data.target_id||'');connectionId.value=String(result.data.connection_id||'');allocationBalance.value=result.data.grant?.balance||'0.00';retailPrices.value=Object.fromEntries(result.data.categories.map(row=>[row.id,row.price]));

    const allowed=new Set(result.data.categories.map(row=>row.id));

    selected.value=(result.data.grant?.category_ids||[]).filter(id=>allowed.has(id));

    active.value=result.data.grant?.active??true; page.value=1;type.value='';

  }catch(e){if(current===revision&&e.name!=='AbortError')error.value=errorText(e);}

  finally{if(current===revision)loading.value=false;}

}

async function synchronize(){
  if(busy.value||!data.value?.connection?.id)return;
  busy.value=true;error.value='';
  try{const c=data.value.connection,result=await session.api.mutate('/digital/connections/'+c.id+'/sync','POST',{version:c.version},controller.signal,{timeoutMs:120000});notice.value=[result.data.sync.balance==='ok'?'تم تحديث رصيد الشركة.':result.data.sync.balance_message,result.data.sync.catalog_message].filter(Boolean).join(' ');await load(target.value);}catch(e){if(e.name!=='AbortError')error.value=errorText(e);}finally{busy.value=false;}
}
async function finishSale(){selling.value=null;await load();}
async function save(){

  if(busy.value||loading.value||!data.value||!target.value)return;

  busy.value=true;error.value='';notice.value='';

  const id=target.value,payload={category_ids:[...selected.value].sort((a,b)=>a-b),active:active.value,version:data.value.grant.version,...system.value?{connection_id:Number(connectionId.value),allocation_balance:allocationBalance.value}:{}};

  try{

    await session.api.mutate('/topup/distribution/'+id,'PUT',{...payload,idempotency_key:key.for({...payload,target:id})},controller.signal);

    notice.value='تم حفظ تخصيص فئات Topup.';await load(id);

  }catch(e){if(e.name!=='AbortError')error.value=errorText(e);}

  finally{busy.value=false;}

}

async function savePrices(){if(busy.value||!data.value)return;busy.value=true;error.value='';try{const payload={version:data.value.allocation.version,prices:data.value.categories.map(row=>({category_id:row.id,price:String(retailPrices.value[row.id])}))};await session.api.mutate('/topup/prices','PUT',{...payload,idempotency_key:key.for(payload)},controller.signal);notice.value='تم حفظ أسعار الوكيل.';await load();}catch(e){error.value=errorText(e);}finally{busy.value=false;}}
onMounted(()=>load());onBeforeUnmount(()=>{revision++;controller.abort();});

</script>

<template>

<section class="digital-services topup-allocation" data-no-pagination>
  <DigitalPage v-if="selling" :focused-offer="selling" @back="finishSale"/>
  <template v-else>

  <p v-if="error" class="notice warn" role="alert">{{error}}</p><p v-if="notice" class="notice" role="status">{{notice}}</p>

  <p class="read-loading" v-if="loading" role="status">جارٍ تحميل فئات Topup…</p>

  <section class="card">

    <div class="topup-target-toolbar">
      <template v-if="data&&!available">

      <label>{{system?'الوكيل الرئيسي':'الحساب التابع مباشرة'}}<select v-model="target" :disabled="busy||loading" @change="notice='';connectionId='';load(target)"><option v-if="!data.targets.length" value="">لا توجد حسابات تابعة</option><option v-for="account in data.targets" :key="account.id" :value="String(account.id)">{{account.name}} · {{labels[account.type]}}{{account.operational?'':' · موقوف'}}</option></select></label>

      <label v-if="system">توكن التبب<select v-model="connectionId" :disabled="busy" @change="load(target)"><option v-for="row in data.connections" :key="row.id" :value="String(row.id)">{{row.name}} · المتاح: {{row.available===null?'غير معلوم':money(row.available)+' د.ع'}}</option></select></label><label v-if="system">رصيد الوكيل بعد التخصيص · د.ع<input v-model="allocationBalance" inputmode="decimal" :disabled="busy" /></label><label>حالة تخصيص الفئات<select v-model="active" :disabled="busy||!target"><option :value="true">مفعّل</option><option :value="false">موقوف</option></select></label>

      <button v-if="system&&data.connection?.id" class="btn topup-sync" :disabled="busy||loading||!data.connection.token_present" @click="synchronize">استعلام الرصيد والفئات</button>
      </template>
      <div class="topup-heading-actions"><RouterLink v-if="system" class="btn" to="/topup/categories">فئات الشركة</RouterLink><button class="btn" :disabled="busy||loading" @click="load()">تحديث</button></div>
    </div>

    <template v-if="data">

    <div class="topup-summary-row">

    <div v-if="data.connection" class="digital-status-summary"><span>الوكيل الرئيسي: {{data.connection.main_account_name}}</span><span>{{data.connection.token_present?'توكن الإدارة محفوظ':'لم يخصص توكن بعد'}}</span><span>{{data.connection.active?'الربط مفعّل':'الربط موقوف'}}</span><span v-if="data.connection.company_balance!==undefined">رصيد الشركة: {{data.connection.company_balance===null?'لم يُجلب بعد':money(data.connection.company_balance)+' د.ع'}}</span></div>

    <div v-if="data.allocation?.connection_id" class="digital-status-summary"><span>حصة الوكيل: {{money(data.allocation.balance)}} د.ع</span><span>المتاح: {{money(data.allocation.available)}} د.ع</span><span>المحجوز: {{money(data.allocation.held)}} د.ع</span><span>المصروف: {{money(data.allocation.spent)}} د.ع</span></div><div class="digital-status-summary topup-counts"><span class="badge">الفئات المتاحة: {{data.categories.length}}</span><span v-for="item in counts" :key="item.key" class="badge">{{uiLabel(item.name)}}: {{item.count}}<template v-if="!available"> · المحدد: {{item.selected}}</template></span></div>
    </div>
    <p v-if="data.execution_ready===false" class="notice warn">التعبئة غير متاحة حاليًا.</p>
    <FilterBar class="topup-filter-tools"><select v-model="type" aria-label="نوع فئات Topup" @change="page=1"><option value="">كل الأنواع</option><option v-for="item in counts" :key="item.key" :value="item.key">{{uiLabel(item.name)}}</option></select><input v-model="query" aria-label="بحث فئات Topup" placeholder="بحث باسم الفئة أو الباقة" @input="page=1"><span v-if="!available" class="badge">{{selected.length}} فئة محددة</span><button class="btn small" :disabled="busy||loading" @click="downloadCategories(false)">تصدير Excel</button><button class="btn small" :disabled="busy||loading" @click="downloadCategories(true)">PDF / طباعة</button></FilterBar>

    <form @submit.prevent="save">

      <TablePanel :start="(page-1)*25+1" class="tablewrap"><table><thead><tr><th v-if="!available">تخصيص</th><th>الفئة / الباقة</th><th>معرف الشركة</th><th>الخدمة</th><th>{{available?'سعر البيع · د.ع':'سعر المدير · د.ع'}}</th><th v-if="!available">سعر الوكيل · د.ع</th><th v-if="available">التعبئة</th></tr></thead><tbody>

        <tr v-for="category in shown" :key="category.id"><td v-if="!available"><input v-model="selected" type="checkbox" :value="category.id" :aria-label="'منح '+category.name" :disabled="busy||!target||!targetAccount?.operational"></td><td>{{category.name}}</td><td dir="ltr">{{category.remote_id}}</td><td>{{labels[category.type]}}</td><td>{{money(available?category.price:category.admin_price)}}</td><td v-if="!available"><input v-if="data.can_edit_prices" v-model="retailPrices[category.id]" inputmode="decimal" :aria-label="'سعر بيع '+category.name" :disabled="busy"><span v-else>{{money(category.price)}}</span></td><td v-if="available"><button type="button" class="btn primary" :disabled="!category.offer||!data.execution_ready||!session.can('digital.create')" @click="selling=category.offer">تعبئة رقم الزبون</button></td></tr>

        <tr v-if="!shown.length"><td colspan="5" class="empty">{{query?'لا توجد فئات مطابقة':system?'لا توجد فئات متاحة؛ استعلم عن فئات الشركة لهذا الوكيل.':'لم تمنح فئات Topup مفعّلة لهذا الحساب بعد.'}}</td></tr>

      </tbody></table></TablePanel>

      <div class="pager"><button type="button" class="btn" :disabled="page<=1" @click="page--">السابق</button><span>الصفحة {{page}} من {{pages}}</span><button type="button" class="btn" :disabled="page>=pages" @click="page++">التالي</button></div>

      <div v-if="!available" class="formfoot"><button class="btn primary" :disabled="busy||loading||!target||!targetAccount?.operational">{{busy?'جارٍ الحفظ…':'حفظ تخصيص الفئات'}}</button><button v-if="data.can_edit_prices" type="button" class="btn" :disabled="busy" @click="savePrices">حفظ أسعار الوكيل</button></div>

    </form><DigitalExcludedCatalog v-if="system" compact :records="data.connection?.excluded_catalog??null"/>

    </template>

  </section>

  </template>
</section>

</template>



<style scoped>
.topup-heading-actions{display:flex;align-items:center;gap:10px;flex-wrap:nowrap;margin:0}.topup-heading-actions .btn{min-height:44px}
.digital-services.topup-allocation .topup-target-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:end}
.topup-target-toolbar label:first-child{flex:0 1 340px}.topup-target-toolbar label:nth-child(2){flex:0 1 190px}
.digital-services.topup-allocation .topup-target-toolbar label{display:grid;gap:8px;margin:0;min-width:0}.topup-target-toolbar select{width:100%;max-width:none;min-width:0;margin:0;height:44px}.topup-sync{min-height:44px;white-space:nowrap}
.topup-summary-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px 16px;margin:12px 0}.digital-status-summary{margin:0;gap:12px;align-items:center;justify-content:flex-start}.topup-counts{gap:8px}
.digital-services.topup-allocation :deep(.topup-filter-tools){display:grid!important;grid-template-columns:180px minmax(200px,1fr) auto auto auto;gap:10px;align-items:center!important;padding:12px!important;border-radius:10px;background:var(--raised);justify-content:initial}
:deep(.topup-filter-tools>input),:deep(.topup-filter-tools>select){width:100%;max-width:none;min-width:0;margin:0;height:44px}
:deep(.topup-filter-tools>.btn){min-height:44px;white-space:nowrap;margin:0}:deep(.topup-filter-tools>.badge){white-space:nowrap;margin:0;justify-self:center}
@media(max-width:1000px){.digital-services.topup-allocation :deep(.topup-filter-tools){grid-template-columns:minmax(140px,.7fr) minmax(180px,1fr) auto auto}:deep(.topup-filter-tools>.badge){grid-column:1/-1;grid-row:2;justify-self:start}}
@media(max-width:700px){.digital-services.topup-allocation .topup-target-toolbar label{flex:1 1 140px}.topup-sync{flex:1 1 100%}.topup-heading-actions{width:100%}.topup-heading-actions .btn{flex:1}.digital-services.topup-allocation :deep(.topup-filter-tools){grid-template-columns:repeat(2,minmax(0,1fr))}:deep(.topup-filter-tools>input){grid-column:1/-1;grid-row:1}:deep(.topup-filter-tools>select){grid-column:1/-1;grid-row:2}:deep(.topup-filter-tools>.badge){grid-column:1/-1;grid-row:4}:deep(.topup-filter-tools>.btn){width:100%}.digital-status-summary{font-size:11px;gap:8px}}
</style>
