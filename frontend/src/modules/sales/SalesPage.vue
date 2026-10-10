<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';


import {computed,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';

import {useRoute} from 'vue-router';

import {useSalesRuntime} from './sales-runtime.js';

import {canReadReceipt,canRequestReprint,canReviewRequest,downloadBlob,money,ownsSale,saleStatuses,time} from './sales-model.js';

import DeviceSession from './DeviceSession.vue';

import ReceiptDialog from './ReceiptDialog.vue';

import ReprintDialog from './ReprintDialog.vue';

import OwnReprintSales from './OwnReprintSales.vue';

import POSHistory from '../digital/POSHistory.vue';

import SaleDetailsDialog from './SaleDetailsDialog.vue';

import '../catalog/catalog.css';

import './sales.css';

const props=defineProps({mode:String}),route=useRoute(),vm=useSalesRuntime();

const exceptions=computed(()=>props.mode==='exceptions'||route?.name==='exceptions');

const tab=ref(exceptions.value?'requests':'sales'),detail=ref(null),reprint=ref(null),reasons=reactive({}),rows=ref([]),counts=reactive({}),meta=reactive({current_page:1,last_page:1,total:0});

const filters=reactive({q:'',account_id:'',status:'',from:'',to:'',currency:'IQD',direction:''}),loading=ref(false),exporting=ref(false);

const statusTabs=[{id:'',name:'الكل'},{id:'Print Requested',name:'بانتظار الطباعة'},{id:'Print Failed',name:'فشل الطباعة'},{id:'Printed',name:'مطبوعة'},{id:'Reprinted',name:'أعيدت طباعتها'}];

let controller,revision=0,timer; const keys=new Map();

const kind=computed(()=>tab.value==='requests'?'reprint-requests':'');

function keyFor(id,action){const idKey=`${id}:${action}`;if(!keys.has(idKey))keys.set(idKey,vm.key());return keys.get(idKey);}

function parameters(page=1){return {page,per_page:25,q:filters.q,account_id:filters.account_id,status:filters.status,from:filters.from,to:filters.to,...(tab.value==='sales'?{currency:filters.currency}:{direction:filters.direction})};}

async function load(page=1){if(vm.pos.value&&tab.value==='sales')return;controller?.abort();controller=new AbortController();const own=controller,params=parameters(page),expected=++revision;loading.value=true;vm.state.error='';rows.value=[];

  try {const sales=tab.value==='sales';const [response,summaryResult]=await Promise.all([vm.api.list(kind.value,params,own.signal),sales?vm.api.summary(params,own.signal):Promise.resolve(null)]);if(expected!==revision)return;rows.value=response.data;Object.assign(meta,response.meta);

    if(sales){const summary=summaryResult.data;if(expected===revision){Object.keys(counts).forEach(key=>delete counts[key]);Object.assign(counts,summary.status_counts,{'':summary.total});statusTabs.forEach(item=>{if(counts[item.id]===undefined)counts[item.id]=0;});}}

  }catch(error){if(error.name!=='AbortError'&&expected===revision)vm.state.error=await vm.failure(error);}finally{if(expected===revision)loading.value=false;}}

async function inspect(sale){try{detail.value=(await vm.api.show(sale.id)).data;}catch(error){vm.state.error=await vm.failure(error);}}

async function openReceipt(sale){detail.value=null;await vm.openReceipt(sale);}

function openReprint(sale){vm.clearReceipt();detail.value=null;reprint.value=sale;}

async function reservation(sale,action){const result=await vm.execute({version:sale.version},keyFor(sale.id,action),(payload,signal)=>vm.api.reservation(sale.id,action,payload,signal),action==='issue'?'تم الإصدار والخصم مرة واحدة.':'تم إلغاء الحجز وإتاحة البطاقات والرصيد.');if(result){await load(meta.current_page);if(action==='issue')await vm.openReceipt(result);}}

async function review(request,decision){const reason=String(reasons[request.id]||'').trim();if(['reject','escalate'].includes(decision)&&!reason){vm.state.error='اذكر سبب القرار أولًا.';return;}const payload={version:request.version,...(decision==='escalate'?{reason}:{decision,...(reason?{reason}:{})})};const result=await vm.execute(payload,keyFor(request.id,decision),(value,signal)=>vm.api.review(request.id,decision==='escalate'?'escalate':'review',value,signal),decision==='escalate'?'تم رفع الطلب إلى المستوى الأعلى.':decision==='approve'?'تم اعتماد إعادة الطباعة دون خصم جديد.':'تم رفض طلب إعادة الطباعة.');if(result){delete reasons[request.id];await load(meta.current_page);}}

async function download(){if(exporting.value)return;exporting.value=true;try{downloadBlob(await vm.api.export(parameters(),controller?.signal),'sales.csv');}catch(error){vm.state.error=await vm.failure(error);}finally{exporting.value=false;}}

function name(id){return vm.state.options.accounts.find(account=>Number(account.id)===Number(id))?.name || `#${id}`;}

watch(()=>[filters.q,filters.account_id,filters.status,filters.from,filters.to,filters.currency,filters.direction],()=>{clearTimeout(timer);timer=setTimeout(()=>load(),250);});

watch(tab,()=>{filters.status='';filters.direction='';load();});

watch(exceptions,value=>{vm.clearReceipt();detail.value=null;reprint.value=null;tab.value=value?'requests':'sales';});

onMounted(()=>Promise.all([vm.load(),load()]));

onBeforeUnmount(()=>{revision++;clearTimeout(timer);controller?.abort();rows.value=[];detail.value=null;reprint.value=null;keys.clear();});

</script>

<template>

  <div class="catalog-workspace sales-workspace"><div v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</div><div v-if="vm.state.notice" class="notice" role="status">{{vm.state.notice}}</div><DeviceSession :vm="vm"/><div v-if="vm.can('sales.view') && vm.can('exceptions.view')" class="tabs"><button type="button" :class="{active:tab==='sales'}" @click="tab='sales'">سجل العمليات</button><button type="button" :class="{active:tab==='requests'}" @click="tab='requests'">طلبات معالجة الطباعة</button></div>

    <POSHistory v-if="vm.pos.value&&tab==='sales'" :sales="vm" @stock-receipt="openReceipt" @stock-details="inspect" @stock-reprint="openReprint"/><div v-else class="card" :class="{'sales-log-card':tab==='sales'}"><div v-if="tab==='sales'" class="tabs operation-status-tabs"><button v-for="item in statusTabs" :key="item.id" type="button" :class="{active:filters.status===item.id}" @click="filters.status=item.id">{{item.name}} · {{counts[item.id] ?? '—'}}</button></div><FilterBar collapsible class="toolbar" :class="{'exceptions-toolbar':tab==='requests'}"><input v-model="filters.q" style="max-width:300px" placeholder="بحث برقم العملية أو نقطة البيع" aria-label="بحث العمليات"><select v-model="filters.status" style="width:190px" aria-label="حالة العملية"><option value="">جميع الحالات</option><option v-for="status in tab==='requests'?['pending','approved','rejected','used']:['Printed','Print Requested','Print Failed','Reprinted','Reprint Requested','Reserved','Cancelled','Delivered']" :key="status" :value="status">{{saleStatuses[status]}}</option></select><select v-model="filters.account_id" aria-label="الحساب"><option value="">جميع الحسابات</option><option v-for="account in vm.state.options.accounts" :key="account.id" :value="account.id">{{account.name}}</option></select><input v-model="filters.from" type="date" aria-label="من تاريخ"><input v-model="filters.to" type="date" aria-label="إلى تاريخ"><select v-if="tab==='requests'" v-model="filters.direction" aria-label="اتجاه الطلبات"><option value="">جميع الطلبات</option><option value="incoming">طلبات واردة</option><option value="outgoing">طلباتي</option></select><button type="button" class="btn small" :disabled="loading" @click="load(meta.current_page)">تحديث</button><button v-if="tab==='sales' && vm.can('sales.export')" type="button" class="btn small" :disabled="exporting" @click="download">تنزيل السجل</button></FilterBar>

      <template v-if="tab==='sales'"><TablePanel :start="(meta.current_page-1)*25+1" v-if="!vm.pos.value" class="tablewrap"><table><thead><tr><th>رقم العملية</th><th>التاريخ والوقت</th><th>الوكيل</th><th>نقطة البيع</th><th>المنتج</th><th>الكمية</th><th>الإجمالي</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="sale in rows" :key="sale.id"><td class="mono">{{sale.id}}</td><td>{{time(sale.issued_at || sale.created_at)}}</td><td>{{sale.main_account_name || name(sale.main_account_id)}}</td><td>{{sale.account_name}}</td><td>{{sale.product_name}}</td><td class="mono">{{sale.quantity}}</td><td class="mono">{{money(sale.total)}} د.ع</td><td><span class="badge" :class="{warn:sale.status==='Print Failed'}">{{saleStatuses[sale.status]}}</span></td><td><div class="actions"><button v-if="canReadReceipt(sale,vm.identity.value,vm.can)" type="button" class="btn small" :disabled="vm.state.busy || vm.receiptLoading.value" @click="openReceipt(sale)">الوصل</button><button v-if="canRequestReprint(sale,vm.identity.value,vm.can)" type="button" class="btn small" :disabled="vm.state.busy" @click="openReprint(sale)">إعادة طباعة</button><button v-if="sale.status==='Reserved' && ownsSale(sale,vm.identity.value) && vm.can('sell.create')" type="button" class="btn small" :disabled="vm.state.busy || !vm.can('data.pin')" @click="reservation(sale,'issue')">إصدار الحجز</button><button v-if="sale.status==='Reserved' && ownsSale(sale,vm.identity.value) && vm.can('sell.create')" type="button" class="btn small danger" :disabled="vm.state.busy" @click="reservation(sale,'cancel')">إلغاء الحجز</button><button type="button" class="btn small" @click="inspect(sale)">السجل</button></div></td></tr></tbody></table></TablePanel><section v-else class="pos-phone-operations"><article v-for="sale in rows" :key="sale.id" class="card"><div><strong>{{sale.product_name}}</strong><span class="badge neutral">{{saleStatuses[sale.status]}}</span></div><small>{{sale.provider_name}} · {{time(sale.issued_at || sale.created_at)}} · {{sale.id}}</small><p v-if="sale.failure_reason" class="caption">{{sale.failure_reason}}</p><div><b>{{money(sale.retail_total)}} د.ع</b><button v-if="canReadReceipt(sale,vm.identity.value,vm.can)" type="button" class="btn small" @click="openReceipt(sale)">عرض الوصل</button><button v-else type="button" class="btn small" @click="inspect(sale)">عرض التفاصيل</button></div></article></section></template>

      <template v-else><OwnReprintSales v-if="vm.seller.value && vm.can('sales.view') && vm.can('sell.reprint')" :vm="vm" :filters="filters" @changed="load(meta.current_page)"/><h3>طلبات إعادة الطباعة ومعالجة الفشل</h3><article v-for="request in rows" :key="request.id" class="ops-request"><div class="actions"><b>{{request.sale_id}}</b><span class="badge" :class="{warn:request.status==='pending'}">{{saleStatuses[request.status]}}</span><span>{{request.account_name}} · {{time(request.created_at)}}</span><span>لدى: {{request.recipient_name}}</span></div><p>{{request.reason}}</p><p v-if="request.failure_reason">سبب فشل الطباعة: {{request.failure_reason}}</p><details v-if="request.history?.length"><summary>مسار معالجة الطلب</summary><ol class="sale-print-history"><li v-for="(item,index) in request.history" :key="index">{{name(item.from)}} ← {{name(item.to)}} · {{time(item.at)}}<p>{{item.reason}}</p></li></ol></details><template v-if="canReviewRequest(request,vm.identity.value,vm.can)"><label>سبب الرفض أو التصعيد<textarea v-model="reasons[request.id]" maxlength="1000"></textarea></label><div class="actions"><button type="button" class="btn primary" :disabled="vm.state.busy" @click="review(request,'approve')">اعتماد إعادة الطباعة</button><button type="button" class="btn danger" :disabled="vm.state.busy || !String(reasons[request.id] || '').trim()" @click="review(request,'reject')">رفض</button><button v-if="vm.identity.value?.account.type!=='system'" type="button" class="btn" :disabled="vm.state.busy || !String(reasons[request.id] || '').trim()" @click="review(request,'escalate')">رفع الطلب للمستوى الأعلى</button></div></template><p v-else-if="request.status==='pending'" class="help">القرار للمسؤول المستلم الحالي من مستخدم مختلف عن مقدم الطلب.</p></article></template><p v-if="loading" class="read-loading empty" role="status">جارٍ تحميل العمليات…</p><p v-else-if="!rows.length" class="empty">{{tab==='sales'?'لا توجد عمليات مطابقة':'لا توجد طلبات معالجة مطابقة'}}</p><div class="sales-pagination"><button type="button" class="btn small" :disabled="loading || meta.current_page<=1" @click="load(meta.current_page-1)">السابق</button><span>الصفحة {{meta.current_page}} من {{meta.last_page}} · {{meta.total}} سجل</span><button type="button" class="btn small" :disabled="loading || meta.current_page>=meta.last_page" @click="load(meta.current_page+1)">التالي</button></div></div>

    <p v-if="vm.receiptLoading.value" class="read-loading notice" role="status">جارٍ تحميل الوصل…</p><ReceiptDialog :vm="vm" @changed="load(meta.current_page)" @reprint="openReprint"/><ReprintDialog v-if="reprint" :vm="vm" :sale="reprint" @close="reprint=null" @changed="load(meta.current_page)"/><SaleDetailsDialog v-if="detail" :vm="vm" :sale="detail" @close="detail=null" @receipt="openReceipt"/>

  </div>

</template>

