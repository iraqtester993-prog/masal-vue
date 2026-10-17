<script setup>
import AppDateField from '../../shared/components/AppDateField.vue';
import { computed,onBeforeUnmount,onMounted,reactive,ref,watch } from 'vue';
import { useDigitalRuntime } from './digital-runtime.js';
import { money,time,stateNames,providerNames,canReceipt,canVerify } from './digital-model.js';
import { saleStatuses,canReadReceipt } from '../sales/sales-model.js';
import DigitalReceipt from './DigitalReceipt.vue';
import './digital.css';
const props=defineProps({sales:{type:Object,required:true}}),emit=defineEmits(['stockReceipt','stockDetails','stockReprint']),vm=useDigitalRuntime(),filters=reactive({q:'',kind:'all',from:'',to:''}),rows=ref([]),loading=ref(false),showFilters=ref(false),expanded=ref(''),meta=reactive({current_page:1,last_page:1,total:0}),keys=new Map();let reader,timer,revision=0;
const filterCount=computed(()=>Number(filters.kind!=='all')+Number(!!filters.from)+Number(!!filters.to));
function hydrate(page=1){const cached=vm.api.peekHistory({...filters,page});rows.value=cached?.data??[];Object.assign(meta,cached?.meta??{current_page:1,last_page:1,total:0});return cached;}
hydrate();
async function load(page=1){reader?.abort();reader=new AbortController();const expected=++revision,cached=hydrate(page);loading.value=!cached;vm.state.error='';try{const response=await vm.api.history({...filters,page},reader.signal);if(expected!==revision)return;rows.value=response.data;Object.assign(meta,response.meta);}catch(error){if(error.name!=='AbortError' && expected===revision)vm.state.error=await vm.failure(error);}finally{if(expected===revision)loading.value=false;}}
async function verify(order){if(!keys.has(order.id))keys.set(order.id,vm.key());const result=await vm.execute({version:order.version},keys.get(order.id),(payload,signal)=>vm.api.action(order.id,'verify',payload,signal),'تم التحقق من الطلب نفسه.');if(result)await load(meta.current_page);}
function clearFilters(){Object.assign(filters,{q:'',kind:'all',from:'',to:''});}
function recordKey(item){return `${item.kind}:${item.record.id}`;}
defineExpose({refresh:()=>load(meta.current_page)});
watch(filters,()=>{clearTimeout(timer);timer=setTimeout(()=>load(),250);});onMounted(()=>load());onBeforeUnmount(()=>{reader?.abort();clearTimeout(timer);revision++;rows.value=[];});
</script>
<template>
  <section class="pos-phone-operations pos-history">
    <div class="pos-history-heading"><h2>سجل العمليات</h2><span>{{meta.total}} عملية</span></div>
    <p v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</p>
    <p v-if="vm.state.notice" class="notice" role="status">{{vm.state.notice}}</p>
    <div class="pos-history-search"><input v-model="filters.q" type="search" placeholder="بحث برقم العملية أو الفئة" aria-label="بحث في العمليات"><button type="button" class="btn small" :class="{active:showFilters}" :aria-expanded="showFilters" aria-controls="pos-history-filters" @click="showFilters=!showFilters">فلترة<span v-if="filterCount" class="badge">{{filterCount}}</span></button></div>
    <div v-if="showFilters" id="pos-history-filters" class="pos-history-filters"><label>الخدمة<select v-model="filters.kind"><option value="all">الكل</option><option value="card">بطاقات</option><option value="topup">Topup</option></select></label><label>من تاريخ<AppDateField v-model="filters.from" :max="filters.to||undefined"/></label><label>إلى تاريخ<AppDateField v-model="filters.to" :min="filters.from||undefined"/></label><button type="button" class="btn small" @click="clearFilters">مسح الفلاتر</button></div>
    <p v-if="loading && rows.length" class="pos-history-loading" role="status">جارٍ تحديث السجل…</p>
    <article v-for="item in rows" :key="recordKey(item)" class="card pos-history-item">
      <div class="pos-history-item-heading"><div><small>{{item.kind==='stock'?item.record.provider_name:providerNames[item.record.provider]}}</small><strong>{{item.record.product_name}}</strong></div><span class="badge" :class="{warn:['Print Failed','failed','review'].includes(item.record.status)}">{{item.kind==='stock'?saleStatuses[item.record.status]:stateNames[item.record.status]}}</span></div>
      <div class="pos-history-item-meta"><time>{{time(item.record.issued_at||item.record.created_at)}}</time><span v-if="item.kind==='stock'">{{item.record.quantity}} بطاقة</span><b>{{money(item.kind==='stock'?item.record.retail_total:item.record.retail)}} د.ع</b></div>
      <p v-if="item.kind==='digital'&&item.record.receipt_missing" class="notice warn">أكدت الشركة الشراء، لكن رمز البطاقة لم يصل. راجع الوكيل دون إعادة شراء.</p>
      <p v-if="item.record.failure_reason||item.record.message" class="pos-history-message">{{item.record.failure_reason||item.record.message}}</p>
      <div class="pos-history-item-actions"><span class="pos-history-reference mono">#{{item.record.id}}</span><template v-if="item.kind==='stock'"><button v-if="canReadReceipt(item.record,sales.identity.value,sales.can)" type="button" class="btn small" :disabled="sales.state.busy || sales.receiptLoading?.value" @click="emit('stockReceipt',item.record)">{{item.record.print_pending ? 'تأكيد النتيجة':'الوصل'}}</button><button type="button" class="btn small" @click="emit('stockDetails',item.record)">التفاصيل</button></template><template v-else><button v-if="canReceipt(item.record,vm.identity.value,vm.can)" type="button" class="btn small" :disabled="vm.state.busy" @click="vm.openReceipt(item.record)">الوصل</button><button v-if="canVerify(item.record,vm.identity.value,vm.can)" type="button" class="btn small" :disabled="vm.state.busy" @click="verify(item.record)">تحقق من النتيجة</button><button type="button" class="btn small" :aria-expanded="expanded===recordKey(item)" @click="expanded=expanded===recordKey(item)?'':recordKey(item)">التفاصيل</button></template></div>
      <dl v-if="item.kind==='digital' && expanded===recordKey(item)" class="pos-history-digital-details"><div><dt>رقم الطلب</dt><dd class="mono">{{item.record.request_id || item.record.id}}</dd></div><div v-if="item.record.mobile"><dt>رقم الزبون</dt><dd class="mono">{{item.record.mobile}}</dd></div><div v-if="item.record.company_transaction_id"><dt>مرجع الشركة</dt><dd class="mono">{{item.record.company_transaction_id}}</dd></div><div><dt>الحالة</dt><dd>{{stateNames[item.record.status]}}</dd></div></dl>
      <small v-if="item.kind==='digital'&&item.record.reservation_active&&item.record.verification_supported===false">النتيجة تحتاج متابعة الوكيل بمرجع الطلب؛ لا تبدأ شراءً جديدًا.</small>
    </article>
    <p v-if="!rows.length" class="empty">{{loading?'جارٍ تحميل السجل…':'لا توجد عمليات مطابقة'}}</p>
    <div class="sales-pagination"><button type="button" class="btn small" :disabled="loading||meta.current_page<=1" @click="load(meta.current_page-1)">السابق</button><span>{{meta.current_page}} / {{meta.last_page}}</span><button type="button" class="btn small" :disabled="loading||meta.current_page>=meta.last_page" @click="load(meta.current_page+1)">التالي</button></div>
    <DigitalReceipt v-if="vm.receipt.value" :vm="vm" :receipt="vm.receipt.value" @close="vm.clearReceipt()"/>
  </section>
</template>

