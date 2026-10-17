<script setup>
import {RouterLink} from "vue-router";
import {usePreferencesTranslator} from "../preferences/preferences-state.js";
const uiLabel=usePreferencesTranslator();
import AccountPicker from '../finance/AccountPicker.vue';
import {useAccountLookup} from '../../shared/api/account-lookup.js';
const lookup=useAccountLookup();
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

const targetAccount=computed(()=>data.value?.targets?.find(row=>String(row.id)===String(target.value)));

async function load(next=target.value){

  const current=++revision; loading.value=true; error.value=''; data.value=null; key.clear();

  try{

    const params=new URLSearchParams({compact_targets:1,...next?{target_account_id:next}:{},...system.value&&connectionId.value?{connection_id:connectionId.value}:{}});
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

    notice.value=system.value?'تم حفظ رصيد الوكيل وتخصيص الفئات.':'تم حفظ تخصيص فئات Topup.';await load(id);

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
      <div v-if="data&&!available" class="topup-target-fields">

      <label>{{system?'الوكيل الرئيسي':'الحساب التابع مباشرة'}}<AccountPicker v-model="target" :vm="lookup" :parameters="{parent_id:session.state.identity.account.id,account_types:system?['main_agent']:['sub_agent','sub_branch','pos']}" label="الحساب المستلم للفئات" :disabled="busy||loading" :clearable="false" @update:model-value="notice='';connectionId='';load(target)"/></label>

      <label v-if="system">توكن Topup<select v-model="connectionId" :disabled="busy" @change="load(target)"><option v-for="row in data.connections" :key="row.id" :value="String(row.id)">{{row.name}} · المتاح: {{row.available===null?'غير معلوم':money(row.available)+' د.ع'}}</option></select></label><label v-if="system">رصيد الوكيل بعد التخصيص · د.ع<input v-model="allocationBalance" inputmode="decimal" :disabled="busy" /></label><label>حالة تخصيص الفئات<select v-model="active" :disabled="busy||!target"><option :value="true">مفعّل</option><option :value="false">موقوف</option></select></label>

      
      </div>
      <div class="topup-heading-actions"><button v-if="data&&!available" type="button" class="btn primary" :disabled="busy||loading||!target||!targetAccount?.operational" @click="save">{{busy?'جارٍ الحفظ…':system?'حفظ الرصيد والفئات':'حفظ تخصيص الفئات'}}</button><button v-if="system&&data?.connection?.id" class="btn topup-sync" :disabled="busy||loading||!data.connection.token_present" @click="synchronize">استعلام الرصيد والفئات</button><RouterLink v-if="system" class="btn" to="/topup/categories">فئات الشركة</RouterLink><button class="btn" :disabled="busy||loading" @click="load()">تحديث</button></div>
    </div>

    <template v-if="data">

    <div class="topup-summary-row">

    <div v-if="data.connection" class="digital-status-summary topup-connection-summary"><span>{{data.connection.token_present?'توكن الإدارة محفوظ':'لم يخصص توكن بعد'}}</span><span>{{data.connection.active?'الربط مفعّل':'الربط موقوف'}}</span><span v-if="data.connection.company_balance!==undefined">رصيد الشركة: <b>{{data.connection.company_balance===null?'لم يُجلب بعد':money(data.connection.company_balance)+' د.ع'}}</b></span></div>

    <dl v-if="data.allocation?.connection_id" class="digital-status-summary topup-balance-summary"><div><dt>حصة الوكيل</dt><dd>{{money(data.allocation.balance)}} <small>د.ع</small></dd></div><div><dt>المتاح</dt><dd>{{money(data.allocation.available)}} <small>د.ع</small></dd></div><div><dt>المحجوز</dt><dd>{{money(data.allocation.held)}} <small>د.ع</small></dd></div><div><dt>المصروف:</dt><dd>{{money(data.allocation.spent)}} <small>د.ع</small></dd></div></dl><div class="digital-status-summary topup-counts"><span class="badge">الفئات المتاحة: {{data.categories.length}}</span><span v-for="item in counts" :key="item.key" class="badge">{{uiLabel(item.name)}}: {{item.count}}<template v-if="!available"> · المحدد: {{item.selected}}</template></span></div>
    </div>
    <p v-if="data.execution_ready===false" class="notice warn">التعبئة غير متاحة حاليًا.</p>
    <FilterBar class="topup-filter-tools"><select v-model="type" aria-label="نوع فئات Topup" @change="page=1"><option value="">كل الأنواع</option><option v-for="item in counts" :key="item.key" :value="item.key">{{uiLabel(item.name)}}</option></select><input v-model="query" aria-label="بحث فئات Topup" placeholder="بحث باسم الفئة أو الباقة" @input="page=1"><span v-if="!available" class="badge">{{selected.length}} فئة محددة</span><button class="btn small" :disabled="busy||loading" @click="downloadCategories(false)">تصدير Excel</button><button class="btn small" :disabled="busy||loading" @click="downloadCategories(true)">PDF / طباعة</button></FilterBar>

    <form @submit.prevent="save">

      <div v-if="available" class="topup-mobile-categories"><article v-for="category in shown" :key="category.id" class="topup-mobile-category"><strong dir="auto">{{category.name}}</strong><small>{{uiLabel(labels[category.type])}}</small><b>{{money(category.price)}} د.ع</b><button type="button" class="btn primary" :disabled="!category.offer||!data.execution_ready||!session.can('digital.create')" @click="selling=category.offer">تعبئة رقم الزبون</button></article></div><TablePanel :start="(page-1)*25+1" class="tablewrap" :class="{'topup-available-table':available}"><table><thead><tr><th v-if="!available">تخصيص</th><th>الفئة / الباقة</th><th>معرف الشركة</th><th>الخدمة</th><th>{{available?'سعر البيع · د.ع':'سعر المدير · د.ع'}}</th><th v-if="!available">سعر الوكيل · د.ع</th><th v-if="available">التعبئة</th></tr></thead><tbody>

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
.digital-services.topup-allocation .topup-target-toolbar{display:grid;gap:12px;min-width:0}
.topup-target-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));align-items:end;gap:12px;min-width:0}
.digital-services.topup-allocation .topup-target-fields>label{display:grid;gap:6px;margin:0;min-width:0;font-size:12px;white-space:normal}
.topup-target-fields :is(select,input){width:100%;max-width:100%;min-width:0;margin:0;height:44px}
.topup-target-fields :deep(.account-picker-trigger){min-width:0;height:44px;overflow:hidden;text-overflow:ellipsis}
.topup-heading-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;min-width:0}
.topup-heading-actions .btn{min-height:42px;max-width:100%}
.topup-summary-row{display:grid;gap:12px;margin:14px 0;min-width:0}
.topup-connection-summary,.topup-counts{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-start;gap:8px 16px;margin:0;min-width:0;font-size:12px}
.topup-connection-summary>span{min-width:0;overflow-wrap:anywhere}
.topup-connection-summary>span:last-child{margin-inline-start:auto}
.digital-status-summary.topup-balance-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0;padding:0}
.topup-balance-summary>div{min-width:0;border:1px solid var(--line);border-radius:12px;background:var(--raised);padding:10px 12px}
.topup-balance-summary dt{font-size:11px;color:var(--muted)}
.topup-balance-summary dd{margin:4px 0 0;font-size:17px;font-weight:700;color:var(--ink);overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
.topup-balance-summary small{font-size:11px;color:var(--muted)}
.topup-counts{gap:7px}
.digital-services.topup-allocation :deep(.topup-filter-tools){display:grid!important;grid-template-columns:minmax(130px,.6fr) minmax(180px,1fr) auto auto auto;gap:8px;align-items:center!important;padding:10px!important;border-radius:12px;background:var(--raised);min-width:0;justify-content:initial}
:deep(.topup-filter-tools>input),:deep(.topup-filter-tools>select){width:100%!important;max-width:100%!important;min-width:0;margin:0;height:42px}
:deep(.topup-filter-tools>.btn){min-height:42px;white-space:nowrap;margin:0}:deep(.topup-filter-tools>.badge){white-space:nowrap;margin:0;justify-self:center}
@media(max-width:1000px){.digital-services.topup-allocation :deep(.topup-filter-tools){grid-template-columns:minmax(130px,.7fr) minmax(150px,1fr) auto auto}:deep(.topup-filter-tools>.badge){grid-column:1/-1;grid-row:2;justify-self:start}}
@media(max-width:700px){.topup-target-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.topup-heading-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.topup-heading-actions .btn{width:100%;white-space:normal;padding-inline:8px}.topup-heading-actions>.primary{grid-column:1/-1}.digital-status-summary.topup-balance-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.topup-connection-summary{font-size:11px;gap:6px 12px}.topup-connection-summary>span:last-child{flex-basis:100%;margin:0}.digital-services.topup-allocation :deep(.topup-filter-tools){grid-template-columns:repeat(2,minmax(0,1fr))}:deep(.topup-filter-tools>input){grid-column:1/-1;grid-row:1}:deep(.topup-filter-tools>select){grid-column:1/-1;grid-row:2}:deep(.topup-filter-tools>.badge){grid-column:1/-1;grid-row:4}:deep(.topup-filter-tools>.btn){width:100%}}
@media(max-width:380px){.topup-target-fields{grid-template-columns:1fr}.topup-balance-summary dd{font-size:15px}}
</style>


