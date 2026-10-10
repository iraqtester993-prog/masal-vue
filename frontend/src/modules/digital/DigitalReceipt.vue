<script setup>
import { onBeforeUnmount,ref } from 'vue';
import { money,time,providerNames } from './digital-model.js';
const props=defineProps({receipt:{type:Object,required:true},vm:{type:Object,required:true}}),emit=defineEmits(['close']),printing=ref(false);
async function print(){if(printing.value)return;printing.value=true;try{const response=await props.vm.api.printAuthorization(props.receipt.order.id,props.vm.signal);if(!response.data.authorized)throw Error('الطباعة غير مسموحة.');document.body.classList.add('digital-printing');window.addEventListener('afterprint',clear,{once:true});window.print();}catch(error){props.vm.state.error=await props.vm.failure(error);}finally{printing.value=false;}}
function clear(){document.body.classList.remove('digital-printing');}
onBeforeUnmount(()=>{clear();window.removeEventListener('afterprint',clear);});
</script>
<template><section class="card digital-receipt"><div class="digital-receipt-content"><h3>إيصال خدمة إلكترونية</h3><p>{{providerNames[receipt.order.provider]}} · {{receipt.order.product_name}}</p><p>{{receipt.order.account_name}} · {{receipt.order.creator_name}}</p><p>{{time(receipt.order.created_at)}}</p><p v-if="receipt.order.mobile" class="mono">{{receipt.order.mobile}}</p><p v-if="receipt.code" class="digital-code mono">{{receipt.code}}</p><p v-if="receipt.serial" class="mono">{{receipt.serial}}</p><strong>{{money(receipt.order.retail)}} د.ع</strong><p class="mono">{{receipt.order.company_transaction_id}}</p></div><div class="actions digital-receipt-actions"><button class="btn primary" :disabled="printing" @click="print">طباعة الإيصال</button><button class="btn" @click="emit('close')">إغلاق</button></div></section></template>
