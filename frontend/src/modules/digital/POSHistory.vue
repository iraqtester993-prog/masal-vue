<script setup>
import AppSelect from '../../shared/components/AppSelect.vue';
import AppDateField from '../../shared/components/AppDateField.vue';
import { computed,onBeforeUnmount,onMounted,reactive,ref,watch } from 'vue';
import { useDigitalRuntime } from './digital-runtime.js';
import { money,time,stateNames,providerNames,canReceipt,canVerify } from './digital-model.js';
import { saleStatuses,canReadReceipt } from '../sales/sales-model.js';
import DigitalReceipt from './DigitalReceipt.vue';
import './digital.css';
import {createCursorFeed} from '../../shared/api/cursor-feed.js';
import {useListNavigation} from '../../shared/api/list-navigation.js';
import {useRouteRefresh} from '../../shared/route-refresh.js';
import {useRoute} from 'vue-router';
import {systemLabel} from '../preferences/system-labels.js';
const props=defineProps({sales:{type:Object,required:true}}),emit=defineEmits(['stockReceipt','stockDetails','stockReprint']),vm=useDigitalRuntime(),route=useRoute();
const navigation=useListNavigation(vm.session.api,'pos-history'),saved=navigation.saved;
const filters=reactive({q:'',kind:'all',from:'',to:'',...saved?.filters,...(route?.query?.service==='topup'?{kind:'topup'}:{})}),showFilters=ref(saved?.showFilters??false),expanded=ref(''),sentinel=ref(null),keys=new Map();
const feedState=reactive({rows:[],meta:{pagination:'cursor',per_page:10,has_more:false,next_cursor:null},loading:false,error:''});
const rows=computed(()=>feedState.rows),meta=computed(()=>feedState.meta),loading=computed(()=>feedState.loading);
let timer,observer;
if(!vm.can('digital.view')&&filters.kind==='topup')filters.kind='all';
const feed=createCursorFeed({state:feedState,key:recordKey,parameters:()=>({...filters}),peek:vm.api.peekHistory,read:vm.api.history,onError:async error=>{vm.state.error=await vm.failure(error);}});
feed.hydrate(Math.max(1,Number(saved?.pages)||1));
const filterCount=computed(()=>Number(filters.kind!=='all')+Number(!!filters.from)+Number(!!filters.to));
const hasMore=computed(()=>meta.value.has_more);
async function loadMore(){if(!loading.value&&hasMore.value){vm.state.error='';await feed.more();}}
async function refresh(){vm.state.error='';await feed.refresh();return !vm.state.error&&!feedState.error;}
async function verify(order){if(!keys.has(order.id))keys.set(order.id,vm.key());const result=await vm.execute({version:order.version},keys.get(order.id),(payload,signal)=>vm.api.action(order.id,'verify',payload,signal),'تم التحقق من الطلب نفسه.');if(result)await refresh();}
function clearFilters(){Object.assign(filters,{q:'',kind:'all',from:'',to:''});}
function recordKey(item){return `${item.kind}:${item.record.id}`;}
function observe(){if(observer)observer.disconnect();if(sentinel.value&&globalThis.IntersectionObserver){observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting))loadMore();},{rootMargin:'0px 0px 120px 0px'});observer.observe(sentinel.value);}}
defineExpose({refresh});
useRouteRefresh(refresh,{canRefresh:()=>!vm.state.busy&&!vm.state.uncertain&&!vm.receipt.value&&!props.sales.state.busy&&!props.sales.state.uncertain&&!props.sales.receiptLoading?.value,priority:10});
watch(filters,()=>{clearTimeout(timer);feed.cancel();timer=setTimeout(()=>feed.load(),250);});
watch(()=>route?.query?.service,service=>{if(service==='topup'&&vm.can('digital.view'))filters.kind='topup';});
watch(sentinel,observe);
onMounted(async()=>{observe();await feed.load({count:feed.pageCount()});await navigation.restoreScroll();});
onBeforeUnmount(()=>{navigation.remember({filters:{...filters},showFilters:showFilters.value,pages:feed.pageCount()});feed.cancel();clearTimeout(timer);observer?.disconnect();keys.clear();});
</script>
<template>
  <section class="pos-phone-operations pos-history">
    <div class="pos-history-heading"><span>{{rows.length}} عملية معروضة</span></div>
    <p v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</p>
    <p v-if="vm.state.notice" class="notice" role="status">{{vm.state.notice}}</p>
    <div class="pos-history-search"><input v-model="filters.q" type="search" placeholder="بحث برقم العملية أو الفئة" aria-label="بحث في العمليات"><button type="button" class="btn small" :class="{active:showFilters}" :aria-expanded="showFilters" aria-controls="pos-history-filters" @click="showFilters=!showFilters">فلترة<span v-if="filterCount" class="badge">{{filterCount}}</span></button></div>
    <div v-if="showFilters" id="pos-history-filters" class="pos-history-filters"><label v-if="vm.can('digital.view')">الخدمة<AppSelect v-model="filters.kind"><option value="all">الكل</option><option value="card">بطاقات</option><option value="topup">Topup</option></AppSelect></label><label>من تاريخ<AppDateField v-model="filters.from" :max="filters.to||undefined"/></label><label>إلى تاريخ<AppDateField v-model="filters.to" :min="filters.from||undefined"/></label><button type="button" class="btn small" @click="clearFilters">مسح الفلاتر</button></div>
    <p v-if="loading && rows.length" class="pos-history-loading" role="status">جارٍ تحديث السجل…</p>
    <article v-for="item in rows" :key="recordKey(item)" class="card pos-history-item">
      <div class="pos-history-item-heading"><div><small>{{item.kind==='stock'?item.record.provider_name:providerNames[item.record.provider]}}</small><strong>{{item.record.product_name}}</strong></div><span class="badge" :class="{warn:['Print Failed','failed','review'].includes(item.record.status)}">{{item.kind==='stock'?saleStatuses[item.record.status]:stateNames[item.record.status]}}</span></div>
      <div class="pos-history-item-meta"><time>{{time(item.record.issued_at||item.record.created_at)}}</time><span v-if="item.kind==='stock'">{{item.record.quantity}} بطاقة</span><span v-else>{{systemLabel(item.record.type||'voucher','productKind')}}</span><b>{{money(item.kind==='stock'?item.record.retail_total:item.record.retail)}} د.ع</b></div>
      <p v-if="item.kind==='digital'&&item.record.receipt_missing" class="notice warn">أكدت الشركة الشراء، لكن رمز البطاقة لم يصل. راجع الوكيل دون إعادة شراء.</p>
      <p v-if="item.record.failure_reason||item.record.message" class="pos-history-message">{{item.record.failure_reason||item.record.message}}</p>
      <div class="pos-history-item-actions"><span class="pos-history-reference mono">#{{item.record.id}}</span><template v-if="item.kind==='stock'"><button v-if="canReadReceipt(item.record,sales.identity.value,sales.can)" type="button" class="btn small" :disabled="sales.state.busy || sales.receiptLoading?.value" @click="emit('stockReceipt',item.record)">{{item.record.print_pending ? 'تأكيد النتيجة':'الوصل'}}</button><button type="button" class="btn small" @click="emit('stockDetails',item.record)">التفاصيل</button></template><template v-else><button v-if="canReceipt(item.record,vm.identity.value,vm.can)" type="button" class="btn small" :disabled="vm.state.busy" @click="vm.openReceipt(item.record)">الوصل</button><button v-if="canVerify(item.record,vm.identity.value,vm.can)" type="button" class="btn small" :disabled="vm.state.busy" @click="verify(item.record)">تحقق من النتيجة</button><button type="button" class="btn small" :aria-expanded="expanded===recordKey(item)" @click="expanded=expanded===recordKey(item)?'':recordKey(item)">التفاصيل</button></template></div>
      <dl v-if="item.kind==='digital' && expanded===recordKey(item)" class="pos-history-digital-details"><div><dt>رقم العملية / الطلب</dt><dd class="mono">{{item.record.request_id || item.record.id}}</dd></div><div v-if="item.record.mobile"><dt>رقم الزبون</dt><dd class="mono">{{item.record.mobile}}</dd></div><div v-if="item.record.company_transaction_id"><dt>مرجع العملية لدى الشركة</dt><dd class="mono">{{item.record.company_transaction_id}}</dd></div><div><dt>الحالة</dt><dd>{{stateNames[item.record.status]}}</dd></div></dl>
      <small v-if="item.kind==='digital'&&item.record.reservation_active&&item.record.verification_supported===false">النتيجة تحتاج متابعة الوكيل بمرجع الطلب؛ لا تبدأ شراءً جديدًا.</small>
    </article>
    <p v-if="!rows.length" class="empty">{{loading?'جارٍ تحميل السجل…':'لا توجد عمليات مطابقة'}}</p>
    <div ref="sentinel" class="history-load-more" :aria-busy="loading"><p v-if="loading" role="status">{{rows.length?'جارٍ تحميل العمليات…':'جارٍ تحميل السجل…'}}</p><button v-else-if="hasMore" type="button" class="btn small" @click="loadMore">{{feedState.error?'إعادة محاولة تحميل العمليات':'عرض عمليات أكثر'}}</button><small v-else-if="rows.length">تم عرض جميع العمليات · {{rows.length}}</small></div>
    <DigitalReceipt v-if="vm.receipt.value" :vm="vm" :receipt="vm.receipt.value" @close="vm.clearReceipt()"/>
  </section>
</template>

