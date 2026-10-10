<script setup>
import {inject,onMounted,onBeforeUnmount,ref} from 'vue';
import FinancePagination from './FinancePagination.vue';
import {financeKey} from './finance-model.js';
import {useDigitalRuntime} from '../digital/digital-runtime.js';
import DigitalLog from '../digital/DigitalLog.vue';
import DigitalCategoryDetails from '../digital/DigitalCategoryDetails.vue';
import DigitalReceipt from '../digital/DigitalReceipt.vue';
import '../digital/digital.css';
const finance=inject(financeKey),vm=useDigitalRuntime(),details=ref(null),log=ref(null);
let reader,revision=0;
async function load(page=vm.state.connectionsMeta.current_page){
 reader?.abort();reader=new AbortController();const expected=++revision;vm.state.loading=true;vm.state.error='';
 try{const result=await vm.api.connections({provider:'topup',page,per_page:25},reader.signal);if(expected!==revision)return;vm.state.connections=result.data;vm.state.connectionsMeta=result.meta;vm.state.options.accounts=finance.options.accounts;vm.state.options.products=[...new Map(result.data.flatMap(row=>row.offers||[]).map(row=>[row.product_id,{id:row.product_id,name:row.name}])).values()];}
 catch(error){if(error.name!=='AbortError'&&expected===revision)vm.state.error=await vm.failure(error);}
 finally{if(expected===revision)vm.state.loading=false;}
}
onBeforeUnmount(()=>{revision++;reader?.abort();});
vm.load=load;
onMounted(load);
</script>
<template><section class="topup-wallet"><p class="notice">رصيد Topup مشترك للوكيل الرئيسي؛ تخصيص الفئات للنقاط لا ينشئ محافظ منفصلة.</p><p v-if="vm.state.error" class="notice warn" role="alert">{{vm.state.error}}</p><div class="card"><h3>روابط Topup والفئات الممنوحة</h3><div v-for="row in vm.state.connections" :key="row.id" class="rowline"><strong>{{row.account_name}}</strong><span>{{row.effective_offer_ids?.length ?? row.offers.length}} فئة</span><button class="btn small" @click="details=row">عرض تفاصيل الفئات</button></div><p v-if="!vm.state.loading&&!vm.state.connections.length" class="empty">لا يوجد ربط Topup متاح ضمن نطاقك.</p><FinancePagination :meta="vm.state.connectionsMeta" :busy="vm.state.loading" @page="load"/></div><DigitalCategoryDetails v-if="details" :connection="details" @close="details=null"/><DigitalLog ref="log" :vm="vm" :route-filters="{provider:'topup',account_id:finance.filters.account,from:finance.filters.from,to:finance.filters.to,q:finance.filters.reference}"/><DigitalReceipt v-if="vm.receipt.value" :vm="vm" :receipt="vm.receipt.value" @close="vm.clearReceipt"/></section></template>
