<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';

import {onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';
import {canRequestReprint,saleStatuses,time} from './sales-model.js';
const props=defineProps({vm:{type:Object,required:true},filters:{type:Object,default:()=>({})}}),emit=defineEmits(['changed']);
const status=ref('Printed'),reason=ref(''),rows=ref([]),loading=ref(false),meta=reactive({current_page:1,last_page:1,total:0}),keys=new Map();
let reader,revision=0;
async function load(page=1){reader?.abort();reader=new AbortController();const expected=++revision;loading.value=true;rows.value=[];
  try{const result=await props.vm.api.list('',{page,per_page:25,account_id:props.vm.ownId.value,status:status.value,q:props.filters.q,from:props.filters.from,to:props.filters.to,currency:props.filters.currency},reader.signal);if(expected!==revision)return;rows.value=result.data;Object.assign(meta,result.meta);}
  catch(error){if(error.name!=='AbortError'&&expected===revision)props.vm.state.error=await props.vm.failure(error);}
  finally{if(expected===revision)loading.value=false;}}
async function request(sale){if(!canRequestReprint(sale,props.vm.identity.value,props.vm.can)||!reason.value.trim())return;
  if(!keys.has(sale.id))keys.set(sale.id,props.vm.key());
  const result=await props.vm.execute({version:sale.version,reason:reason.value.trim()},keys.get(sale.id),(payload,signal)=>props.vm.api.action(sale.id,'reprint-request',payload,signal),'أرسل الطلب إلى الأعلى دون خصم جديد.');
  if(result){reason.value='';await load(meta.current_page);emit('changed',result.sale);}}
watch(()=>[status.value,props.vm.ownId.value,props.filters.q,props.filters.from,props.filters.to,props.filters.currency],()=>load());
onMounted(()=>load());
onBeforeUnmount(()=>{revision++;reader?.abort();rows.value=[];reason.value='';keys.clear();});
</script>
<template><section class="own-reprint-sales"><FilterBar class="toolbar"><h3>طلب إذن إعادة الطباعة</h3><select v-model="status" aria-label="حالة عملياتي لإعادة الطباعة"><option v-for="value in ['Printed','Reprinted','Print Failed']" :key="value" :value="value">{{saleStatuses[value]}}</option></select></FilterBar><label v-if="rows.length">سبب الطلب<input v-model="reason" maxlength="1000" :disabled="vm.state.busy" required></label><div v-for="sale in rows" :key="sale.id" class="rowline"><span>{{sale.account_name}} · {{sale.id}} · {{sale.product_name}}<small> {{time(sale.issued_at)}} · {{sale.reprints}} إعادة طباعة</small></span><button type="button" class="btn" :disabled="vm.state.busy || !reason.trim() || !canRequestReprint(sale,vm.identity.value,vm.can)" @click="request(sale)">طلب إذن إعادة الطباعة</button></div><p v-if="loading" class="read-loading empty" role="status">جارٍ تحميل عملياتك…</p><p v-else-if="!rows.length" class="empty">لا توجد عمليات لك بهذه الحالة.</p><div class="sales-pagination"><button type="button" class="btn small" :disabled="loading || meta.current_page<=1" @click="load(meta.current_page-1)">السابق</button><span>الصفحة {{meta.current_page}} من {{meta.last_page}} · {{meta.total}} عملية</span><button type="button" class="btn small" :disabled="loading || meta.current_page>=meta.last_page" @click="load(meta.current_page+1)">التالي</button></div></section></template>
