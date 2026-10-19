<script setup>
import { onBeforeUnmount,ref } from 'vue';
import CatalogModal from '../catalog/CatalogModal.vue';
import '../catalog/catalog.css';
import { money,time,providerNames } from './digital-model.js';
import { requiresNativePrinter as hasNativePrinter,printNativeReceipt } from '../../shared/native-print.js';
import {usePrinterRoute} from '../../shared/printer-route.js';
const {printRoute}=usePrinterRoute();
const props=defineProps({receipt:{type:Object,required:true},vm:{type:Object,required:true}}),emit=defineEmits(['close']),printing=ref(false);
async function print(){if(printing.value)return;printing.value=true;try{const response=await props.vm.api.printAuthorization(props.receipt.order.id,props.vm.signal);if(!response.data.authorized)throw Error('الطباعة غير مسموحة.');if(hasNativePrinter()){props.vm.state.notice=await printNativeReceipt(document.querySelector('.digital-receipt-content'),'digital-'+props.receipt.order.id);return;}document.body.classList.add('digital-printing');window.addEventListener('afterprint',clear,{once:true});window.print();}catch(error){props.vm.state.error=await props.vm.failure(error);}finally{printing.value=false;}}
function clear(){document.body.classList.remove('digital-printing');}
function close(){if(!printing.value)emit('close');}
onBeforeUnmount(()=>{clear();window.removeEventListener('afterprint',clear);});
</script>
<template><CatalogModal title="وصل العملية" section="الخدمات الإلكترونية" content-class="digital-receipt-dialog pos-receipt-dialog" body-class="receipt-dialog-body" :busy="printing" @close="close"><section class="card digital-receipt"><div class="digital-receipt-content"><h3>إيصال خدمة إلكترونية</h3><p>{{providerNames[receipt.order.provider]}} · {{receipt.order.product_name}}</p><p>{{receipt.order.account_name}} · {{receipt.order.creator_name}}</p><p>{{time(receipt.order.created_at)}}</p><p v-if="receipt.order.mobile" class="mono">{{receipt.order.mobile}}</p><p v-if="receipt.code" class="digital-code mono">{{receipt.code}}</p><p v-if="receipt.serial" class="mono">{{receipt.serial}}</p><strong>{{money(receipt.order.retail)}} د.ع</strong><p class="mono">{{receipt.order.company_transaction_id}}</p></div><p v-if="vm.state.error" class="notice warn no-print" role="alert">{{vm.state.error}}</p><div class="actions digital-receipt-actions no-print"><button v-if="vm.can('digital.print')" class="btn primary" :disabled="printing" @click="print">{{printRoute==='direct'?'طباعة مباشرة':'طباعة المتصفح'}}</button><button class="btn" :disabled="printing" @click="close">إغلاق</button></div></section></CatalogModal></template>
